<?php

namespace Automattic\WP\Cron_Control;

class Events_Store extends Singleton {
	/**
	 * PLUGIN SETUP
	 */

	/**
	 * Class properties
	 */
	const TABLE_SUFFIX = 'cron_control';

	/**
	 * Register hooks
	 */
	protected function class_init() {}
}

Events_Store::instance();
