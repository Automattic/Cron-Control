<?php
/** Each contender runs with its own database connection and lock state. */

$tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $tests_dir ) {
	$tests_dir = '/tmp/wordpress-tests-lib';
}
require $tests_dir . '/wp-tests-config.php';
require ABSPATH . 'wp-settings.php';
require_once dirname( __DIR__, 3 ) . '/includes/constants.php';
require_once dirname( __DIR__, 3 ) . '/includes/class-lock.php';

$lock = $argv[1];
$barrier = $argv[2];
echo "ready\n";
flush();
while ( ! file_exists( $barrier ) ) {
	usleep( 1000 );
}

$limit = isset( $argv[3] ) ? (int) $argv[3] : 1;
$acquired = \Automattic\WP\Cron_Control\Lock::check_lock( $lock, $limit );
echo $acquired ? "acquired\n" : "rejected\n";
flush();
fgets( STDIN );
if ( $acquired ) {
	$released = \Automattic\WP\Cron_Control\Lock::free_lock( $lock );
	echo $released ? "released\n" : "release-failed\n";
} else {
	echo "done\n";
}
flush();
