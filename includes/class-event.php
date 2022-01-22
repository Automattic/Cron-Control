<?php

namespace Automattic\WP\Cron_Control;

use WP_Error;

class Event {
	private ?int $id;
	private string $status;

	// TODO: Maybe we don't need action_hashed going forward?
	private string $action;
	private string $action_hashed;

	private array $args = [];
	private string $instance;

	// These are left empty for one-time events.
	private ?string $schedule;
	private ?int $interval;

	// When the event will run next.
	private int $timestamp;

	private $created;
	private $last_modified;

	/*
	|--------------------------------------------------------------------------
	| Getters
	|--------------------------------------------------------------------------
	*/

	public function get_id(): ?int {
		return isset( $this->id ) ? $this->id : null;
	}

	public function get_status(): ?string {
		return isset( $this->status ) ? $this->status : null;
	}

	public function get_action(): ?string {
		return isset( $this->action ) ? $this->action : null;
	}

	public function get_args(): array {
		return $this->args;
	}

	public function get_instance(): string {
		// Defaults to a hash of the empty args array.
		return isset( $this->instance ) ? $this->instance : self::create_instance_hash( $this->args );
	}

	public function get_schedule(): ?string {
		return isset( $this->schedule ) ? $this->schedule : null;
	}

	public function get_interval(): ?int {
		return isset( $this->interval ) ? $this->interval : null;
	}

	public function get_timestamp(): ?int {
		return isset( $this->timestamp ) ? $this->timestamp : null;
	}

	/*
	|--------------------------------------------------------------------------
	| Setters
	|--------------------------------------------------------------------------
	*/

	public function set_status( string $status ): void {
		$this->status = strtolower( $status );
	}

	public function set_action( string $action ): void {
		$this->action = $action;
		$this->action_hashed = md5( $action );
	}

	public function set_args( array $args ): void {
		$this->args = $args;
		$this->instance = self::create_instance_hash( $this->args );
	}

	public function set_schedule( string $schedule, int $interval ): void {
		$this->schedule = $schedule;
		$this->interval = $interval;
	}

	public function set_timestamp( int $timestamp ): void {
		$this->timestamp = $timestamp;
	}

	/*
	|--------------------------------------------------------------------------
	| Methods for interacting with the object.
	|--------------------------------------------------------------------------
	*/

	/**
	 * Save the event (create if needed, else update).
	 *
	 * @return true|WP_Error true on success, WP_Error on failure.
	 */
	public function save() {
		// Set default status for new events.
		if ( ! $this->exists() && null === $this->get_status() ) {
			$this->set_status( Events_Store::STATUS_PENDING );
		}

		$validation_result = $this->validate_props();
		if ( is_wp_error( $validation_result ) ) {
			return $validation_result;
		}

		$row_data = [
			'status'        => $this->get_status(),
			'action'        => $this->get_action(),
			'action_hashed' => $this->action_hashed,
			'args'          => serialize( $this->get_args() ),
			'instance'      => $this->get_instance(),
			'timestamp'     => $this->get_timestamp(),
		];

		if ( $this->is_recurring() ) {
			$row_data['schedule'] = $this->get_schedule();
			$row_data['interval'] = $this->get_interval();
		} else {
			// Data store expects these as the defaults for "empty".
			$row_data['schedule'] = null;
			$row_data['interval'] = 0;
		}

		// About to be updated, so increment the "last modified" timestamp.
		$current_time = current_time( 'mysql', true );
		$row_data['last_modified'] = $current_time;

		if ( $this->exists() ) {
			$success = Events_Store::instance()->_update_event( $this->id, $row_data );
			if ( ! $success ) {
				return new WP_Error( 'cron-control:event:failed-update' );
			}

			$this->last_modified = $current_time;
			return true;
		}

		$row_data['created'] = $current_time;

		$event_id = Events_Store::instance()->_create_event( $row_data );
		if ( $event_id < 1 ) {
			return new WP_Error( 'cron-control:event:failed-create' );
		}

		$this->id = $event_id;
		$this->created = $current_time;
		return true;
	}

