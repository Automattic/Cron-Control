<?php

namespace Automattic\WP\Cron_Control\CLI;

/**
 * Manage Cron Control's data, including internal caches
 */
class Data extends \WP_CLI_Command {
	/**
	 * Flush Cron Control's internal caches
	 *
	 * @subcommand flush-caches
	 */
	public function flush_internal_caches( $args, $assoc_args ) {
		$flushed = \Automattic\WP\Cron_Control\flush_internal_caches();

		if ( $flushed ) {
			\WP_CLI::success( __( 'Internal caches cleared', 'automattic-cron-control' ) );
		} else {
			\WP_CLI::warning( __( 'No caches to clear', 'automattic-cron-control' ) );
		}
	}

	/**
	 * List cron events
	 *
	 * Intentionally bypasses caching to ensure latest data is shown
	 *
	 * @subcommand list-events
	 */
	public function list_events( $args, $assoc_args ) {
		$events = $this->get_events( $args, $assoc_args );

		// Output in the requested format
		if ( isset( $assoc_args['format'] ) && 'ids' === $assoc_args['format'] ) {
			echo implode( ' ', wp_list_pluck( $events['items'], 'ID' ) );
		} else {
			// Lest someone think the `completed` record should be...complete
			if ( isset( $assoc_args['status'] ) && 'completed' === $assoc_args['status'] ) {
				\WP_CLI::warning( __( 'Entries are purged automatically, so this cannot be relied upon as a record of past event execution.', 'automattic-cron-control' ) );
			}

			// Not much to do
			if ( 0 === $events['total_items'] ) {
				\WP_CLI::success( __( 'Nothing to display', 'automattic-cron-control' ) );
				return;
			}

			// Prepare events for display
			$events_for_display      = $this->format_events( $events['items'] );
			$total_events_to_display = count( $events_for_display );

			// How shall we display?
			$format = 'table';
			if ( isset( $assoc_args['format'] ) ) {
				$format = $assoc_args['format'];
			}

			// Count, noting if showing fewer than all
			if ( $events['total_items'] <= $total_events_to_display ) {
				\WP_CLI::line( sprintf( __( 'Displaying all %s entries', 'automattic-cron-control' ), number_format_i18n( $total_events_to_display ) ) );
			} else {
				\WP_CLI::line( sprintf( __( 'Displaying %s of %s entries', 'automattic-cron-control' ), number_format_i18n( $total_events_to_display ), number_format_i18n( $events['total_items'] ) ) );
			}

			// And reformat
			\WP_CLI\Utils\format_items( $format, $events_for_display, array(
				'ID',
				'action',
				'instance',
				'next_run_gmt',
				'next_run_relative',
				'recurrence',
				'event_args',
				'created_gmt',
				'modified_gmt',
			) );
		}
	}

	/**
	 * Retrieve list of events, and related data, for a given request
	 */
	private function get_events( $args, $assoc_args ) {
		global $wpdb;

		// Validate status, with a default
		$status = 'pending';
		if ( isset( $assoc_args['status'] ) ) {
			$status = $assoc_args['status'];
		}

		if ( 'pending' !== $status && 'completed' !== $status ) {
			\WP_CLI::error( __( 'Invalid status requested', 'automattic-cron-control' ) );
		}

		// Convert to post status
		$post_status = null;
		switch ( $status ) {
			case 'pending' :
				$post_status = \Automattic\WP\Cron_Control\Cron_Options_CPT::POST_STATUS_PENDING;
				break;

			case 'completed' :
				$post_status = \Automattic\WP\Cron_Control\Cron_Options_CPT::POST_STATUS_COMPLETED;
				break;
		}

		// Total to show
		$limit = 25;
		if ( isset( $assoc_args['limit'] ) && is_numeric( $assoc_args['limit'] ) ) {
			$limit = max( 1, min( absint( $assoc_args['limit'] ), 500 ) );
		}

		// Pagination
		$page = 1;
		if ( isset( $assoc_args['page'] ) && is_numeric( $assoc_args['page'] ) ) {
			$page = absint( $assoc_args['page'] );
		}

		$offset = absint( ( $page - 1 ) * $limit );

		// Query
		$items = $wpdb->get_results( $wpdb->prepare( "SELECT SQL_CALC_FOUND_ROWS ID, post_content_filtered, post_date_gmt, post_modified_gmt FROM {$wpdb->posts} WHERE post_type = %s AND post_status = %s ORDER BY post_date ASC LIMIT %d,%d", \Automattic\WP\Cron_Control\Cron_Options_CPT::POST_TYPE, $post_status, $offset, $limit ) );

		// Bail if we don't get results
		if ( ! is_array( $items ) ) {
			\WP_CLI::error( __( 'Problem retrieving events', 'automattic-cron-control' ) );
		}

		// Include total for pagination etc
		$total_items = (int) $wpdb->get_var( 'SELECT FOUND_ROWS()' );

		return compact( 'status', 'limit', 'page', 'offset', 'items', 'total_items' );
	}

	/**
	 * Format event data into something human-readable
	 */
	private function format_events( $events ) {
		$formatted_events = array();

		// Get schedules, for recurrence data
		$schedules = wp_get_schedules();

		// Reformat events
		foreach ( $events as $event ) {
			$row = array(
				'ID'                => '',
				'action'            => '',
				'instance'          => '',
				'next_run_gmt'      => '',
				'next_run_relative' => '',
				'recurrence'        => '',
				'event_args'        => '',
				'created_gmt'       => '',
				'modified_gmt'      => '',
			);

			$formatted_events[] = $row;
		}

		return $formatted_events;
	}
}

\WP_CLI::add_command( 'cron-control-data', 'Automattic\WP\Cron_Control\CLI\Data' );
