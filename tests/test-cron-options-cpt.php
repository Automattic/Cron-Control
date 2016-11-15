<?php
/**
 * Class WPCCR_Cron_Options_CPT_Test
 *
 * @package WP_Cron_Control_Revisited
 */

/**
 * Sample test case.
 */
class WPCCR_Cron_Options_CPT_Test extends WP_UnitTestCase {

	/**
	 * Custom post type exists
	 */
	function test_cpt_exists() {
		$this->assertTrue( post_type_exists( WP_Cron_Control_Revisited\Cron_Options_CPT::POST_TYPE ) );
	}

	/**
	 * Check that an event is stored properly in a CPT entry
	 */
	function test_events_exist() {
		global $wpdb;

		$event     = WP_Cron_Control_Revisited_Tests\Utils::create_test_event();
		$post_name = sprintf( '%s-%s-%s', $event['timestamp'], md5( $event['action'] ), md5( maybe_serialize( $event['args'] ) ) );

		$entry = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $wpdb->posts WHERE post_type = %s AND post_status = %s AND post_name = %s LIMIT 1", WP_Cron_Control_Revisited\Cron_Options_CPT::POST_TYPE, WP_Cron_Control_Revisited\Cron_Options_CPT::POST_STATUS, $post_name ) );

		$this->assertEquals( count( $entry ), 1 );

		$entry    = array_shift( $entry );
		$instance = maybe_unserialize( $entry->post_content_filtered );

		$this->assertEquals( $event['action'], $instance['action'] );
		$this->assertEquals( md5( maybe_serialize( $event['args'] ) ), $instance['instance'] );
		WP_Cron_Control_Revisited_Tests\Utils::compare_arrays( $event['args'], $instance['args'], $this );
	}

	/**
	 * Check format of filtered array returned from CPT
	 */
	function test_filter_cron_option_get() {
		$event = WP_Cron_Control_Revisited_Tests\Utils::create_test_event();

		$cron = get_option( 'cron' );

		// Core versions the cron option (see `_upgrade_cron_array()`)
		// Without this in the filtered result, all events continually requeue as Core tries to "upgrade" the option
		$this->assertArrayHasKey( 'version', $cron );
		$this->assertEquals( $cron['version'], 2 );

		// Validate the remaining structure
		foreach ( $cron as $timestamp => $timestamp_events ) {
			if ( ! is_numeric( $timestamp ) ) {
				continue;
			}

			foreach ( $timestamp_events as $action => $action_instances ) {
				$this->assertEquals( $action, $event['action'] );

				foreach ( $action_instances as $instance => $instance_args ) {
					$this->assertArrayHasKey( 'schedule', $instance_args );
					$this->assertArrayHasKey( 'args', $instance_args );
				}
			}
		}
	}

	/**
	 * Test that events are unscheduled correctly using Core functions
	 */
	function test_event_unscheduling_using_core_functions() {
		$first_event = WP_Cron_Control_Revisited_Tests\Utils::create_test_event();
		$second_event = WP_Cron_Control_Revisited_Tests\Utils::create_test_event( true );

		$first_event_ts = wp_next_scheduled( $first_event['action'], $first_event['args'] );

		$this->assertEquals( $first_event_ts, $first_event['timestamp'] );

		wp_unschedule_event( $first_event_ts, $first_event['action'], $first_event['args'] );

		$first_event_ts  = wp_next_scheduled( $first_event['action'], $first_event['args'] );
		$second_event_ts = wp_next_scheduled( $second_event['action'], $second_event['args'] );

		$this->assertFalse( $first_event_ts );
		$this->assertEquals( $second_event_ts, $second_event['timestamp'] );

		wp_unschedule_event( $second_event_ts, $second_event['action'], $second_event['args'] );

		$second_event_ts = wp_next_scheduled( $second_event['action'], $second_event['args'] );

		$this->assertFalse( $second_event_ts );
	}
}
