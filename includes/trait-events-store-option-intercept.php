<?php
/**
 * Cron option manipulation.
 *
 * Prior to 5.1, this was the primary way cron events
 * were captured; 5.1 introduced filters that obviate
 * the option.
 *
 * @package a8c_Cron_Control
 */

namespace Automattic\WP\Cron_Control;

/**
 * Trait Option_Intercept
 */
trait Events_Store_Option_Intercept {
	/**
	 * Register hooks to invoke option interception.
	 */
	protected function register_option_intercept_hooks() {
		add_filter( 'pre_option_cron', array( $this, 'get_option' ) );
		add_filter( 'pre_update_option_cron', array( $this, 'update_option' ), 10, 2 );
	}

	/**
	 * Override cron option requests with data from custom table
	 */
	public function get_option() {
		// If this thread has already generated the cron array,
		// use the copy from local memory. Don't fetch this list
		// remotely multiple times per request (even from the
		// object cache).
		static $cron_array;
		if ( $cron_array && true === $this->is_option_cache_valid ) {
			return $cron_array;
		}

		$this->is_option_cache_valid = true;

		// Use cached value when available.
		$cached_option = $this->get_cached_option();

		if ( false !== $cached_option ) {
			return $cached_option;
		}

		// Start building a new cron option.
		$cron_array = array(
			'version' => 2, // Core versions the cron array; without this, events will continually requeue.
		);

		// Get events to re-render as the cron option.
		$page     = 1;
		$quantity = 5000;

		do {
			$jobs = $this->get_jobs(
				array(
					'status'   => self::STATUS_PENDING,
					'quantity' => $quantity,
					'page'     => $page++,
				)
			);

			// Nothing more to add.
			if ( empty( $jobs ) ) {
				break;
			}

			// Loop through results and built output Core expects.
			foreach ( $jobs as $job ) {
				// Alias event timestamp.
				$timestamp = $job->timestamp;

				// If timestamp is invalid, event is removed to let its source fix it.
				if ( $timestamp <= 0 ) {
					$this->mark_job_record_completed( $job->ID );
					continue;
				}

				// Basic arguments to add a job to the array format Core expects.
				$action   = $job->action;
				$instance = $job->instance;

				// Populate remaining job data.
				$cron_array[ $timestamp ][ $action ][ $instance ] = array(
					'schedule' => $job->schedule,
					'args'     => $job->args,
					'interval' => 0,
				);

				if ( isset( $job->interval ) ) {
					$cron_array[ $timestamp ][ $action ][ $instance ]['interval'] = $job->interval;
				}
			}
		} while ( count( $jobs ) >= $quantity );

		// Re-sort the array just as Core does when events are scheduled.
		// Ensures events are sorted chronologically.
		uksort( $cron_array, 'strnatcasecmp' );

		// Cache the results.
		$this->cache_option( $cron_array );

		return $cron_array;
	}

	/**
	 * Handle requests to update the cron option
	 *
	 * By returning $old_value, `cron` option won't be updated
	 *
	 * @param array $new_value New option value.
	 * @param array $old_value Old option value.
	 * @return array
	 */
	public function update_option( $new_value, $old_value ) {
		// Find changes to record.
		$new_events     = $this->find_cron_array_differences( $new_value, $old_value );
		$deleted_events = $this->find_cron_array_differences( $old_value, $new_value );

		// Add/update new events.
		foreach ( $new_events as $new_event ) {
			$job_id = $this->get_job_id( $new_event['timestamp'], $new_event['action'], $new_event['instance'] );

			if ( 0 === $job_id ) {
				$job_id = null;
			}

			$this->create_or_update_job( $new_event['timestamp'], $new_event['action'], $new_event['args'], $job_id, false );
		}

		// Mark deleted entries for removal.
		foreach ( $deleted_events as $deleted_event ) {
			$this->mark_job_completed( $deleted_event['timestamp'], $deleted_event['action'], $deleted_event['instance'], false );
		}

		$this->flush_internal_caches();

		return $old_value;
	}

