<?php

namespace WP_Cron_Control_Revisited;

class Main extends Singleton {
	/**
	 * PLUGIN SETUP
	 */

	/**
	 * Class properties
	 */
	const LOCK = 'run-events';

	/**
	 * Register hooks
	 */
	protected function class_init() {
		// For now, leave WP-CLI alone
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return;
		}

		// Bail when plugin conditions aren't met
		if ( ! defined( '\WP_CRON_CONTROL_SECRET' ) ) {
			add_action( 'admin_notices', array( $this, 'admin_notice' ) );
			return;
		}

		// Prime lock cache if not present
		Lock::prime_lock( self::LOCK );

		// Load dependencies
		require __DIR__ . '/class-internal-events.php';
		require __DIR__ . '/class-rest-api.php';
		require __DIR__ . '/functions.php';

		// Block normal cron execution
		$this->set_constants();

		$block_action = did_action( 'muplugins_loaded' ) ? 'plugins_loaded' : 'muplugins_loaded';
		add_action( $block_action, array( $this, 'block_direct_cron' ) );
		remove_action( 'init', 'wp_cron' );

		add_filter( 'cron_request', array( $this, 'block_spawn_cron' ) );
	}

	/**
	 * Define constants that block Core's cron
	 *
	 * If a constant is already defined and isn't what we expect, log it
	 */
	private function set_constants() {
		$constants = array(
			'DISABLE_WP_CRON'   => true,
			'ALTERNATE_WP_CRON' => false,
		);

		foreach ( $constants as $constant => $expected_value ) {
			if ( defined( $constant ) ) {
				if ( constant( $constant ) !== $expected_value ) {
					error_log( sprintf( __( '%s: %s set to unexpected value; must be corrected for proper behaviour.', 'wp-cron-control-revisited' ), 'WP-Cron Control Revisited', $constant ) );
				}
			} else {
				define( $constant, $expected_value );
			}
		}
	}

	/**
	 * Block direct cron execution as early as possible
	 */
	public function block_direct_cron() {
		if ( false !== strpos( $_SERVER['REQUEST_URI'], '/wp-cron.php' ) ) {
			status_header( 403 );
			wp_send_json_error( new \WP_Error( 'forbidden', sprintf( __( 'Normal cron execution is blocked when the %s plugin is active.', 'wp-cron-control-revisited' ), 'WP-Cron Control Revisited' ) ) );
		}
	}

	/**
	 * Block the `spawn_cron()` function
	 */
	public function block_spawn_cron( $spawn_cron_args ) {
		delete_transient( 'doing_cron' );

		$spawn_cron_args['url']  = '';
		$spawn_cron_args['key']  = '';
		$spawn_cron_args['args'] = array();

		return $spawn_cron_args;
	}

	/**
	 * Display an error if the plugin's conditions aren't met
	 */
	public function admin_notice() {
		?>
		<div class="notice notice-error">
			<p><?php printf( __( '<strong>%1$s</strong>: To use this plugin, define the constant %2$s.', 'wp-cron-control-revisited' ), 'WP-Cron Control Revisited', '<code>WP_CRON_CONTROL_SECRET</code>' ); ?></p>
		</div>
		<?php
	}

	/**
	 * PLUGIN FUNCTIONALITY
	 */

	/**
	 * List events pending for the current period
	 */
	public function get_events() {
		$events = get_option( 'cron' );

		// That was easy
		if ( ! is_array( $events ) || empty( $events ) ) {
			return array( 'events' => null, );
		}

		// To be safe, re-sort the array just as Core does when events are scheduled
		// Ensures events are sorted chronologically
		uksort( $events, 'strnatcasecmp' );

		// Simplify array format for further processing
		$events = collapse_events_array( $events );

		// Select only those events to run in the next sixty seconds
		// Will include missed events as well
		$current_events = $internal_events = array();
		$current_window = strtotime( sprintf( '+%d seconds', JOB_QUEUE_WINDOW_IN_SECONDS ) );

		foreach ( $events as $event ) {
			// Skip events whose time hasn't come
			if ( $event['timestamp'] > $current_window ) {
				continue;
			}

			// Necessary data to identify an individual event
			// `$event['action']` is hashed to avoid information disclosure
			// Core hashes `$event['instance']` for us
			$event = array(
				'timestamp' => $event['timestamp'],
				'action'    => md5( $event['action'] ),
				'instance'  => $event['instance'],
			);

			// Queue internal events separately to avoid them being blocked
			if ( is_internal_event( $event['action'] ) ) {
				$internal_events[] = $event;
			} else {
				$current_events[] = $event;
			}
		}

		// Limit batch size to avoid resource exhaustion
		if ( count( $current_events ) > JOB_QUEUE_SIZE ) {
			$current_events = array_slice( $current_events, 0, JOB_QUEUE_SIZE );
		}

		return array(
			'events'   => array_merge( $current_events, $internal_events ),
			'endpoint' => get_rest_url( null, REST_API_NAMESPACE . '/' . REST_API_ENDPOINT_RUN ),
		);
	}

	/**
	 * Execute a specific event
	 *
	 * @param $timestamp  int     Unix timestamp
	 * @param $action     string  md5 hash of the action used when the event is registered
	 * @param $instance   string  md5 hash of the event's arguments array, which Core uses to index the `cron` option
	 *
	 * @return array|\WP_Error
	 */
	public function run_event( $timestamp, $action, $instance ) {
		// Validate input data
		if ( empty( $timestamp ) || empty( $action ) || empty( $instance ) ) {
			return new \WP_Error( 'missing-data', __( 'Invalid or incomplete request data.', 'wp-cron-control-revisited' ) );
		}

		// Ensure we don't run jobs too far ahead
		if ( $timestamp > strtotime( sprintf( '+%d seconds', JOB_EXECUTION_BUFFER_IN_SECONDS ) ) ) {
			return new \WP_Error( 'premature', __( 'Event is not scheduled to be run yet.', 'wp-cron-control-revisited' ) );
		}

		// Find the event to retrieve the full arguments
		$event = $this->get_event( $timestamp, $action, $instance );
		unset( $timestamp, $action, $instance );

		// Nothing to do...
		if ( ! is_array( $event ) ) {
			return new \WP_Error( 'no-event', __( 'The specified event could not be found.', 'wp-cron-control-revisited' ) );
		}

		// And we're off!
		$time_start = microtime( true );

		// Limit how many events are processed concurrently
		if ( ! is_internal_event( $event['action'] ) && ! Lock::check_lock( self::LOCK ) ) {
			return new \WP_Error( 'no-free-threads', __( 'No resources available to run this job.', 'wp-cron-control-revisited' ) );
		}

		// Prepare environment to run job
		ignore_user_abort( true );
		set_time_limit( JOB_TIMEOUT_IN_MINUTES * MINUTE_IN_SECONDS );
		define( 'DOING_CRON', true );

		// Remove the event, and reschedule if desired
		// Follows pattern Core uses in wp-cron.php
		if ( false !== $event['schedule'] ) {
			$reschedule_args = array( $event['timestamp'], $event['schedule'], $event['action'], $event['args'] );
			call_user_func_array( 'wp_reschedule_event', $reschedule_args );
		}

		wp_unschedule_event( $event['timestamp'], $event['action'], $event['args'] );

		// Run the event
		do_action_ref_array( $event['action'], $event['args'] );

		// Free process for the next event
		if ( ! is_internal_event( $event['action'] ) ) {
			Lock::free_lock( self::LOCK );
		}

		$time_end = microtime( true );

		return array(
			'success' => true,
			'message' => sprintf( __( 'Job with action `%1$s` and arguments `%2$s` completed in %3$d seconds.', 'wp-cron-control-revisited' ), $event['action'], maybe_serialize( $event['args'] ), $time_end - $time_start ),
		);
	}

	/**
	 * Find an event's data using its hashed representations
	 *
	 * The `$instance` argument is hashed for us by Core, while we hash the action to avoid information disclosure
	 */
	private function get_event( $timestamp, $action_hashed, $instance ) {
		$events = get_option( 'cron' );
		$event  = false;

		$filtered_events = collapse_events_array( $events, $timestamp );

		foreach ( $filtered_events as $filtered_event ) {
			if ( hash_equals( md5( $filtered_event['action'] ), $action_hashed ) && hash_equals( $filtered_event['instance'], $instance ) ) {
				$event = $filtered_event['args'];
				$event['timestamp'] = $filtered_event['timestamp'];
				$event['action']    = $filtered_event['action'];
				$event['instance']  = $filtered_event['instance'];
				break;
			}
		}

		return $event;
	}
}
