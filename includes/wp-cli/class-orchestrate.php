<?php

namespace Automattic\WP\Cron_Control\CLI;

/**
 * Commands to manage automatic event execution
 */
class Orchestrate extends \WP_CLI_Command {
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

\WP_CLI::add_command( 'cron-control orchestrate', 'Automattic\WP\Cron_Control\CLI\Orchestrate' );
