<?php

namespace Automattic\WP\Cron_Control\CLI;

/**
 * Manage Cron Control's internal locks
 */
class Lock extends \WP_CLI_Command {
	/**
	 * Manage the lock that limits concurrent job executions
	 *
	 * @subcommand run-lock
	 * @synopsis [--reset]
	 */
	public function run_lock( $args, $assoc_args ) {
		// Output information about the lock
		\WP_CLI::line( __( 'This lock limits the number of concurrent events that are run.', 'automattic-cron-control' ) . "\n" );

		\WP_CLI::line( sprintf( __( 'Maximum: %s', 'automattic-cron-control' ), number_format_i18n( \Automattic\WP\Cron_Control\JOB_CONCURRENCY_LIMIT ) ) . "\n" );

		// Reset requested
		if ( isset( $assoc_args['reset'] ) ) {
			\WP_CLI::warning( __( 'Resetting lock', 'automattic-cron-control' ) . "\n" );

			$lock      = \Automattic\WP\Cron_Control\Lock::get_lock_value( \Automattic\WP\Cron_Control\Events::LOCK );
			$timestamp = \Automattic\WP\Cron_Control\Lock::get_lock_timestamp( \Automattic\WP\Cron_Control\Events::LOCK );

			\WP_CLI::line( sprintf( __( 'Previous value: %s', 'automattic-cron-control' ), number_format_i18n( $lock ) ) );
			\WP_CLI::line( sprintf( __( 'Previous lock expiration: %s GMT', 'automattic-cron-control' ), date( TIME_FORMAT, $timestamp ) ) . "\n" );

			\Automattic\WP\Cron_Control\Lock::reset_lock( \Automattic\WP\Cron_Control\Events::LOCK );
			\WP_CLI::success( __( 'Lock reset', 'automattic-cron-control' ) . "\n" );
			\WP_CLI::line( __( 'New lock values:', 'automattic-cron-control' ) );
		}

		// Output lock state
		$lock      = \Automattic\WP\Cron_Control\Lock::get_lock_value( \Automattic\WP\Cron_Control\Events::LOCK );
		$timestamp = \Automattic\WP\Cron_Control\Lock::get_lock_timestamp( \Automattic\WP\Cron_Control\Events::LOCK );

		\WP_CLI::line( sprintf( __( 'Current value: %s', 'automattic-cron-control' ), number_format_i18n( $lock ) ) );
		\WP_CLI::line( sprintf( __( 'Lock expiration: %s GMT', 'automattic-cron-control' ), date( TIME_FORMAT, $timestamp ) ) );
	}
}

\WP_CLI::add_command( 'cron-control locks', 'Automattic\WP\Cron_Control\CLI\Lock' );
