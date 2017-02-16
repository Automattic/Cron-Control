<?php

namespace Automattic\WP\Cron_Control;

class Events_Store extends Singleton {
	/**
	 * PLUGIN SETUP
	 */

	/**
	 * Class properties
	 */
	const TABLE_SUFFIX = 'a8c_cron_ctrl_jobs';

	const DB_VERSION        = 1;
	const DB_VERSION_OPTION = 'a8c_cron_control_db_version';


	/**
	 * Register hooks
	 */
	protected function class_init() {
		// Check that the table exists and is the correct version
		$this->prepare_db();
	}

	/**
	 * Build appropriate table name for this install
	 */
	protected function get_table_name() {
		global $wpdb;

		return $wpdb->base_prefix . self::TABLE_SUFFIX;
	}

	/**
	 * Create the plugin's DB table when necessary
	 */
	protected function prepare_db() {
		// Should be in admin context before using dbDelta
		if ( ! is_admin() ) {
			return;
		}

		// Nothing to do
		if ( (int) get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION ) {
			return;
		}

		// Use Core's method of creating/updating tables
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . '/wp-admin/includes/upgrade.php';
		}

		global $wpdb;

		// Define schema and create the table
		$schema = "CREATE TABLE IF NOT EXISTS `{$this->get_table_name()}` (
			`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,

			`timestamp` bigint(20) unsigned NOT NULL,
			`action` varchar(255) NOT NULL,
			`instance` varchar(32) NOT NULL,

			`args` longtext NOT NULL,
			`schedule` varchar(255) DEFAULT NULL,
			`interval` int unsigned DEFAULT NULL,
			`status` varchar(255) NOT NULL DEFAULT 'pending',

			`created` datetime NOT NULL,
			`last_modified` datetime NOT NULL,

			PRIMARY KEY (`id`),
			UNIQUE KEY `ts_action_instance` (`timestamp`, `action`, `instance`),
			KEY `status` (`status`)
		) ENGINE=InnoDB;\n";

		dbDelta( $schema, true );

		// Confirm that the table was created, and set the option to prevent further updates
		$table_count = count( $wpdb->get_col( "SHOW TABLES LIKE '{$this->get_table_name()}'" ) );

		if ( 1 === $table_count ) {
			update_option( self::DB_VERSION_OPTION, self::DB_VERSION, true );
		} else {
			delete_option( self::DB_VERSION_OPTION );
		}
	}
}

Events_Store::instance();
