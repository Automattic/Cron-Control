<?php
/**
 * Class Lock_Tests
 *
 * @package Automattic_Cron_Control
 */

namespace Automattic\WP\Cron_Control\Tests;

use Automattic\WP\Cron_Control;

/**
 * Lock tests
 */
class Lock_Tests extends \WP_UnitTestCase {
	/**
	 * Prepare test environment
	 */
	function setUp() {
		parent::setUp();
	}

	/**
	 * Clean up after our tests
	 */
	function tearDown() {
		parent::tearDown();
	}

	/**
	 * Test a single-concurrency lock
	 */
	function test_single_concurrency_lock() {
		$lock_name = 'test-lock';
		$limit     = 1;

		Cron_Control\Lock::prime_lock( $lock_name );

		$can_run = Cron_Control\Lock::check_lock( $lock_name, $limit );

		$this->assertEquals( true, $can_run );

		$can_run = Cron_Control\Lock::check_lock( $lock_name, $limit );

		$this->assertEquals( false, $can_run );

		Cron_Control\Lock::free_lock( $lock_name );

		$can_run = Cron_Control\Lock::check_lock( $lock_name, $limit );

		$this->assertEquals( true, $can_run );

		Cron_Control\Lock::reset_lock( $lock_name );

		$can_run = Cron_Control\Lock::check_lock( $lock_name, $limit );

		$this->assertEquals( true, $can_run );
	}

	/**
	 * Test a multiple-concurrency lock
	 */
	function test_multiple_concurrency_lock() {
		$lock_name = 'test-lock';
		$limit     = 5;

		Cron_Control\Lock::prime_lock( $lock_name );

		$can_run = Cron_Control\Lock::check_lock( $lock_name, $limit );

		$this->assertEquals( true, $can_run );
		$this->assertEquals( 1, Cron_Control\Lock::get_lock_value( $lock_name ) );

		for ( $i = 0; $i < $limit; $i++ ) {
			$can_run = Cron_Control\Lock::check_lock( $lock_name, $limit );
		}

		$this->assertEquals( false, $can_run );
		$this->assertEquals( $limit, Cron_Control\Lock::get_lock_value( $lock_name ) );

		Cron_Control\Lock::free_lock( $lock_name );

		$can_run = Cron_Control\Lock::check_lock( $lock_name, $limit );

		$this->assertEquals( true, $can_run );

		Cron_Control\Lock::reset_lock( $lock_name );

		$can_run = Cron_Control\Lock::check_lock( $lock_name, $limit );

		$this->assertEquals( true, $can_run );
	}
}
