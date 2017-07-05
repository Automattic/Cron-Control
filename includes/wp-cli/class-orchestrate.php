<?php

namespace Automattic\WP\Cron_Control\CLI;

/**
 * Commands used by the Go-based runner to execute events
 */
class Orchestrate extends \WP_CLI_Command {
	/**
	 * List the next set of events to run
	 *
	 * Will not be all events, just those atop the curated queue
	 *
	 * @subcommand list-due-batch
	 */
	public function list_due_now( $args, $assoc_args ) {
		$events = \Automattic\WP\Cron_Control\Events::instance()->get_events();

		$format = \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );

		\WP_CLI\Utils\format_items( $format, $events['events'], array(
			'timestamp',
			'action',
			'instance',
		) );
	}

	/**
	 * Run a given event
	 *
	 * @subcommand run
	 * @synopsis --timestamp=<timestamp> --action=<action-hashed> --instance=<instance>
	 */
	public function run_event( $args, $assoc_args ) {
		$timestamp = \WP_CLI\Utils\get_flag_value( $assoc_args, 'timestamp', null );
		$action    = \WP_CLI\Utils\get_flag_value( $assoc_args, 'action',    null );
		$instance  = \WP_CLI\Utils\get_flag_value( $assoc_args, 'instance',  null );

		if ( ! is_numeric( $timestamp ) || ! is_string( $action ) || ! is_string( $instance ) ) {
			\WP_CLI::error( __( 'Invalid event arguments', 'automattic-cron-control' ) );
		}

		// Prepare environment
		if ( ! defined( 'DOING_CRON' ) ) {
			define( 'DOING_CRON', true );
		}

		$now = time();
		if ( $timestamp > $now ) {
			\WP_CLI::error( sprintf( __( 'This event is not scheduled to run until %1$s GMT', 'automattic-cron-control' ), date( TIME_FORMAT, $timestamp ) ) );
		}

		// Run the event
		$run = \Automattic\WP\Cron_Control\run_event( $timestamp, $action, $instance );

		if ( is_wp_error( $run ) ) {
			\WP_CLI::error( $run->get_error_message() );
		} elseif ( is_array( $run ) && isset( $run['success'] ) && true === $run['success'] ) {
			\WP_CLI::success( $run['message'] );
		} else {
			\WP_CLI::error( $run['message'] );
		}
	}
}

\WP_CLI::add_command( 'cron-control orchestrate', 'Automattic\WP\Cron_Control\CLI\Orchestrate' );