	/**
	 * Compare two arrays and return collapsed representation of the items present in one but not the other
	 *
	 * @param array $changed   Array to identify additional items from.
	 * @param array $reference Array to compare against.
	 * @return array
	 */
	private function find_cron_array_differences( $changed, $reference ) {
		$differences = array();

		$changed = collapse_events_array( $changed );

		foreach ( $changed as $event ) {
			$event = (object) $event;

			if ( ! isset( $reference[ $event->timestamp ][ $event->action ][ $event->instance ] ) ) {
				$differences[] = array(
					'timestamp' => $event->timestamp,
					'action'    => $event->action,
					'instance'  => $event->instance,
					'args'      => $event->args,
				);
			}
		}

		return $differences;
	}

	/**
	 * Retrieve cron option from cache
	 *
	 * @return array|false
	 */
	private function get_cached_option() {
		$cache_details = wp_cache_get( self::CACHE_KEY, null, true );

		if ( ! is_array( $cache_details ) ) {
			return false;
		}

		// Single bucket.
		if ( isset( $cache_details['version'] ) ) {
			return $cache_details;
		}

		// Invalid data!
		if ( ! isset( $cache_details['incrementer'] ) ) {
			return false;
		}

		$option_flat = array( array() );

		// Restore option from cached pieces.
		for ( $i = 1; $i <= $cache_details['buckets']; $i++ ) {
			$cache_key    = $this->get_cache_key_for_slice( $cache_details['incrementer'], $i );
			$cached_slice = wp_cache_get( $cache_key, null, true );

			// Bail if a chunk is missing.
			if ( ! is_array( $cached_slice ) ) {
				return false;
			}

			$option_flat[] = $cached_slice;
		}

		$option_flat = array_merge( ...$option_flat );

		// Something's missing, likely due to cache eviction.
		if ( empty( $option_flat ) || count( $option_flat ) !== $cache_details['event_count'] ) {
			return false;
		}

		return inflate_collapsed_events_array( $option_flat );
	}

	/**
	 * Cache cron option, accommodating large versions by splitting into chunks
	 *
	 * @param array $option Cron option to cache.
	 * @return bool
	 */
	private function cache_option( $option ) {
		// Determine storage requirements.
		$option_flat        = collapse_events_array( $option );
		$option_flat_string = maybe_serialize( $option_flat );
		$option_size        = strlen( $option_flat_string );
		$buckets            = (int) ceil( $option_size / CACHE_BUCKET_SIZE );

		// Store in single cache key.
		if ( 1 === $buckets ) {
			return wp_cache_set( self::CACHE_KEY, $option, null, 1 * \HOUR_IN_SECONDS );
		}

		// Too large to cache?
		if ( $buckets > MAX_CACHE_BUCKETS ) {
			do_action( 'a8c_cron_control_uncacheable_cron_option', $option_size, $buckets, count( $option_flat ) );

			$this->flush_internal_caches();
			return false;
		}

		$incrementer  = md5( $option_flat_string . time() );
		$event_count  = count( $option_flat );
		$segment_size = (int) ceil( $event_count / $buckets );

		for ( $i = 1; $i <= $buckets; $i++ ) {
			$offset    = ( $i - 1 ) * $segment_size;
			$slice     = array_slice( $option_flat, $offset, $segment_size );
			$cache_key = $this->get_cache_key_for_slice( $incrementer, $i );

			wp_cache_set( $cache_key, $slice, null, 1 * \HOUR_IN_SECONDS );
		}

		$option = array(
			'incrementer' => $incrementer,
			'buckets'     => $buckets,
			'event_count' => count( $option_flat ),
		);

		return wp_cache_set( self::CACHE_KEY, $option, null, 1 * \HOUR_IN_SECONDS );
	}

	/**
	 * Build cache key for a given portion of a large option
	 *
	 * @param string $incrementor Current cache incrementor.
	 * @param int    $slice Slice ID.
	 * @return string
	 */
	private function get_cache_key_for_slice( $incrementor, $slice ) {
		return md5( self::CACHE_KEY . $incrementor . $slice );
	}

	/**
	 * Delete the cached representation of the cron option
	 */
	public function flush_internal_caches() {
		$this->is_option_cache_valid = false;
		return wp_cache_delete( self::CACHE_KEY );
	}
}
