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
}

\WP_CLI::add_command( 'cron-control-data', 'Automattic\WP\Cron_Control\CLI\Data' );
