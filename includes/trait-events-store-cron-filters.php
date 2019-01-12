<?php
/**
 * Event storage for WP 5.1 and later.
 *
 * @package a8c_Cron_Control
 */

namespace Automattic\WP\Cron_Control;

/**
 * Trait Option_Intercept
 */
trait Events_Store_Cron_Filters {
	/**
	 * Register hooks to intercept events before storage.
	 */
	protected function register_core_cron_filters() {}
}
