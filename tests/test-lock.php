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

		Cron_Control\Lock::prime_lock( $lock_name );

		$should_be_free = Cron_Control\Lock::check_lock( $lock_name, 1 );

		$this->assertEquals( true, $should_be_free );

		$should_be_locked = Cron_Control\Lock::check_lock( $lock_name, 1 );

		$this->assertEquals( false, $should_be_locked );

		Cron_Control\Lock::free_lock( $lock_name );

		$should_be_free = Cron_Control\Lock::check_lock( $lock_name, 1 );

		$this->assertEquals( true, $should_be_free );

		Cron_Control\Lock::reset_lock( $lock_name );

		$should_be_free = Cron_Control\Lock::check_lock( $lock_name, 1 );

		$this->assertEquals( true, $should_be_free );
	}
}
