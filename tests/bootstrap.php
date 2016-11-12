<?php
/**
 * PHPUnit bootstrap file
 *
 * @package WP_Cron_Control_Revisited
 */

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

	require dirname( dirname( __FILE__ ) ) . '/wp-cron-control-revisited.php';
}
tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );

// Utilities
require_once __DIR__ . '/utils.php';

// Start up the WP testing environment.
require $_tests_dir . '/includes/bootstrap.php';
