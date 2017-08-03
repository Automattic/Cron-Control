<?php

namespace Automattic\WP\Cron_Control\CLI;

/**
 * Commands to manage automatic event execution
 */
class Orchestrate extends \WP_CLI_Command {
	/**
	 * Check the status of automatic event execution
	 *
	 * @subcommand check-status
	 */
	public function get_automatic_execution_status( $args, $assoc_args ) {
		$status = get_option( \Automattic\WP\Cron_Control\Events::DISABLE_RUN_OPTION, 0 );

		switch ( $status ) {
			case 0 :
				$status = __( 'Automatic execution is enabled', 'automattic-cron-control' );
				break;

			case 1 :
				$status = __( 'Automatic execution is disabled indefinitely', 'automattic-cron-control' );
				break;

			default :
				$status = sprintf( __( 'Automatic execution is disabled until %s', 'automattic-cron-control' ), date_i18n( 'Y-m-d H:i:s T', $status ) );
				break;
		}

		\WP_CLI::log( $status );
	}

	/**
	 * Change status of automatic event execution
	 *
	 * When using the Go-based runner, it may be necessary to stop execution for a period, or indefinitely
	 *
	 * @subcommand manage-automatic-execution
	 * @synopsis [--enable] [--disable] [--disable_until=<disable_until>]
	 */
	public function manage_automatic_execution( $args, $assoc_args ) {
		// Update execution status
		$disable_ts = \WP_CLI\Utils\get_flag_value( $assoc_args, 'disable_until', 0 );
		$disable_ts = absint( $disable_ts );

		if ( \WP_CLI\Utils\get_flag_value( $assoc_args, 'enable', false ) ) {
			update_option( \Automattic\WP\Cron_Control\Events::DISABLE_RUN_OPTION, 0 );
			\WP_CLI::success( __( 'Enabled', 'automattic-cron-control' ) );
			return;
		} elseif ( \WP_CLI\Utils\get_flag_value( $assoc_args, 'disable', false ) ) {
			update_option( \Automattic\WP\Cron_Control\Events::DISABLE_RUN_OPTION, 1 );
			\WP_CLI::success( __( 'Disabled', 'automattic-cron-control' ) );
			return;
		} elseif( $disable_ts > 0 ) {
			if ( $disable_ts > time() ) {
				update_option( \Automattic\WP\Cron_Control\Events::DISABLE_RUN_OPTION, $disable_ts );
				\WP_CLI::success( sprintf( __( 'Disabled until %s', 'automattic-cron-control' ), date_i18n( 'Y-m-d H:i:s T', $disable_ts ) ) );
				return;
			} else {
				\WP_CLI::error( __( 'Timestamp is in the past.', 'automattic-cron-control' ) );
			}
		}

		\WP_CLI::error( __( 'Please provide a valid action.', 'automattic-cron-control' ) );
	}
}

\WP_CLI::add_command( 'cron-control orchestrate', 'Automattic\WP\Cron_Control\CLI\Orchestrate' );