	/**
	 * Mark the event as completed.
	 * TODO: Probably introduce cancel() method and status as well for more specific situations.
	 *
	 * @return true|WP_Error true on success, WP_Error on failure.
	 */
	public function complete() {
		if ( ! $this->exists() ) {
			return new WP_Error( 'cron-control:event:cannot-complete' );
		}

		// Prevent conflicts with the unique constraints in the table.
		// Is a bit unfortunate since it won't be as easy to query for the event anymore.
		// Perhaps in the future could remove the unique constraint in favor of stricter duplicate checking.
		$this->instance = 'randomized:' . (string) mt_rand( 1000000, 9999999999999 );

		$this->set_status( Events_Store::STATUS_COMPLETED );
		return $this->save();
	}

	/**
	 * Reschedule the event w/ an updated timestamp.
	 *
	 * @return true|WP_Error true on success, WP_Error on failure.
	 */
	public function reschedule() {
		if ( ! $this->exists() ) {
			return new WP_Error( 'cron-control:event:cannot-reschedule' );
		}

		if ( ! $this->is_recurring() ) {
			// The event doesn't recur (or data was corrupted somehow), mark it as completed instead.
			$this->complete();
			return new WP_Error( 'cron-control:event:cannot-reschedule' );
		}

		// Get a fresh status from the database.
		$db_row = Events_Store::instance()->_get_event_raw( $this->id );
		if ( is_null( $db_row ) || in_array( $db_row->status, Events_Store::INACTIVE_STATUSES, true ) ) {
			// The event must have been completed/cancelled in a concurrent request, respect that and don't reschedule.
			return new WP_Error( 'cron-control:event:cannot-reschedule-completed-event' );
		}

		$fresh_interval = $this->get_refreshed_schedule_interval();
		$next_timestamp = $this->calculate_next_timestamp( $fresh_interval );

		if ( $this->interval !== $fresh_interval ) {
			$this->set_schedule( $this->schedule, $this->interval );
		}

		$this->set_status( Events_Store::STATUS_PENDING );
		$this->set_timestamp( $next_timestamp );
		return $this->save();
	}

	/**
	 * Update the event's status to $new_status as long as $current_status is a match with the current state.
	 * Helps prevent concurrency-related issues by keeping the database as the source of truth for event state.
	 *
	 * @return true|WP_Error true on success, WP_Error on failure.
	 */
	private function update_status_from_to( string $current_status, string $new_status ) {
		if ( ! $this->exists() ) {
			return new WP_Error( 'cron-control:event:cannot-update-status' );
		}

		if ( ! in_array( $new_status, Events_Store::ALLOWED_STATUSES, true ) ) {
			return new WP_Error( 'cron-control:event:prop-validation:invalid-status' );
		}

		// Need to send action/args to assist with cache invalidation.
		$row_data = [
			'action' => $this->get_action(),
			'args'   => serialize( $this->get_args() ),
			'status' => $new_status,
		];

		$current_time = current_time( 'mysql', true );
		$row_data['last_modified'] = $current_time;

		$success = Events_Store::instance()->_update_event( $this->id, $row_data, [ 'status' => $current_status ] );
		if ( ! $success ) {
			return new WP_Error( 'cron-control:event:status-update-failed' );
		}

		$this->last_modified = $current_time;
		$this->set_status( $new_status );
		return true;
	}

	/**
	 * Run an event if all the safety/concurrency checks pass.
	 *
	 * @return true|WP_Error true on success, WP_Error on failure.
	 */
	public function run_if_allowed() {
		// First round of safety, ensure the event is actually due to run.
		if ( $this->get_timestamp() > time() || Events_Store::STATUS_PENDING !== $this->get_status() ) {
			return new WP_Error( 'cron-control:event:not-ready-yet' );
		}

		// Second round of safety, ensure the same event actions are not run in parallel if concurrency is not allowed.
		if ( ! $this->claim_action_run_lock() ) {
			return new WP_Error( 'cron-control:event:action-lock-unavailable' );
		}

		// Third round of safety, adherring to the total allowed concurrency at the site-level. Internal events are exempt.
		if ( ! $this->is_internal() ) {
			// This check is not perfect as another parrallel request could be checking at the same time. But it's also not the most vital of checks.
			// May be removing this check entirely in the future and just having "unlimited" site-level concurrency, letting the runner decide this instead.
			$running_events = Events::query( [ 'status' => Events_Store::STATUS_RUNNING, 'limit' => -1 ] );
			if ( count( $running_events ) >= JOB_CONCURRENCY_LIMIT ) {
				$this->unclaim_action_run_lock();
				return new WP_Error( 'cron-control:event:site-concurrency-limit-reached' );
			}
		}

		// Finally, try to mark the event as "running" to claim ownership.
		$update_result = $this->update_status_from_to( Events_Store::STATUS_PENDING, Events_Store::STATUS_RUNNING );
		if ( is_wp_error( $update_result ) ) {
			$this->unclaim_action_run_lock();
			return new WP_Error( 'cron-control:event:failed-to-set-running-status' );
		}

		$run_result = $this->run();
		$this->unclaim_action_run_lock();

		return $run_result;
	}

