<?php

namespace Automattic\WP\Cron_Control\Tests;

use Automattic\WP\Cron_Control\Lock;

class Lock_Tests extends \WP_UnitTestCase {
	private $locks = array(
		'concurrent-acquisition',
		'rejected-admission',
		'stale-lock',
		'old-generation-cleanup',
		'recreated-lock',
		'reset-lock',
	);

	public function setUp(): void {
		parent::setUp();

		foreach ( $this->locks as $lock ) {
			Lock::reset_lock( $lock );
		}
	}

	public function tearDown(): void {
		foreach ( $this->locks as $lock ) {
			Lock::reset_lock( $lock );
		}

		parent::tearDown();
	}

	public function test_concurrent_acquisition_with_limit_one_admits_one_worker() {
		$lock = 'concurrent-acquisition';

		$this->assertTrue( Lock::check_lock( $lock, 1 ) );
		$this->assertFalse( Lock::check_lock( $lock, 1 ) );
		$this->assertSame( 1, Lock::get_lock_value( $lock ) );
	}

	public function test_rejected_admission_does_not_change_lock_value() {
		$lock = 'rejected-admission';

		$this->assertTrue( Lock::check_lock( $lock, 1 ) );
		$this->assertFalse( Lock::check_lock( $lock, 1 ) );
		$this->assertSame( 1, Lock::get_lock_value( $lock ) );
	}

	public function test_stale_lock_recovery_records_the_new_admission() {
		global $wpdb;

		$lock = 'stale-lock';
		$wpdb->update(
			$wpdb->options,
			array( 'option_value' => '1:' . ( time() - MINUTE_IN_SECONDS ) . ':1' ),
			array( 'option_name' => 'a8ccc_lock_stale-lock' )
		);

		$this->assertTrue( Lock::check_lock( $lock, 1, 1 ) );
		$this->assertSame( 1, Lock::get_lock_value( $lock ) );
		$this->assertGreaterThan( time() - 2, Lock::get_lock_timestamp( $lock ) );
	}

	public function test_old_generation_cleanup_does_not_release_recovered_lock() {
		global $wpdb;

		$lock = 'old-generation-cleanup';
		$this->assertTrue( Lock::check_lock( $lock, 1 ) );
		$wpdb->update(
			$wpdb->options,
			array( 'option_value' => '1:' . ( time() - MINUTE_IN_SECONDS ) . ':1' ),
			array( 'option_name' => 'a8ccc_lock_old-generation-cleanup' )
		);
		$this->assertTrue( Lock::check_lock( $lock, 1, 1 ) );
		$state = explode( ':', $wpdb->get_var( "SELECT `option_value` FROM `$wpdb->options` WHERE `option_name` = 'a8ccc_lock_old-generation-cleanup'" ) );

		$reflection = new \ReflectionProperty( Lock::class, 'acquired_locks' );
		$reflection->setAccessible( true );
		$reflection->setValue(
			array(
				$lock => array( (int) $state[2], 1 ),
			)
		);

		$this->assertTrue( Lock::free_lock( $lock ) );
		$this->assertSame( 1, Lock::get_lock_value( $lock ) );
	}

	public function test_old_generation_cleanup_does_not_release_a_recreated_lock() {
		global $wpdb;

		$lock = 'recreated-lock';
		$this->assertTrue( Lock::check_lock( $lock, 1 ) );
		$state          = explode( ':', $wpdb->get_var( "SELECT `option_value` FROM `$wpdb->options` WHERE `option_name` = 'a8ccc_lock_recreated-lock'" ) );
		$old_generation = (int) $state[2];
		$this->assertTrue( Lock::free_lock( $lock ) );
		$this->assertNull( $wpdb->get_var( "SELECT `option_value` FROM `$wpdb->options` WHERE `option_name` = 'a8ccc_lock_recreated-lock'" ) );

		$this->assertTrue( Lock::check_lock( $lock, 1 ) );
		$state                  = explode( ':', $wpdb->get_var( "SELECT `option_value` FROM `$wpdb->options` WHERE `option_name` = 'a8ccc_lock_recreated-lock'" ) );
		$replacement_state      = implode( ':', $state );
		$replacement_generation = (int) $state[2];

		$reflection = new \ReflectionProperty( Lock::class, 'acquired_locks' );
		$reflection->setAccessible( true );
		$reflection->setValue(
			array(
				$lock => array( $replacement_generation, $old_generation ),
			)
		);

		$this->assertTrue( Lock::free_lock( $lock ) );
		$this->assertSame( $replacement_state, $wpdb->get_var( "SELECT `option_value` FROM `$wpdb->options` WHERE `option_name` = 'a8ccc_lock_recreated-lock'" ) );
	}

	public function test_old_generation_cleanup_does_not_release_a_reset_lock() {
		global $wpdb;

		$lock = 'reset-lock';
		$this->assertTrue( Lock::check_lock( $lock, 1 ) );
		$state          = explode( ':', $wpdb->get_var( "SELECT `option_value` FROM `$wpdb->options` WHERE `option_name` = 'a8ccc_lock_reset-lock'" ) );
		$old_generation = (int) $state[2];
		$this->assertTrue( Lock::reset_lock( $lock ) );
		$this->assertTrue( Lock::check_lock( $lock, 1 ) );
		$state                  = explode( ':', $wpdb->get_var( "SELECT `option_value` FROM `$wpdb->options` WHERE `option_name` = 'a8ccc_lock_reset-lock'" ) );
		$replacement_state      = implode( ':', $state );
		$replacement_generation = (int) $state[2];

		$reflection = new \ReflectionProperty( Lock::class, 'acquired_locks' );
		$reflection->setAccessible( true );
		$reflection->setValue(
			array(
				$lock => array( $replacement_generation, $old_generation ),
			)
		);

		$this->assertTrue( Lock::free_lock( $lock ) );
		$this->assertSame( $replacement_state, $wpdb->get_var( "SELECT `option_value` FROM `$wpdb->options` WHERE `option_name` = 'a8ccc_lock_reset-lock'" ) );
	}
}
