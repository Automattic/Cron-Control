<?php

namespace WP_Cron_Control_Revisited;

/**
 * Produce a simplified version of the cron events array
 *
 * Also removes superfluous, non-event data that Core stores in the option
 */
function collapse_events_array( $events ) {
	$collapsed_events = array();

	if ( ! is_array( $events ) ) {
		return $collapsed_events;
	}

	foreach ( $events as $timestamp => $timestamp_events ) {
		// Skip non-event data that Core includes in the option
		if ( ! is_numeric( $timestamp ) ) {
			continue;
		}

		foreach ( $timestamp_events as $action => $action_instances ) {
			foreach ( $action_instances as $instance => $instance_args ) {
				$collapsed_events[] = array(
					'timestamp' => $timestamp,
					'action'    => $action,
					'instance'  => $instance,
					'args'      => $instance_args,
				);
			}
		}
	}

	return $collapsed_events;
}