	/**
	 * Run an event and reschedule/complete afterwards. Catches most fatal errors.
	 *
	 * @return true|WP_Error true on success, WP_Error on failure.
	 */
	public function run() {
		try {
			do_action_ref_array( $this->action, $this->args );
		} catch ( \Throwable $t ) {
			// Catch any fatals during the event callback (excluding OOMs/timeouts, can't catch those here) and log as a warning instead. Allowing us to continue on with event cleanup.
			$error_message = sprintf( 'PHP Fatal error: Cron-Control Caught Error: %1$s%2$sStack trace: %3$s', $t->getMessage(), PHP_EOL, $t->getTraceAsString() );
			trigger_error( $error_message, E_USER_WARNING );

			$result = $this->is_recurring() ? $this->reschedule() : $this->complete();
			return is_wp_error( $result ) ? $result->get_error_code() : new WP_Error( 'cron-control:event:error-thrown-during-run' );
		}

		return $this->is_recurring() ? $this->reschedule() : $this->complete();
	}

	private function claim_action_run_lock(): bool {
		$has_allowed_concurrency = in_array( $this->action, Events::instance()->get_actions_with_allowed_concurrency(), true );
		if ( $has_allowed_concurrency ) {
			// The "lock" is always available if the event allows for concurrency.
			return true;
		}

		// Will return false if the lock is already taken by another process.
		$timeout = LOCK_DEFAULT_TIMEOUT_IN_MINUTES * \MINUTE_IN_SECONDS;
		return wp_cache_add( "event_action_{$this->action}", 1, 'cron-control-locks', $timeout );
	}

	private function unclaim_action_run_lock(): void {
		$has_allowed_concurrency = in_array( $this->action, Events::instance()->get_actions_with_allowed_concurrency(), true );
		if ( ! $has_allowed_concurrency ) {
			wp_cache_delete( "event_action_{$this->action}", 'cron-control-locks' );
		}
	}

	/*
	|--------------------------------------------------------------------------
	| Utilities
	|--------------------------------------------------------------------------
	*/

	public static function get( int $event_id ): ?Event {
		$db_row = Events_Store::instance()->_get_event_raw( $event_id );
		return is_null( $db_row ) ? null : self::get_from_db_row( $db_row );
	}

	public static function find( array $query_args ): ?Event {
		$results = Events_Store::instance()->_query_events_raw( array_merge( $query_args, [ 'limit' => 1 ] ) );
		return empty( $results ) ? null : self::get_from_db_row( $results[0] );
	}

	public static function get_from_db_row( object $data ): ?Event {
		if ( ! isset( $data->ID, $data->status, $data->action, $data->timestamp ) ) {
			// Missing expected/required data, cannot setup the object.
			return null;
		}

		$event = new Event();
		$event->id = (int) $data->ID;
		$event->set_status( (string) $data->status );
		$event->set_action( (string) $data->action );
		$event->set_timestamp( (int) $data->timestamp );
		$event->set_args( (array) maybe_unserialize( $data->args ) );
		$event->created = $data->created;
		$event->last_modified = $data->last_modified;

		if ( ! empty( $data->schedule ) && ! empty( $data->interval ) ) {
			// Note: the db is sending back "null" and "0" for the above two on single events,
			// so we do the above empty() checks to filter that out.
			$event->set_schedule( (string) $data->schedule, (int) $data->interval );
		}

		return $event;
	}

	public function exists(): bool {
		return isset( $this->id );
	}

	public function is_recurring() : bool {
		// To allow validation to do it's job, here we just see if the props have ever been set.
		return isset( $this->schedule, $this->interval );
	}

	public function is_internal(): bool {
		return Internal_Events::instance()->is_internal_event( $this->action );
	}

	// The format WP expects an event to come in.
	public function get_wp_event_format(): object {
		$wp_event = [
			'hook'      => $this->get_action(),
			'timestamp' => $this->get_timestamp(),
			'schedule'  => empty( $this->get_schedule() ) ? false : $this->get_schedule(),
			'args'      => $this->get_args(),
		];

		if ( $this->is_recurring() ) {
			$wp_event['interval'] = $this->get_interval();
		}

		return (object) $wp_event;
	}

