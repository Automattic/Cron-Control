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
}
