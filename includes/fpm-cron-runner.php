<?php

//
// This file is being templated and managed by Kubernetes wpvip-operator.
//

//
// An init container will be needed that runs something along the lines of
// `cp /usr/local/bin/wp /tmp/wp.phar`
// `phar extract -f /usr/local/bin/wp.phar $_POST['WP_CLI_ROOT']`
//

namespace WPCLI\FPM;

$allowed_subcommands = array(
	'cron-control orchestrate runner-only get-info',
	'cron-control orchestrate runner-only list-due-batch',
	'cron-control orchestrate runner-only run',
	'cron-control orchestrate sites heartbeat',
	'cron-control orchestrate sites list',
	'site list',
);

$cli_args = $_POST['subcommands'];
unset( $_POST['subcommands'] );
$subcommand = implode( ' ', $cli_args );

if ! in_array( $subcommand, $allowed_subcommands, true ) {
	throw new Exception( '"' . $subcommand . '" not allowed via FPM.' );
} else {

	// Place the remainer of $_POST in an array and strip placeholders
	// I don't really even know if this is needed or not.
	foreach ( $_POST as $key => $value ) {
		if ( 'true' === $value ) {
			array_push( $cli_args, $key )
		} else {
			$cli_args[ $key ] = $value;
		}
	}

	// phpcs:ignore
	$GLOBALS['argv'] = $cli_args;
	// phpcs:ignore
	$_SERVER['argv'] = $cli_args;

	define( 'STDIN', fopen( 'php://input', 'r' ) );
	define( 'STDOUT', fopen( 'php://output', 'w' ) );
	define( 'STDERR', fopen( 'php://output', 'w' ) );

	require_once WP_CLI_ROOT . '/php/wp-cli.php';
}