	// The old way this plugin used to pass around event objects.
	// Needed for BC for some hooks, hopefully deprecated/removed fully later on.
	public function get_legacy_event_format(): object {
		$legacy_format = [
			'ID'            => $this->get_id(),
			'timestamp'     => $this->get_timestamp(),
			'action'        => $this->get_action(),
			'action_hashed' => $this->action_hashed,
			'instance'      => $this->get_instance(),
			'args'          => $this->get_args(),
			'schedule'      => isset( $this->schedule ) ? $this->get_schedule() : false,
			'interval'      => isset( $this->interval ) ? $this->get_interval() : 0,
			'status'        => $this->get_status(),
			'created'       => $this->created,
			'last_modified' => $this->last_modified,
		];

		return (object) $legacy_format;
	}

	public static function create_instance_hash( array $args ): string {
		return md5( serialize( $args ) );
	}

	private function validate_props() {
		$status = $this->get_status();
		if ( ! in_array( $status, Events_Store::ALLOWED_STATUSES, true ) ) {
			return new WP_Error( 'cron-control:event:prop-validation:invalid-status' );
		}

		$action = $this->get_action();
		if ( empty( $action ) ) {
			return new WP_Error( 'cron-control:event:prop-validation:invalid-action' );
		}

		$timestamp = $this->get_timestamp();
		if ( empty( $timestamp ) || $timestamp < 1 ) {
			return new WP_Error( 'cron-control:event:prop-validation:invalid-timestamp' );
		}

		if ( $this->is_recurring() ) {
			if ( empty( $this->get_schedule() ) || $this->get_interval() <= 0 ) {
				return new WP_Error( 'cron-control:event:prop-validation:invalid-schedule' );
			}
		}

		// Don't prevent event creation, but do warn about overly large arguments.
		if ( ! $this->args_array_is_reasonably_sized() ) {
			trigger_error( sprintf( 'Cron-Control: Event (action: %s) was added w/ abnormally large arguments. This can badly effect performance.', $this->get_action() ), E_USER_WARNING );
		}

		return true;
	}

	// Similar functionality to wp_reschedule_event().
	private function calculate_next_timestamp( int $interval ): ?int {
		$now = time();

		if ( $this->timestamp >= $now ) {
			// Event was ran ahead (or right on) it's due time, schedule it to run again after it's full interval.
			return $now + $interval;
		}

		// Event ran a bit delayed, adjust accordingly (example: a 12h interval event running 6h late will be scheduled for +6h from now).
		// TODO: Maybe we can simplify here later and just always return `$now + $interval`?
		$elapsed_time_since_due = $now - $this->timestamp;
		$remaining_seconds_into_the_future = ( $interval - ( $elapsed_time_since_due % $interval ) );
		return $now + $remaining_seconds_into_the_future;
	}

	private function get_refreshed_schedule_interval() {
		// Try to get the interval from the schedule in case it's been updated.
		$schedules = wp_get_schedules();
		if ( isset( $schedules[ $this->schedule ] ) ) {
			return (int) $schedules[ $this->schedule ]['interval'];
		}

		// If we couldn't get from schedule (was removed), use whatever was saved already.
		return $this->interval;
	}

	private function args_array_is_reasonably_sized(): bool {
		// We aim to cache queries w/ up to 500 events.
		$max_events_per_page = 500;

		// A compressed db row of an event is around 300 bytes.
		$db_row_size_of_normal_event = 300;

		// Note: Memcache can only cache up to 1mb values, after compression.
		$reasonable_size = ( MB_IN_BYTES / $max_events_per_page ) - $db_row_size_of_normal_event;

		// First a quick uncompressed test.
		$uncompressed_size = mb_strlen( serialize( $this->get_args() ), '8bit' );
		if ( $uncompressed_size < $reasonable_size * 4 ) {
			// After compression, this is for sure generously under the limit.
			return true;
		}

		// Now the more expensive test, accounting for compression.
		$compressed_args = gzdeflate( serialize( $this->get_args() ) );
		if ( false === $compressed_args ) {
			// Wasn't able to compress, let's assume it is above the ideal limit.
			return false;
		}

		$compressed_size = mb_strlen( $compressed_args, '8bit' );
		return $compressed_size < $reasonable_size;
	}
}
