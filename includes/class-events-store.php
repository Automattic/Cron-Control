<?php

namespace Automattic\WP\Cron_Control;

class Events_Store extends Singleton {
	/**
	 * PLUGIN SETUP
	 */

	/**
	 * Class properties
	 */
	const TABLE_SUFFIX = 'a8c_cron_ctrl_jobs';

	const DB_VERSION        = 1;
	const DB_VERSION_OPTION = 'a8c_cron_control_db_version';

	const STATUS_PENDING   = 'pending';
	const STATUS_RUNNING   = 'running';
	const STATUS_COMPLETED = 'complete';

	const CACHE_KEY = 'a8c_cron_ctrl_option';

	private $option_before_unscheduling = null;

	private $job_creation_suspended = false;

	/**
	 * Register hooks
	 */
	protected function class_init() {
		// Check that the table exists and is the correct version
		$this->prepare_tables();

		// Option interception
		add_filter( 'pre_option_cron', array( $this, 'get_option' ) );
		add_filter( 'pre_update_option_cron', array( $this, 'update_option' ), 10, 2 );
	}

	/**
	 * Build appropriate table name for this install
	 */
	public function get_table_name() {
		global $wpdb;

		return $wpdb->base_prefix . self::TABLE_SUFFIX;
	}

	/**
	 * Create the plugin's DB table when necessary
	 */
	protected function prepare_tables() {
		// Nothing to do
		if ( (int) get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION ) {
			return;
		}

		// Use Core's method of creating/updating tables
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . '/wp-admin/includes/upgrade.php';
		}

		global $wpdb;

		// Define schema and create the table
		$schema = "CREATE TABLE IF NOT EXISTS `{$this->get_table_name()}` (
			`ID` bigint(20) unsigned NOT NULL AUTO_INCREMENT,

			`timestamp` bigint(20) unsigned NOT NULL,
			`action` varchar(255) NOT NULL,
			`instance` varchar(32) NOT NULL,

			`args` longtext NOT NULL,
			`schedule` varchar(255) DEFAULT NULL,
			`interval` int unsigned DEFAULT NULL,
			`status` varchar(255) NOT NULL DEFAULT 'pending',

			`created` datetime NOT NULL,
			`last_modified` datetime NOT NULL,

