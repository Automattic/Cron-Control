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
		$post_name = sprintf( '%s-%s-%s', $event['timestamp'], md5( $event['action'] ), md5( serialize( $event['args'] ) ) );

		$entry = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $wpdb->posts WHERE post_type = %s AND post_status = %s AND post_name = %s LIMIT 1", WP_Cron_Control_Revisited\Cron_Options_CPT::POST_TYPE, WP_Cron_Control_Revisited\Cron_Options_CPT::POST_STATUS, $post_name ) );

		$this->assertEquals( count( $entry ), 1 );

		$entry    = array_shift( $entry );
		$instance = maybe_unserialize( $entry->post_content_filtered );

		$this->assertEquals( $event['action'], $instance['action'] );
		$this->assertEquals( md5( serialize( $event['args'] ) ), $instance['instance'] );
		WP_Cron_Control_Revisited_Tests\Utils::compare_arrays( $event['args'], $instance['args'], $this );
	}
}
