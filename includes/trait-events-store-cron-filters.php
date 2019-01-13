<?php
/**
 * Event storage for WP 5.1 and later.
 *
 * @package a8c_Cron_Control
 */

namespace Automattic\WP\Cron_Control;

/**
 * Trait Option_Intercept
 */
trait Events_Store_Cron_Filters {
	/**
	 * Register hooks to intercept events before storage.
	 */
	protected function register_core_cron_filters() {
		add_filter( 'pre_schedule_event', [ $this, 'filter_event_scheduling' ], 10, 2 );
		add_filter( 'pre_reschedule_event', [ $this, 'filter_event_rescheduling' ], 10, 2 );
		add_filter( 'pre_unschedule_event', [ $this, 'filter_event_unscheduling' ], 10, 4 );
		add_filter( 'pre_clear_scheduled_hook', [ $this, 'filter_clear_scheduled_hook' ], 10, 3 );
		add_filter( 'pre_unschedule_hook', [ $this, 'filter_unchedule_hook' ], 10, 2 );
		add_filter( 'pre_get_scheduled_event', [ $this, 'filter_event_retrieval' ], 10, 4 );
		add_filter( 'pre_next_scheduled', [ $this, 'filter_next_scheduled' ], 10, 4 );
	}

	/**
	 * Intercept event scheduling.
	 *
	 * @param bool|null $scheduled Bool if event was already intercepted, null otherwise.
	 * @param \stdClass $event Event object.
	 * @return bool|null
	 */
	public function filter_event_scheduling( $scheduled, $event ) {
		if ( null !== $scheduled ) {
			return $scheduled;
		}

		$this->do_schedule_from_filter( $event );

		return true;
	}

	/**
	 * Intercept event rescheduling.
	 *
	 * Largely duplicates timestamp logic from `wp_reschedule_event()`.
	 *
	 * @param bool|null $rescheduled Bool if event was already intercepted, null otherwise.
	 * @param \stdClass $event Event object.
	 * @return bool|null
	 */
	public function filter_event_rescheduling( $rescheduled, $event ) {
		if ( null !== $rescheduled ) {
			return $rescheduled;
		}

		$previous_timestamp = $event->timestamp;

		$schedules = wp_get_schedules();
		$interval  = isset( $event->interval ) ? (int) $event->interval : 0;

		// Defer to scheduled interval, if possible.
		if ( isset( $schedules[ $event->schedule ] ) ) {
			$interval = $schedules[ $event->schedule ]['interval'];
		}

		$now = time();

		if ( $event->timestamp >= $now ) {
			$event->timestamp = $now + $interval;
		} else {
			$event->timestamp = $now + ( $interval - ( ( $now - $event->timestamp ) % $interval ) );
		}

		$this->do_schedule_from_filter( $event, $previous_timestamp );
		return true;
	}

	/**
	 * Helper for schedule and reschedule filter callbacks.
	 *
	 * @param \stdClass $event Event object.
	 * @param int|null  $previous_timestamp Previous timestamp, when rescheduling a recurring event.
	 */
	protected function do_schedule_from_filter( $event, $previous_timestamp = null ) {
		$existing = $this->get_job_by_attributes( [
			'action'    => $event->hook,
			'timestamp' => ! empty( $previous_timestamp ) ? $previous_timestamp : $event->timestamp,
			'instance'  => $this->generate_instance_identifier( $event->args ),
		] );

		$args = [
			'args'     => $event->args,
			'schedule' => $event->schedule,
		];

		if ( isset( $event->interval ) ) {
			$args['interval'] = $event->interval;
		}

		$this->create_or_update_job( $event->timestamp, $event->hook, $args, $existing ? $existing->ID : null );
	}

	/**
	 * Intercept event unscheduling.
	 *
	 * @param bool|null $unscheduled Bool if event was already intercepted, null otherwise.
	 * @param int       $timestamp Event timestamp.
	 * @param string    $hook Event action.
	 * @param array     $args Event arguments.
	 * @return bool|null
	 */
	public function filter_event_unscheduling( $unscheduled, $timestamp, $hook, $args ) {
		if ( null !== $unscheduled ) {
			return $unscheduled;
		}

		return $this->mark_job_completed( $timestamp, $hook, $this->generate_instance_identifier( $args ) );
	}

