<?php

namespace Automattic\WP\Cron_Control\CLI;

/**
 * Manage Cron Control's data, including internal caches
 */
class Data extends \WP_CLI_Command {
	/**
	 * Flush Cron Control's internal caches
	 *
	 * eg.: `wp --allow-root cron-control-data flush-cache`
	 *
	 * @subcommand flush-cache
	 */
	public function purge( $args, $assoc_args ) {
		$flushed = wp_cache_delete( \Automattic\WP\Cron_Control\Cron_Options_CPT::CACHE_KEY );

		if ( $flushed ) {
			\WP_CLI::success( __( 'Internal caches cleared', 'automattic-cron-control' ) );
		} else {
			\WP_CLI::warning( __( 'No caches to clear', 'automattic-cron-control' ) );
		}
	}
}

\WP_CLI::add_command( 'cron-control-data', 'Automattic\WP\Cron_Control\CLI\Data' );
