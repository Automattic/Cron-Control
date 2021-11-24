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

	// Keeps track of what properties are changed.
	private array $changed = [];

	/**
	 * Get an existing event if an ID or row data is passed, otherwise the event is new and empty.
	 * Note: Use Event::get() when possible instead of initializing an existing event here.
	 *
	 * @param int|object $event Event to initialize.
	 */
	public function __construct( $event = null ) {
		if ( is_int( $event ) && $event > 0 ) {
			$event_stub = new \stdClass();
			$event_stub->ID = $event;
			self::populate_data( $event_stub );
		} elseif ( ! empty( $event->ID ) ) {
			self::populate_data( $event );
		}
	}

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
		$status = strtolower( $status );

		if ( in_array( $status, Events_Store::ALLOWED_STATUSES, true ) ) {
			$this->status = $status;
		} else {
			$this->status = Events_Store::STATUS_PENDING;
		}

		// Always mark status as changed, as we want to ensure it always sends this data in the SQL if needed.
		$this->mark_changed( 'status' );
	}

	public function set_action( string $action ): void {
		if ( ! empty( $action ) ) {
			$this->action = $action;
			$this->mark_changed( 'action' );

			$this->action_hashed = md5( $action );
			$this->mark_changed( 'action_hashed' );
		}
	}

	public function set_args( array $args ): void {
		$this->args = $args;
		$this->mark_changed( 'args' );

		$this->instance = self::create_instance_hash( $this->args );
		$this->mark_changed( 'instance' );
	}

	public function set_schedule( string $schedule, int $interval ): void {
		if ( ! empty( $schedule ) && $interval > 0 ) {
			// Ensure either both or none are set.
			$this->schedule = $schedule;
			$this->mark_changed( 'schedule' );

			$this->interval = $interval;
			$this->mark_changed( 'interval' );
		}
	}

	public function set_timestamp( int $timestamp ): void {
		if ( $timestamp >= 1 ) {
			$this->timestamp = $timestamp;
			$this->mark_changed( 'timestamp' );
		}
	}

	/*
	|--------------------------------------------------------------------------
	| Methods for interacting with the object.
	|--------------------------------------------------------------------------
	*/

	/**
	 * Save the event based on locally-changed props.
	 *
	 * @return true|WP_Error true on success, WP_Error on failure.
	 */
	public function save() {
		$changed_data = $this->get_changed_data();

		if ( empty( $changed_data ) ) {
			// Nothing to save.
			return new WP_Error( 'cron-control:event:no-save-needed' );
		}

		if ( ! isset( $this->action, $this->timestamp ) ) {
			// These are required properties, must be set in order to save.
			return new WP_Error( 'cron-control:event:missing-props' );
		}

		$all_row_data = [
			'status'        => isset( $this->status ) ? $this->status : null,
			'action'        => $this->action,
			'action_hashed' => md5( $this->action ),
			'args'          => serialize( $this->args ),
			'instance'      => self::create_instance_hash( $this->args ),
			'timestamp'     => $this->timestamp,
		];

		if ( isset( $this->schedule ) && isset( $this->interval ) ) {
			$all_row_data['schedule'] = $this->schedule;
			$all_row_data['interval'] = $this->interval;
		}

		// Pick out just the data that has changed.
		$row_data = [];
		foreach ( $changed_data as $changed_prop ) {
			$row_data[ $changed_prop ] = $all_row_data[ $changed_prop ];
		}

		if ( $this->exists() ) {
			$success = Events_Store::_update_event( $this->id, $row_data );
			if ( ! $success ) {
				return new WP_Error( 'cron-control:event:failed-update' );
			}

			$this->clear_changed_data();
			return true;
		}

		// Gotta create the event for the first time. Let's make sure we send all the necessary data and defaults.
		$status_set = false;
		if ( ! isset( $row_data['status'] ) ) {
			$row_data['status'] = Events_Store::STATUS_PENDING;
			$status_set = true;
		}

		if ( ! isset( $row_data['args'], $row_data['instance'] ) ) {
			$row_data['args']     = $all_row_data['args'];
			$row_data['instance'] = $all_row_data['instance'];
		}

		if ( ! isset( $row_data['schedule'], $row_data['interval'] ) ) {
			// Data store expects these, however we'll leave both as null internally here.
			$row_data['schedule'] = null;
			$row_data['interval'] = 0;
		}

		$event_id = Events_Store::_create_event( $row_data );
		if ( $event_id < 1 ) {
			return new WP_Error( 'cron-control:event:failed-create' );
		}

		// Hydrate the object with the defaults that were set.
		$this->id       = $event_id;
		$this->status   = $status_set ? Events_Store::STATUS_PENDING : $this->status;

		$this->clear_changed_data();
		return true;
	}

	public function run(): void {
		do_action_ref_array( $this->action, $this->args );
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
		$this->instance = (string) mt_rand( 1000000, 9999999999999 );
		$this->mark_changed( 'instance' );

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

		if ( ! isset( $this->schedule, $this->interval ) ) {
			// The event doesn't recur (or data was corrupted somehow), mark it as cancelled instead.
			$this->complete();
			return new WP_Error( 'cron-control:event:cannot-reschedule' );
		}

		$fresh_interval = $this->get_refreshed_schedule_interval();
		$next_timestamp = $this->calculate_next_timestamp( $fresh_interval );

		if ( $this->interval !== $fresh_interval ) {
			$this->set_schedule( $this->schedule, $this->interval );
		}

		$this->set_timestamp( $next_timestamp );
		return $this->save();
	}

	/*
	|--------------------------------------------------------------------------
	| Utilities
	|--------------------------------------------------------------------------
	*/

	/**
	 * Helper method for getting an event.
	 *
	 * @param int|array $event Event ID, or args to search for an event.
	 * @return Event|null Returns an Event if successful, else null if the event could not be found.
	 */
	public static function get( $event ): ?Event {
		if ( is_int( $event ) ) {
			$id = $event;
			$event = new Event( $id );
			return $event->exists() ? $event : null;
		}

		if ( is_array( $event ) ) {
			$args = $event;
			$query = Events_Store::_query_events_raw( array_merge( $args, [ 'limit' => 1 ] ) );
			if ( empty( $query ) ) {
				return null;
			}

			$event = new Event( $query[0] );
			return $event->exists() ? $event : null;
		}

		return null;
	}

	public function exists(): bool {
		return isset( $this->id );
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

		if ( isset( $this->interval ) ) {
			$wp_event['interval'] = $this->interval;
		}

		return (object) $wp_event;
	}

	public static function create_instance_hash( array $args ): string {
		return md5( serialize( $args ) );
	}

	private function get_changed_data(): array {
		return array_keys( $this->changed );
	}

	private function clear_changed_data(): void {
		$this->changed = [];
	}

	private function mark_changed( string $property ): void {
		$this->changed[ $property ] = true;
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

	private function populate_data( object $data ): void {
		if ( ! isset( $data->status, $data->action, $data->args, $data->timestamp ) ) {
			if ( isset( $data->ID ) ) {
				// Given just an ID, grab the rest of the data.
				$data = Events_Store::_get_event_raw( $data->ID );
			}

			if ( ! isset( $data->ID, $data->status, $data->action, $data->args, $data->timestamp ) ) {
				// Still no valid data, avoid setting up the object. Will be treated as a new event now.
				return;
			}
		}

		$this->id = $data->ID;
		$this->set_status( (string) $data->status );
		$this->set_action( (string) $data->action );
		$this->set_timestamp( (int) $data->timestamp );
		$this->set_args( (array) maybe_unserialize( $data->args ) );

		if ( ! empty( $data->schedule ) && ! empty( $data->interval ) ) {
			$this->set_schedule( (string) $data->schedule, (int) $data->interval );
		}

		// Didn't actually change anything yet.
		$this->clear_changed_data();
	}
}
