<?php

namespace Automattic\WP\Cron_Control\CLI;

/**
 *
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
}

\WP_CLI::add_command( 'cron-control orchestrate', 'Automattic\WP\Cron_Control\CLI\Orchestrate' );
