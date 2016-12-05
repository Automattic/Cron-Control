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
		\WP_CLI::line( 'CRON CONTROL' );
		\WP_CLI::line( "This process will remove all CPT data for the Cron Control plugin\n" );

		global $wpdb;

		$items = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_title FROM {$wpdb->posts} WHERE post_type = %s LIMIT 250;", 'a8c_cron_ctrl_event' ) );

		if ( is_array( $items ) && ! empty( $items ) ) {
			foreach ( $items as $item ) {
				\WP_CLI::line( "{$item->ID}, `{$item->post_title}`" );

				if ( isset( $assoc_args['dry-run'] ) && 'false' === $assoc_args['dry-run'] ) {
					\WP_CLI::line( 'Removing...' );
					wp_delete_post( $item->ID, true );
					\WP_CLI::line( '' );
				}
			}
		}

		if ( isset( $assoc_args['dry-run'] ) && 'false' === $assoc_args['dry-run'] ) {
			wp_cache_delete( 'a8c_cron_ctrl_option' );
			\WP_CLI::line( "Cleared Cron Control cache\n" );
		}

		\WP_CLI::line( "\nAll done" );
	}
}

\WP_CLI::add_command( 'cron-control', 'Automattic\WP\Cron_Control\CLI\One_Time_Fixers' );
