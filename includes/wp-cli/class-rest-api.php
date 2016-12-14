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
		\WP_CLI::warning( 'Queue goes here :P' );
	}
}

\WP_CLI::add_command( 'cron-control rest-api', 'Automattic\WP\Cron_Control\CLI\REST_API' );
