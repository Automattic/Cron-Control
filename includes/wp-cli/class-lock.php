<?php

namespace Automattic\WP\Cron_Control\CLI;

/**
 * Manage Cron Control's internal locks
 */
class Lock extends \WP_CLI_Command {
	/**
	 * Check value of execution lock
	 *
	 * Not to exceed `JOB_CONCURRENCY_LIMIT`
	 *
	 * @subcommand check-run-lock
	 */
	public function check_run_lock( $args, $assoc_args ) {
		\WP_CLI::line( __( 'This lock limits the number of concurrent events that are run.', 'automattic-cron-control' ) . "\n" );

		\WP_CLI::line( sprintf( __( 'Maximum: %s', 'automattic-cron-control' ), number_format_i18n( \Automattic\WP\Cron_Control\JOB_CONCURRENCY_LIMIT ) ) . "\n" );

		$lock      = \Automattic\WP\Cron_Control\Lock::get_lock_value( \Automattic\WP\Cron_Control\Events::LOCK );
		$timestamp = \Automattic\WP\Cron_Control\Lock::get_lock_timestamp( \Automattic\WP\Cron_Control\Events::LOCK );

		\WP_CLI::line( sprintf( __( 'Current value: %s', 'automattic-cron-control' ), number_format_i18n( $lock ) ) );
		\WP_CLI::line( sprintf( __( 'Lock expiration: %s GMT', 'automattic-cron-control' ), date( TIME_FORMAT, $timestamp ) ) );
	}
}

\WP_CLI::add_command( 'cron-control locks', 'Automattic\WP\Cron_Control\CLI\Lock' );
