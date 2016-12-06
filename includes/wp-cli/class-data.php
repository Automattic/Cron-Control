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
	 * @subcommand show-events
	 */
	public function show_events( $args, $assoc_args ) {
		$events = $this->get_events( $args, $assoc_args );
		$this->format_events( $events );
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
		$total_items = $wpdb->get_var( 'SELECT FOUND_ROWS()' );

		return compact( 'status', 'limit', 'page', 'offset', 'items', 'total_items' );
	}

	/**
	 *
	 */
	private function format_events( $events ) {}
}

\WP_CLI::add_command( 'cron-control-data', 'Automattic\WP\Cron_Control\CLI\Data' );
