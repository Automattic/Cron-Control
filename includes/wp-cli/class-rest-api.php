<?php

namespace Automattic\WP\Cron_Control\CLI;

/**
 * Make requests to Cron Control's REST API
 */
class REST_API extends \WP_CLI_Command {
	/**
	 * Retrieve the current event queue
	 *
	 * @subcommand get-queue
	 */
	public function get_queue( $args, $assoc_args ) {
		// Build and make request
		$queue_request = new \WP_REST_Request( 'POST', '/' . \Automattic\WP\Cron_Control\REST_API::API_NAMESPACE . '/' . \Automattic\WP\Cron_Control\REST_API::ENDPOINT_LIST );
		$queue_request->add_header( 'Content-Type', 'application/json' );
		$queue_request->set_body( wp_json_encode( array(
			'secret' => \WP_CRON_CONTROL_SECRET,
		) ) );

		$queue_request = rest_do_request( $queue_request );

		// Oh well
		if ( $queue_request->is_error() ) {
			\WP_CLI::error( $queue_request->as_error()->get_error_message() );
		}

		// Get the decoded JSON object returned by the API
		$queue_response = $queue_request->get_data();

		// No events, nothing more to do
		if ( empty( $queue_response['events'] ) && empty( $queue_response['events_long_running' ] ) ) {
			\WP_CLI::warning( __( 'No events in the current queues', 'automattic-cron-control' ) );
			return;
		}

		// Render queues
		foreach ( array( 'events', 'events_long_running', ) as $event_type ) {
			$events_for_display = $queue_response[ $event_type ];

			$event_type_label = 'events' === $event_type ? 'regular' : 'long-running';

			if ( empty( $events_for_display ) ) {
				\WP_CLI::line( sprintf( __( 'No events in the %s events queue', 'automattic-cron-control' ), $event_type_label ) );
				\WP_CLI::line( '' );
				continue;
			}

			// Prepare items for display
			$events_for_display = $this->format_events( $events_for_display );
			$total_events_to_display = count( $events_for_display );
			\WP_CLI::line( sprintf( _n( 'Displaying one event from the %2$s queue', 'Displaying %1$s events from the %2$s queue', $total_events_to_display, 'automattic-cron-control' ), number_format_i18n( $total_events_to_display ), $event_type_label ) );

			// And reformat
			$format = 'table';
			if ( isset( $assoc_args['format'] ) ) {
				if ( 'ids' === $assoc_args['format'] ) {
					\WP_CLI::error( __( 'Invalid output format requested', 'automattic-cron-control' ) );
				} else {
					$format = $assoc_args['format'];
				}
			}

			\WP_CLI\Utils\format_items( $format, $events_for_display, array(
				'timestamp',
				'action',
				'instance',
				'scheduled_for',
				'internal_event',
				'schedule_name',
				'event_args',
			) );
		}
	}

	/**
	 * Format event data into something human-readable
	 *
	 * @param $events
	 *
	 * @return array
	 */
	private function format_events( $events ) {
		$formatted_events = array();

		foreach ( $events as $event ) {
			$event_data = \Automattic\WP\Cron_Control\get_event_by_attributes( array(
				'timestamp'     => $event['timestamp'],
				'action_hashed' => $event['action'],
				'instance'      => $event['instance'],
			) );

			$formatted_events[] = array(
				'timestamp'      => $event_data->timestamp,
				'action'         => $event_data->action,
				'instance'       => $event_data->instance,
				'scheduled_for'  => date( TIME_FORMAT, $event_data->timestamp ),
				'internal_event' => \Automattic\WP\Cron_Control\is_internal_event( $event_data->action ) ? __( 'true', 'automattic-cron-control' ) : '',
				'schedule_name'  => false === $event_data->schedule ? __( 'n/a', 'automattic-cron-control' ) : $event_data->schedule,
				'event_args'     => maybe_serialize( $event_data->args ),
			);
		}

		return $formatted_events;
	}
}

\WP_CLI::add_command( 'cron-control rest-api', 'Automattic\WP\Cron_Control\CLI\REST_API' );
