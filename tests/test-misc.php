<?php
/**
 * Class WPCCR_Misc_Test
 *
 * @package WP_Cron_Control_Revisited
 */

/**
 * Sample test case.
 */
class WPCCR_Misc_Test extends WP_UnitTestCase {

	/**
	 * Expected values for certain constants
	 */
	function test_constants() {
		$this->assertTrue( defined( 'DISABLE_WP_CRON' ) );
		$this->assertTrue( defined( 'ALTERNATE_WP_CRON' ) );

		$this->assertTrue( constant( 'DISABLE_WP_CRON' ) );
		$this->assertFalse( constant( 'ALTERNATE_WP_CRON' ) );
	}
}
