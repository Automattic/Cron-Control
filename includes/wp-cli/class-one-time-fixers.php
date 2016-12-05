<?php

namespace Automattic\WP\Cron_Control\CLI;

class One_Time_Fixers extends \WPCOM_VIP_CLI_Command {

	/**
	 * Remove corrupt Cron Control data resulting from initial plugin deployment
	 *
	 * eg.: `wp --allow-root cron-control remove-all-plugin-data`
	 *
	 * @subcommand remove-all-plugin-data
	 */
	public function purge( $args, $assoc_args ) {
		global $wpdb;

		// Are we actually destroying any data?
		$dry_run = true;

		if ( isset( $assoc_args['dry-run'] ) && 'false' === $assoc_args['dry-run'] ) {
			$dry_run = false;
		}

		// Provide some idea of what's going on
		\WP_CLI::line( __( 'CRON CONTROL', 'automattic-cron-control' ) . "\n" );

		$count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_type = %s;", 'a8c_cron_ctrl_event' ) );

		if ( is_numeric( $count ) ) {
			$count = (int) $count;
			\WP_CLI::line( sprintf( __( 'Found %s entries', 'automattic-cron-control' ), number_format_i18n( $count ) ) . "\n\n" );
		} else {
			\WP_CLI::line( __( 'Something went wrong...aborting!', 'automattic-cron-control' ) );
			return;
		}

		// Should we really destroy all this data?
		if ( ! $dry_run ) {
			\WP_CLI::line( __( 'This process will remove all CPT data for the Cron Control plugin', 'automattic-cron-control' ) );
			\WP_CLI::confirm( __( 'Proceed?', 'automattic-cron-control' ) );
			\WP_CLI::line( "\n" . __( 'Starting...', 'automattic-cron-control' ) . "\n" );
		}

		// Work through all items until there are no more
		$items = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_title FROM {$wpdb->posts} WHERE post_type = %s LIMIT 250;", 'a8c_cron_ctrl_event' ) );

		if ( is_array( $items ) && ! empty( $items ) ) {
			foreach ( $items as $item ) {
				\WP_CLI::line( "{$item->ID}, `{$item->post_title}`" );

				if ( ! $dry_run ) {
					\WP_CLI::line( __( 'Removing...', 'automattic-cron-control' ) );
					wp_delete_post( $item->ID, true );
					\WP_CLI::line( '' );
				}
			}
		}

		if ( ! $dry_run ) {
			wp_cache_delete( 'a8c_cron_ctrl_option' );
			\WP_CLI::line( sprintf( __( 'Cleared the %s cache', 'automattic-cron-control' ), 'Cron Control' ) );
		}

		\WP_CLI::line( "\n" . __( 'All done. Note that new entries may have been created as the result of other requests made to the site during this process.', 'automattic-cron-control' ) );
	}
}

\WP_CLI::add_command( 'cron-control', 'Automattic\WP\Cron_Control\CLI\One_Time_Fixers' );
