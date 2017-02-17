<?php
/**
 * Class Misc_Tests
 *
 * @package Automattic_Cron_Control
 */

namespace Automattic\WP\Cron_Control\Tests;

/**
 * Sample test case.
 */
class Misc_Tests extends \WP_UnitTestCase {
	/**
	 * Prepare test environment
	 */
	function setUp() {
		parent::setUp();

		// make sure the schedule is clear
		Utils::reset_events_store();
	}

	/**
	 * Clean up after our tests
	 */
	function tearDown() {
		// make sure the schedule is clear
		Utils::reset_events_store();

		parent::tearDown();
	}

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