	/**
	 * Clear all actions for a given hook with given arguments.
	 *
	 * @param bool|null $cleared Bool if hook was already cleared, null otherwise.
	 * @param string    $hook Event action.
	 * @param array     $args Event arguments.
	 * @return bool|int
	 */
	public function filter_clear_scheduled_hook( $cleared, $hook, $args ) {
		if ( null !== $cleared ) {
			return $cleared;
		}

		return $this->do_unschedule_hook( $hook, $args );
	}

	/**
	 * Clear all actions for a given hook, regardless of arguments.
	 *
	 * @param bool|null $cleared Bool if hook was already cleared, null otherwise.
	 * @param string    $hook Event action.
	 * @return bool|int
	 */
	public function filter_unchedule_hook( $cleared, $hook ) {
		// Bail, something else already cleared this hook.
		if ( null !== $cleared ) {
			return $cleared;
		}

		return $this->do_unschedule_hook( $hook, null );
	}

	/**
	 * Unschedule all events with a given hook and optional arguments.
	 *
	 * @param string     $hook Action to clear.
	 * @param array|null $args Optional job arguments to filter by.
	 * @return bool|int
	 */
	protected function do_unschedule_hook( $hook, $args ) {
		$ids      = [ [] ];
		$page     = 1;
		$quantity = 500;

		do {
			$batch_ids = $this->get_job_ids_by_hook(
				[
					'page'     => $page,
					'quantity' => $quantity,
				],
				$hook,
				$args
			);

			$ids[] = $batch_ids;
			$page ++;
		} while ( ! empty( $batch_ids ) && count( $batch_ids ) === $quantity );

		$ids = array_merge( ...$ids );

		if ( empty( $ids ) ) {
			return false;
		}

		$results = [];

		foreach ( $ids as $id ) {
			$results[] = $this->mark_job_record_completed( $id, false );
		}

		$this->flush_internal_caches();

		$results = array_filter( $results );
		return empty( $results ) ? false : count( $results );
	}

	/**
	 * Intercept event retrieval.
	 *
	 * @param \stdClass|null $retrieved \stdClass if already retrieved, null otherwise.
	 * @param string         $hook Job action.
	 * @param array          $args Job arguments.
	 * @param int            $timestamp Job timestamp.
	 * @return \stdClass|bool|null
	 */
	public function filter_event_retrieval( $retrieved, $hook, $args, $timestamp ) {
		if ( null !== $retrieved ) {
			return $retrieved;
		}

		$job = $this->get_job_by_attributes( [
			'action'    => $hook,
			'timestamp' => $timestamp,
			'instance'  => $this->generate_instance_identifier( $args ),
		] );

		if ( ! $job ) {
			return false;
		}

		$event = (object) [
			'hook'      => $job->action,
			'timestamp' => $job->timestamp,
			'schedule'  => $job->schedule,
			'args'      => maybe_unserialize( $job->args ),
		];

		if ( ! empty( $job->interval ) ) {
			$event->interval = $job->interval;
		}

		return $event;
	}

	/**
	 * Intercept request for event's next timestamp.
	 *
	 * @param int|bool|null $next Int or bool if already looked up, null otherwise.
	 * @param string        $hook Job action.
	 * @param array         $args Job arguments.
	 * @return int|bool|null
	 */
	public function filter_next_scheduled( $next, $hook, $args ) {
		global $wpdb;

		if ( null !== $next ) {
			return $next;
		}

		// TODO: cache this lookup?
		$ts = $wpdb->get_var( $wpdb->prepare(
			"SELECT timestamp FROM {$this->get_table_name()} WHERE action = %s AND instance = %s AND status = %s ORDER BY timestamp ASC LIMIT 1", // Cannot prepare table name. @codingStandardsIgnoreLine
			$hook,
			$this->generate_instance_identifier( $args ),
			self::STATUS_PENDING
		) );

		return empty( $ts ) ? null : (int) $ts;
	}
}