			PRIMARY KEY (`id`),
			UNIQUE KEY `ts_action_instance` (`timestamp`, `action`, `instance`),
			KEY `status` (`status`)
		) ENGINE=InnoDB;\n";

		dbDelta( $schema, true );

		// Confirm that the table was created, and set the option to prevent further updates
		$table_count = count( $wpdb->get_col( "SHOW TABLES LIKE '{$this->get_table_name()}'" ) );

		if ( 1 === $table_count ) {
			update_option( self::DB_VERSION_OPTION, self::DB_VERSION, true );
		} else {
			delete_option( self::DB_VERSION_OPTION );
		}
	}

	/**
	 * PLUGIN FUNCTIONALITY
	 */

	/**
	 * Override cron option requests with data from CPT
	 */
	public function get_option() {
		// Use cached value for reads, except when we're unscheduling and state is important
		if ( ! $this->is_unscheduling() ) {
			$cached_option = wp_cache_get( self::CACHE_KEY, null, true );

			if ( false !== $cached_option ) {
				return $cached_option;
			}
		}

		// Start building a new cron option
		$cron_array = array(
			'version' => 2, // Core versions the cron array; without this, events will continually requeue
		);

		// Get events to re-render as the cron option
		$page = 1;

		do {
			$jobs_posts = $this->get_jobs( array(
				'status'   => self::STATUS_PENDING,
				'quantity' => 100,
				'page'     => 1,
			) );

			// Nothing more to add
			if ( empty( $jobs_posts ) ) {
				break;
			}

			$page++;

			// Something's probably wrong if a site has more than 1,500 pending cron actions
			if ( $page > 15 ) {
				do_action( 'a8c_cron_control_stopped_runaway_cron_option_rebuild' );
				break;
			}

			// Loop through results and built output Core expects
			if ( ! empty( $jobs_posts ) ) {
				foreach ( $jobs_posts as $jobs_post ) {
					// Alias event timestamp
					$timestamp = (int) $jobs_post->timestamp;

					// If timestamp is invalid, event is removed to let its source fix it
					if ( $timestamp <= 0 ) {
						$this->mark_job_record_completed( $jobs_post->ID );
						continue;
					}

					// Basic arguments to add a job to the array format Core expects
					$action   = $jobs_post->action;
					$instance = $jobs_post->instance;

					// Populate remaining job data
					$cron_array[ $timestamp ][ $action ][ $instance ] = array(
						'schedule' => $jobs_post->schedule,
						'args'     => maybe_unserialize( $jobs_post->args ),
						'interval' => 0,
					);

					if ( isset( $jobs_post->interval ) ) {
						$cron_array[ $timestamp ][ $action ][ $instance ]['interval'] = (int) $jobs_post->interval;
					}

				}
			}
		} while( true );

		// Re-sort the array just as Core does when events are scheduled
		// Ensures events are sorted chronologically
		uksort( $cron_array, 'strnatcasecmp' );

		// If we're unscheduling an event, hold onto the previous value so we can identify what's unscheduled
		if ( $this->is_unscheduling() ) {
			$this->option_before_unscheduling = $cron_array;
		} else {
			$this->option_before_unscheduling = null;
		}

		// Cache the results, bearing in mind that they won't be used during unscheduling events
		wp_cache_set( self::CACHE_KEY, $cron_array, null, 1 * \HOUR_IN_SECONDS );

		return $cron_array;
	}

	/**
	 * Handle requests to update the cron option
	 *
	 * By returning $old_value, `cron` option won't be updated
	 */
	public function update_option( $new_value, $old_value ) {
		if ( $this->is_unscheduling() ) {
			$this->unschedule_job( $new_value, $this->option_before_unscheduling );
		} else {
			$this->convert_option( $new_value );
		}

		return $old_value;
	}

	/**
	 * Delete jobs that are unscheduled using `wp_unschedule_event()`
	 */
	private function unschedule_job( $new_value, $old_value ) {
		$jobs = $this->find_unscheduled_jobs( $new_value, $old_value );

		foreach ( $jobs as $job ) {
			$this->mark_job_completed( $job['timestamp'], $job['action'], $job['instance'] );
		}
	}

	/**
	 * Save cron events in CPT
	 */
	private function convert_option( $new_value ) {
		if ( is_array( $new_value ) && ! empty( $new_value ) ) {
			$events = collapse_events_array( $new_value );

			foreach ( $events as $event ) {
				$job_exists = $this->job_exists( $event['timestamp'], $event['action'], $event['instance'] );

				if ( ! $job_exists ) {
					$this->create_or_update_job( $event['timestamp'], $event['action'], $event['args'] );
				}
			}
		}
	}

	/**
	 * PLUGIN UTILITY METHODS
	 */

	/**
	 * Retrieve list of jobs, respecting whether or not the CPT is registered
	 *
	 * Uses a direct query to avoid stale caches that result in duplicate events
	 */
	private function get_jobs( $args ) {
		global $wpdb;

		if ( ! isset( $args['quantity'] ) || ! is_numeric( $args['quantity'] ) ) {
			$args['quantity'] = 100;
		}

		if ( isset( $args['page'] ) ) {
			$page  = max( 0, $args['page'] - 1 );
			$offset = $page * $args['quantity'];
		} else {
			$offset = 0;
		}

		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->get_table_name()} WHERE status = %s ORDER BY timestamp LIMIT %d,%d;", $args['status'], $offset, $args['quantity'] ), 'OBJECT' );
	}

	/**
	 * Check if a job post exists
	 *
	 * Uses a direct query to avoid stale caches that result in duplicate events
	 */
	public function job_exists( $timestamp, $action, $instance, $return_id = false ) {
		global $wpdb;

		$exists = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$this->get_table_name()} WHERE timestamp = %d AND action = %s AND instance = %s LIMIT 1;", $timestamp, $action, $instance ) );

		if ( $return_id ) {
			return empty( $exists ) ? 0 : (int) array_shift( $exists );
		} else {
			return ! empty( $exists );
		}
	}

	/**
	 * Create a post object for a given event
	 *
	 * Can't call `wp_insert_post()` because `wp_unique_post_slug()` breaks the plugin's expectations
	 * Also doesn't call `wp_insert_post()` because this function is needed before post types and capabilities are ready.
	 */
	public function create_or_update_job( $timestamp, $action, $args, $update_id = null ) {
		// Don't create new jobs when manipulating jobs via the plugin's CLI commands
		if ( $this->job_creation_suspended ) {
			return;
		}

		global $wpdb;

		$job_post = array(
			'timestamp'     => $timestamp,
			'action'        => $action,
			'instance'      => md5( serialize( $args['args'] ) ),
			'args'          => maybe_serialize( $args['args'] ),
			'last_modified' => current_time( 'mysql', true ),
		);

		if ( isset( $args['schedule'] ) && ! empty( $args['schedule'] ) ) {
			$job_post['schedule'] = $args['schedule'];
		}

		if ( isset( $args['interval'] ) && ! empty( $args['interval'] ) && is_numeric( $args['interval'] ) ) {
			$job_post['interval'] = (int) $args['interval'];
		}

		// Create the post, or update an existing entry to run again in the future
		if ( is_int( $update_id ) && $update_id > 0 ) {
			$wpdb->update( $this->get_table_name(), $job_post, array( 'ID' => $update_id, ) );
		} else {
			$job_post['created'] = date( 'Y-m-d H:i:s', $timestamp );

			$wpdb->insert( $this->get_table_name(), $job_post );
		}

		// Delete internal cache
		wp_cache_delete( self::CACHE_KEY );
	}

	/**
	 * Mark an event's CPT entry as completed
	 *
	 * Completed entries will be cleaned up by an internal job
	 *
	 * @param $timestamp  int     Unix timestamp
	 * @param $action     string  name of action used when the event is registered (unhashed)
	 * @param $instance   string  md5 hash of the event's arguments array, which Core uses to index the `cron` option
	 *
	 * @return bool
	 */
	public function mark_job_completed( $timestamp, $action, $instance ) {
		$job_id = $this->job_exists( $timestamp, $action, $instance, true );

		if ( ! $job_id ) {
			return false;
		}

		return $this->mark_job_record_completed( $job_id );
	}

	/**
	 * Set a job post to the "completed" status
	 */
	private function mark_job_record_completed( $job_id, $flush_cache = true ) {
		global $wpdb;

		$wpdb->update( $this->get_table_name(), array( 'status' => self::STATUS_COMPLETED, ), array( 'ID' => $job_id, ) );

		// Delete internal cache
		// Should only be skipped when deleting duplicates, as they are excluded from the cache
		if ( $flush_cache ) {
			wp_cache_delete( self::CACHE_KEY );
		}

		return true;
	}

	/**
	 * Determine if current request is a call to `wp_unschedule_event()`
	 */
	private function is_unscheduling() {
		return false !== array_search( 'wp_unschedule_event', wp_debug_backtrace_summary( __CLASS__, null, false ) );
	}

	/**
	 * Identify jobs unscheduled using `wp_unschedule_event()` by comparing current value with previous
	 */
	private function find_unscheduled_jobs( $new, $old ) {
		$differences = array();

		$old = collapse_events_array( $old );

		foreach ( $old as $event ) {
			$timestamp = $event['timestamp'];
			$action    = $event['action'];
			$instance  = $event['instance'];

			if ( ! isset( $new[ $timestamp ][ $action ][ $instance ] ) ) {
				$differences[] = array(
					'timestamp' => $timestamp,
					'action'    => $action,
					'instance'  => $instance,
				);
			}
		}

		return $differences;
	}

	/**
	 * Prevent CPT from creating new entries
	 *
	 * Should be used sparingly, and followed by a call to resume_event_creation(), during bulk operations
	 */
	public function suspend_event_creation() {
		$this->job_creation_suspended = true;
	}

	/**
	 * Stop discarding events, once again storing them in the CPT
	 */
	public function resume_event_creation() {
		$this->job_creation_suspended = false;
	}

	/**
	 * Remove entries for non-recurring events that have been run
	 */
	public function purge_completed_events() {
		global $wpdb;

		$wpdb->delete( $this->get_table_name(), array( 'status' => self::STATUS_COMPLETED, ) );
	}
}

Events_Store::instance();
