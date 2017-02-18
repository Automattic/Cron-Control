<?php

namespace Automattic\WP\Cron_Control;

class Events_Store extends Singleton {
	/**
	 * PLUGIN SETUP
	 */

	/**
	 * Class properties
	 */
	const TABLE_SUFFIX = 'a8c_cron_control_jobs';

	const DB_VERSION        = 1;
	const DB_VERSION_OPTION = 'a8c_cron_control_db_version';
	const TABLE_CREATE_LOCK = 'a8c_cron_control_creating_table';

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
	 * Build array of valid event statuses
	 */
	public function get_allowed_statuses() {
		return array(
			self::STATUS_PENDING,
			self::STATUS_RUNNING,
			self::STATUS_COMPLETED,
		);
	}

	/**
	 * Create the plugin's DB table when necessary
	 */
	protected function prepare_tables() {
		// Nothing to do
		if ( (int) get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION ) {
			return;
		}

		// Limit chance of race conditions when creating table
		if ( false === wp_cache_add( self::TABLE_CREATE_LOCK, 1, null, 1 * \MINUTE_IN_SECONDS ) ) {
			return;
		}

		// Use Core's method of creating/updating tables
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . '/wp-admin/includes/upgrade.php';
		}

		global $wpdb;

		// Define schema and create the table
		$schema = "CREATE TABLE `{$this->get_table_name()}` (
			`ID` bigint(20) unsigned NOT NULL AUTO_INCREMENT,

			`timestamp` bigint(20) unsigned NOT NULL,
			`action` varchar(255) NOT NULL,
			`action_hashed` varchar(32) NOT NULL,
			`instance` varchar(32) NOT NULL,

			`args` longtext NOT NULL,
			`schedule` varchar(255) DEFAULT NULL,
			`interval` int unsigned DEFAULT 0,
			`status` varchar(32) NOT NULL DEFAULT 'pending',

			`created` datetime NOT NULL,
			`last_modified` datetime NOT NULL,

