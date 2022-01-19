<?php
/**
 * PHPUnit bootstrap file
 *
 * @package a8c_Cron_Control
 */

use Automattic\WP\Cron_Control;

$_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $_tests_dir ) {
	$_tests_dir = '/tmp/wordpress-tests-lib';
}

// Give access to tests_add_filter() function.
require_once $_tests_dir . '/includes/functions.php';

/**
 * Manually load the plugin being tested.
 */
function _manually_load_plugin() {
	define( 'WP_CRON_CONTROL_SECRET', 'testtesttest' );

	define(
		'CRON_CONTROL_ADDITIONAL_INTERNAL_EVENTS',
		array(
			array(
				'schedule' => 'hourly',
				'action'   => 'cron_control_additional_internal_event',
				'callback' => '__return_true',
			),
		)
	);

	require dirname( dirname( __FILE__ ) ) . '/cron-control.php';

	// Plugin loads after `wp_install()` is called, so we compensate.
	Cron_Control\Events_Store::instance()->install();
	Cron_Control\register_adapter_hooks();
}
tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );

// Utilities.
require_once __DIR__ . '/utils.php';

// Start up the WP testing environment.
require $_tests_dir . '/includes/bootstrap.php';
