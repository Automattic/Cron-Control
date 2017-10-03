Cron Control
============

Execute WordPress cron events in parallel, using a custom post type for event storage.

Using REST API endpoints (requires WordPress 4.4+), an event queue is produced and events are triggered.

## PHP Compatibility

Cron Control requires PHP 7 or greater to be able to catch fatal errors triggered by event callbacks. PHP 7 is also required to define arrays in constants (such as for adding "Internal Events"). 

## Event Concurrency

In some circumstances, multiple events with the same action can safely run in parallel. This is usually not the case, largely due to Core's alloptions, but sometimes an event is written in a way that we can support concurrent executions.

To allow concurrency for your event, and to specify the level of concurrency, please hook the `a8c_cron_control_concurrent_event_whitelist` filter as in the following example:

``` php
add_filter( 'a8c_cron_control_concurrent_event_whitelist', function( $wh ) {
	$wh['my_custom_event'] = 2;

	return $wh;
} );
```

## Adding Internal Events

**This should be done sparingly as "Internal Events" bypass certain locks and limits built into the plugin.** Overuse will lead to unexpected resource usage, and likely resource exhaustion.

In `wp-config.php` or a similarly-early and appropriate place, define `CRON_CONTROL_ADDITIONAL_INTERNAL_EVENTS` as an array of arrays like:

```php
define( 'CRON_CONTROL_ADDITIONAL_INTERNAL_EVENTS', array(
	array(
		'schedule' => 'hourly',
		'action'   => 'do_a_thing',
		'callback' => '__return_true',
	),
) );
```

Due to the early loading (to limit additions), the `action` and `callback` generally can't directly reference any Core, plugin, or theme code. Since WordPress uses actions to trigger cron, class methods can be referenced, so long as the class name is not dynamically referenced. For example:

```php
define( 'CRON_CONTROL_ADDITIONAL_INTERNAL_EVENTS', array(
	array(
		'schedule' => 'hourly',
		'action'   => 'do_a_thing',
		'callback' => array( 'Some_Class', 'some_method' ),
	),
) );
```

Take care to reference the full namespace when appropriate.