			PRIMARY KEY (`ID`),
			UNIQUE KEY `ts_action_instance` (`timestamp`, `action`, `instance`)
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
	 * Override cron option requests with data from custom table
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
					$timestamp = $jobs_post->timestamp;

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
						'args'     => $jobs_post->args,
						'interval' => 0,
					);

					if ( isset( $jobs_post->interval ) ) {
						$cron_array[ $timestamp ][ $action ][ $instance ]['interval'] = $jobs_post->interval;
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
		if ( $this->is_scheduling() ) {
			$this->convert_option( $new_value );
		} elseif ( $this->is_unscheduling() ) {
			$this->unschedule_job( $new_value, $this->option_before_unscheduling );
		} else {
			$this->force_update( $new_value );
		}

		return $old_value;
	}

	/**
	 * Overwrite all existing data with a new value
	 */
	private function force_update( $new_value ) {
		global $wpdb;

		// Remove all existing data
		$this->suspend_event_creation();

		$wpdb->update( $this->get_table_name(), array( 'status' => self::STATUS_COMPLETED, ), array( 'status' => self::STATUS_PENDING, ) );
		$wpdb->update( $this->get_table_name(), array( 'status' => self::STATUS_COMPLETED, ), array( 'status' => self::STATUS_RUNNING, ) );
		$this->option_before_unscheduling = null;

		$this->flush_internal_caches();

		$this->resume_event_creation();

		// Write new events to store
		$this->convert_option( $new_value );
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
	 * Save cron events in custom table
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
	 * Retrieve jobs given a set of parameters
	 *
	 * @param array $args
	 * @return array|false
	 */
	public function get_jobs( $args ) {
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

		$jobs = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->get_table_name()} WHERE status = %s ORDER BY timestamp ASC LIMIT %d,%d;", $args['status'], $offset, $args['quantity'] ), 'OBJECT' );

		if ( is_array( $jobs ) ) {
			$jobs = array_map( array( $this, 'format_job' ), $jobs );
		} else {
			$jobs = false;
		}

		return $jobs;
	}

	/**
	 * Retrieve a single event by ID, or by a combination of its timestamp, instance identifier, and either action or the action's hashed representation
	 *
	 * @param  array $attrs Array of event attributes to query by
	 * @return object|false
	 */
	public function get_job( $attrs ) {
		global $wpdb;

		// Validate basic inputs
		if ( ! is_array( $attrs ) || empty( $attrs ) ) {
			return false;
		}

		// Validate requested status
		$allowed_status = $this->get_allowed_statuses();
		$allowed_status[] = 'any';

		if ( ! isset( $attrs['status'] ) || ! in_array( $attrs['status'], $allowed_status, true ) ) {
			$attrs['status'] = self::STATUS_PENDING;
		}

		// Validate attributes provided to query for a post
		if ( isset( $attrs['ID'] ) ) {
			$query = $wpdb->prepare( "SELECT * FROM {$this->get_table_name()} WHERE ID = %d", $attrs['ID'] );
		} else {
			// Need a timestamp, an instance, and either an action or its hashed representation
			if ( ! isset( $attrs['timestamp'] ) || ! isset( $attrs['instance'] ) ) {
				return false;
			} elseif ( ! isset( $attrs['action'] ) && ! isset( $attrs['action_hashed'] ) ) {
				return false;
			}

			// Build query
			if ( isset( $attrs['action'] ) ) {
				$action_column = 'action';
				$action_value  = $attrs['action'];
			} else {
				$action_column = 'action_hashed';
				$action_value  = $attrs['action_hashed'];
			}

			$query = $wpdb->prepare( "SELECT * FROM {$this->get_table_name()} WHERE timestamp = %d AND {$action_column} = %s AND instance = %s", $attrs['timestamp'], $action_value, $attrs['instance'] );
		}

		// Final query preparations
		if ( 'any' !== $attrs['status'] ) {
			$query .= " AND status = '{$attrs['status']}'";
		}

		$query .= ' LIMIT 1';

		// Query and format results
		$job = $wpdb->get_row( $query );

		if ( is_object( $job ) && ! is_wp_error( $job ) ) {
			$job = $this->format_job( $job );
		} else {
			$job = false;
		}

		return $job;
	}

	/**
	 * Standardize formatting and expand serialized data
	 *
	 * @param  object $job Job row from DB, in object form
	 * @return object
	 */
	private function format_job( $job ) {
		if ( ! is_object( $job ) || is_wp_error( $job ) ) {
			return $job;
		}

		$job->ID        = (int) $job->ID;
		$job->timestamp = (int) $job->timestamp;
		$job->interval  = (int) $job->interval;
		$job->args      = maybe_unserialize( $job->args );

		if ( empty( $job->schedule ) ) {
			$job->schedule = false;
		}

		return $job;
	}

	/**
	 * Check if a job post exists
	 *
	 * Uses a direct query to avoid stale caches that result in duplicate events
	 */
	public function job_exists( $timestamp, $action, $instance, $return_id = false ) {
		global $wpdb;

		$exists = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$this->get_table_name()} WHERE timestamp = %d AND action = %s AND instance = %s AND status = %s LIMIT 1;", $timestamp, $action, $instance, self::STATUS_PENDING ) );

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
			'action_hashed' => md5( $action ),
			'instance'      => md5( maybe_serialize( $args['args'] ) ),
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
			$job_post['created'] = current_time( 'mysql', true );

			$wpdb->insert( $this->get_table_name(), $job_post );
		}

		$this->flush_internal_caches();
	}

	/**
	 * Mark an event's entry as completed
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
	public function mark_job_record_completed( $job_id, $flush_cache = true ) {
		global $wpdb;

		$success = $wpdb->update( $this->get_table_name(), array( 'status' => self::STATUS_COMPLETED, ), array( 'ID' => $job_id, ) );

		// Delete internal cache
		// Should only be skipped when deleting duplicates, as they are excluded from the cache
		if ( $flush_cache ) {
			$this->flush_internal_caches();
		}

		return (bool) $success;
	}

	/**
	 * Determine if current request is a call to one of Core's functions for scheduling events
	 *
	 * @return bool
	 */
	private function is_scheduling() {
		$trace = wp_debug_backtrace_summary( __CLASS__, null, false );

		$scheduling_callbacks = array(
			'wp_schedule_single_event',
			'wp_schedule_event',
		);

		$is_scheduling = false;

		foreach ( $scheduling_callbacks as $cb ) {
			if ( false !== array_search( $cb, $trace ) ) {
				$is_scheduling = true;
				break;
			}
		}

		return $is_scheduling;
	}

	/**
	 * Determine if current request is a call to `wp_unschedule_event()`
	 *
	 * @return bool
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
	 * Delete the cached representation of the cron option
	 */
	public function flush_internal_caches() {
		return wp_cache_delete( self::CACHE_KEY );
	}

	/**
	 * Prevent event store from creating new entries
	 *
	 * Should be used sparingly, and followed by a call to resume_event_creation(), during bulk operations
	 */
	public function suspend_event_creation() {
		$this->job_creation_suspended = true;
	}

	/**
	 * Stop discarding events, once again storing them in the table
	 *
	 * First clears any completed events to free unique timestamp-action-instance key
	 */
	public function resume_event_creation() {
		$this->purge_completed_events();

		$this->job_creation_suspended = false;
	}

	/**
	 * Remove entries for non-recurring events that have been run
	 */
	public function purge_completed_events() {
		global $wpdb;

		$wpdb->delete( $this->get_table_name(), array( 'status' => self::STATUS_COMPLETED, ) );
	}

	/**
	 * Count number of events with a given status
	 *
	 * @param string $status
	 * @return int|false
	 */
	public function count_events_by_status( $status ) {
		global $wpdb;

		if ( ! in_array( $status, $this->get_allowed_statuses(), true ) ) {
			return false;
		}

		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(action) FROM {$this->get_table_name()} WHERE status = %s", $status ) );
	}
}

Events_Store::instance();
