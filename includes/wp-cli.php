<?php

namespace Automattic\WP\Cron_Control;

if ( defined( '\WP_CLI' ) && \WP_CLI ) {
	require __DIR__ . '/wp-cli/class-one-time-fixers.php';
}
