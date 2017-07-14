<?php

namespace Automattic\WP\Cron_Control\CLI;

/**
 * Commands used by the Go-based runner to execute events
 */
class Orchestrate extends \WP_CLI_Command {
	/**
	 * List the next set of events to run; meant for Runner
	 *
	 * Will not be all events, just those atop the curated queue
	 *
	 * Not intended for human use, rather it powers the Go-based Runner. Use the `events list` command instead.
	 *
	 * @subcommand list-due-batch
	 */
	public function list_due_now( $args, $assoc_args ) {
		if ( 0 !== \Automattic\WP\Cron_Control\Events::instance()->run_disabled() ) {
			\WP_CLI::error( __( 'Automatic event execution is disabled', 'automattic-cron-control' ) );
		}

		$events = \Automattic\WP\Cron_Control\Events::instance()->get_events();

		$format = \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );

		\WP_CLI\Utils\format_items( $format, $events['events'], array(
			'timestamp',
			'action',
			'instance',
		) );
	}

	/**
	 * Run a given event; meant for Runner
	 *
	 * Not intended for human use, rather it powers the Go-based Runner. Use the `events run` command instead.
	 *
	 * @subcommand run
	 * @synopsis --timestamp=<timestamp> --action=<action-hashed> --instance=<instance>
	 */
	public function run_event( $args, $assoc_args ) {
		if ( 0 !== \Automattic\WP\Cron_Control\Events::instance()->run_disabled() ) {
			\WP_CLI::error( __( 'Automatic event execution is disabled', 'automattic-cron-control' ) );
		}

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

	/**
	 * Get some details needed to execute events; meant for Runner
	 *
	 * Not intended for human use, rather it powers the Go-based Runner. Use the `orchestrate manage-automatic-execution` command instead.
	 *
	 * @subcommand get-info
	 */
	public function get_info( $args, $assoc_args ) {
		$info = array(
			array(
				'multisite' => is_multisite() ? 1 : 0,
				'siteurl'   => site_url(),
				'disabled'  => \Automattic\WP\Cron_Control\Events::instance()->run_disabled(),
			),
		);

		$format = \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );

		\WP_CLI\Utils\format_items( $format, $info, array_keys( $info[0] ) );
	}

	/**
	 * Check and change status of automatic event execution
	 *
	 * When using the Go-based runner, it may be necessary to stop execution for a period, or indefinitely
	 *
	 * @subcommand manage-automatic-execution
	 * @synopsis [--enable] [--disable] [--disable_until=<disable_until>]
	 */
	public function toggle_event_execution( $args, $assoc_args ) {
		// Update execution status
		$disable_ts = \WP_CLI\Utils\get_flag_value( $assoc_args, 'disable_until', 0 );
		$disable_ts = absint( $disable_ts );

		if ( \WP_CLI\Utils\get_flag_value( $assoc_args, 'enable', false ) ) {
			update_option( \Automattic\WP\Cron_Control\Events::DISABLE_RUN_OPTION, 0 );
			\WP_CLI::success( 'Enabled' );
			return;
		} elseif ( \WP_CLI\Utils\get_flag_value( $assoc_args, 'disable', false ) ) {
			update_option( \Automattic\WP\Cron_Control\Events::DISABLE_RUN_OPTION, 1 );
			\WP_CLI::success( 'Disabled' );
			return;
		} elseif( $disable_ts > 0 ) {
			if ( $disable_ts > time() ) {
				update_option( \Automattic\WP\Cron_Control\Events::DISABLE_RUN_OPTION, $disable_ts );
				\WP_CLI::success( sprintf( 'Disabled until %s', date( 'Y-m-d H:i:s T', $disable_ts ) ) );
				return;
			} else {
				\WP_CLI::error( 'Timestamp is in the past.' );
			}
		}

		// Display existing status
		$status = get_option( \Automattic\WP\Cron_Control\Events::DISABLE_RUN_OPTION, 0 );

		switch ( $status ) {
			case 0 :
				$status = 'Automatic execution is enabled';
				break;

			case 1 :
				$status = 'Automatic execution is disabled indefinitely';
				break;

			default :
				$status = sprintf( 'Automatic execution is disabled until %s', date( 'Y-m-d H:i:s T', $status ) );
				break;
		}

		\WP_CLI::log( $status );
	}
}

\WP_CLI::add_command( 'cron-control orchestrate runner', 'Automattic\WP\Cron_Control\CLI\Orchestrate' );
