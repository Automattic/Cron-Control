<?php

namespace Automattic\WP\Cron_Control\CLI;

use Automattic\WP\Cron_Control;

/**
 *
 */
class Orchestrate extends \WP_CLI_Command {
	/**
	 *
	 *
	 * @subcommand do
	 */
	public function do_orchestration( $args, $assoc_args ) {
		\WP_CLI::log( 'Hi' );
	}
}

\WP_CLI::add_command( 'cron-control orchestrate', 'Automattic\WP\Cron_Control\CLI\Orchestrate' );
