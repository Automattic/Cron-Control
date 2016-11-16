<?php
/**
 * Class WPCCR_Internal_Events_Test
 *
 * @package WP_Cron_Control_Revisited
 */

/**
 * Sample test case.
 */
class WPCCR_Internal_Events_Test extends WP_UnitTestCase {

	/**
	 * Internal events should be scheduled
	 */
	function test_events() {
		Automattic\WP\Cron_Control\Internal_Events::instance()->schedule_internal_events();

		$events = \Automattic\WP\Cron_Control\collapse_events_array( get_option( 'cron' ) );

		// Check that the plugin scheduled the expected number of events
		$this->assertEquals( count( $events ), 3 );

		// Confirm that the scheduled jobs came from the Internal Events class
		foreach ( $events as $event ) {
			$this->assertTrue( \Automattic\WP\Cron_Control\is_internal_event( $event['action'] ) );
		}
	}
}
