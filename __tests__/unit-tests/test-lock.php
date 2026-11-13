<?php

namespace Automattic\WP\Cron_Control\Tests;

use Automattic\WP\Cron_Control\Lock;

class Lock_Tests extends \WP_UnitTestCase {
	/** @dataProvider concurrency_limits */
	public function test_simultaneous_workers_respect_limit( $limit ) {
		if ( ! function_exists( 'proc_open' ) ) {
			$this->markTestSkipped( 'Process creation is unavailable.' );
		}
		$lock = 'contention-' . wp_generate_uuid4();
		$barrier = tempnam( sys_get_temp_dir(), 'cron-lock-' );
		$this->assertNotFalse( $barrier );
		unlink( $barrier );
		$workers = array();
		try {
			for ( $i = 0; $i < 4; ++$i ) {
				$process = proc_open(
					array( PHP_BINARY, __DIR__ . '/fixtures/lock-worker.php', $lock, $barrier, (string) $limit ),
					array( array( 'pipe', 'r' ), array( 'pipe', 'w' ), array( 'pipe', 'w' ) ),
					$pipes
				);
				$this->assertIsResource( $process );
				$workers[] = array( $process, $pipes );
				stream_set_timeout( $pipes[1], 30 );
			}
			foreach ( $workers as $worker ) {
				$this->assertSame( "ready\n", fgets( $worker[1][1] ) );
			}
			touch( $barrier );
			$results = array();
			foreach ( $workers as $worker ) {
				$results[] = trim( (string) fgets( $worker[1][1] ) );
			}
			$this->assertSame( $limit, count( array_filter( $results, fn( $result ) => 'acquired' === $result ) ) );
			$this->assertSame( 4 - $limit, count( array_filter( $results, fn( $result ) => 'rejected' === $result ) ) );
			foreach ( $workers as $worker ) {
				fwrite( $worker[1][0], "\n" );
			}
			$completed = array();
			foreach ( $workers as $worker ) {
				$completed[] = trim( (string) fgets( $worker[1][1] ) );
			}
			$this->assertSame( $limit, count( array_filter( $completed, fn( $result ) => 'released' === $result ) ) );
			$this->assertSame( 4 - $limit, count( array_filter( $completed, fn( $result ) => 'done' === $result ) ) );
		} finally {
			foreach ( $workers as $worker ) {
				fclose( $worker[1][0] );
				fclose( $worker[1][1] );
				fclose( $worker[1][2] );
				proc_terminate( $worker[0] );
				proc_close( $worker[0] );
			}
			if ( file_exists( $barrier ) ) {
				unlink( $barrier );
			}
		}
	}

	public function concurrency_limits() {
		return array( array( 1 ), array( 3 ) );
	}

	public function test_limits_and_release() {
		global $wpdb;
		$lock = 'limits-' . wp_generate_uuid4();
		$this->assertFalse( Lock::check_lock( $lock, 0 ) );
		$this->assertTrue( Lock::check_lock( $lock, 2 ) );
		$this->assertTrue( Lock::check_lock( $lock, 2 ) );
		$this->assertFalse( Lock::check_lock( $lock, 2 ) );
		$this->assertFalse( Lock::check_lock( $lock, 1 ) );
		$this->assertSame( 2, Lock::get_lock_value( $lock ) );
		$this->assertTrue( Lock::free_lock( $lock ) );
		$this->assertSame( 1, Lock::get_lock_value( $lock ) );
		$this->assertTrue( Lock::free_lock( $lock ) );
		$this->assertSame( 0, Lock::get_lock_value( $lock ) );
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM `$wpdb->options` WHERE option_name = %s", 'a8ccc_lock_' . $lock ) ) );
	}

	public function test_rejection_does_not_change_state() {
		global $wpdb;
		$lock = 'rejection-' . wp_generate_uuid4();
		$this->assertTrue( Lock::check_lock( $lock, 1 ) );
		$before = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM `$wpdb->options` WHERE option_name = %s", 'a8ccc_lock_' . $lock ) );
		$this->assertFalse( Lock::check_lock( $lock, 1 ) );
		$this->assertSame( $before, $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM `$wpdb->options` WHERE option_name = %s", 'a8ccc_lock_' . $lock ) ) );
		$this->assertTrue( Lock::free_lock( $lock ) );
	}

	public function test_expired_owner_is_recovered_while_another_owner_is_active() {
		global $wpdb;
		$lock = 'stale-' . wp_generate_uuid4();
		$this->assertTrue( Lock::check_lock( $lock, 2 ) );
		$this->assertTrue( Lock::check_lock( $lock, 2 ) );
		$key = 'a8ccc_lock_' . $lock;
		$state = json_decode( $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM `$wpdb->options` WHERE option_name = %s", $key ) ), true );
		$owners = array_keys( $state['owners'] );
		$state['owners'][ $owners[0] ] = time() - 1;
		$wpdb->update( $wpdb->options, array( 'option_value' => wp_json_encode( $state ) ), array( 'option_name' => $key ) );
		$this->assertTrue( Lock::check_lock( $lock, 2 ) );
		$this->assertSame( 2, Lock::get_lock_value( $lock ) );
		$this->assertTrue( Lock::free_lock( $lock ) );
		$this->assertSame( 1, Lock::get_lock_value( $lock ) );
		$this->assertTrue( Lock::free_lock( $lock ) );
		$this->assertTrue( Lock::free_lock( $lock ) );
		$this->assertSame( 0, Lock::get_lock_value( $lock ) );
	}

	public function test_expired_owner_cannot_release_replacement() {
		global $wpdb;
		$lock = 'replaced-' . wp_generate_uuid4();
		$key = 'a8ccc_lock_' . $lock;
		$this->assertTrue( Lock::check_lock( $lock, 1 ) );
		$state = json_decode( $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM `$wpdb->options` WHERE option_name = %s", $key ) ), true );
		$old_owner = array_key_first( $state['owners'] );
		$state['owners'][ $old_owner ] = time() - 1;
		$wpdb->update( $wpdb->options, array( 'option_value' => wp_json_encode( $state ) ), array( 'option_name' => $key ) );
		$this->assertTrue( Lock::check_lock( $lock, 1 ) );

		// Simulate the old worker completing after a new worker has acquired the lease.
		$property = new \ReflectionProperty( Lock::class, 'acquired_locks' );
		$property->setAccessible( true );
		$owners = $property->getValue();
		$owners[ $wpdb->options ][ $lock ] = array_reverse( $owners[ $wpdb->options ][ $lock ] );
		$property->setValue( null, $owners );
		$this->assertTrue( Lock::free_lock( $lock ) );
		$this->assertSame( 1, Lock::get_lock_value( $lock ) );
		$this->assertTrue( Lock::free_lock( $lock ) );
		$this->assertSame( 0, Lock::get_lock_value( $lock ) );
	}

	public function test_failed_release_and_reset_can_be_retried() {
		global $wpdb;
		$lock = 'failed-' . wp_generate_uuid4();
		$this->assertTrue( Lock::check_lock( $lock, 1 ) );
		$table = $wpdb->options;
		$filter = static function ( $query ) use ( $table ) {
			if ( 0 === strpos( $query, 'UPDATE `' . $table . '`' ) || 0 === strpos( $query, 'INSERT INTO `' . $table . '`' ) ) {
				return '';
			}
			return $query;
		};
		add_filter( 'query', $filter );
		try {
			$this->assertFalse( Lock::free_lock( $lock ) );
			$this->assertFalse( Lock::reset_lock( $lock ) );
		} finally {
			remove_filter( 'query', $filter );
		}
		$this->assertSame( 1, Lock::get_lock_value( $lock ) );
		$this->assertTrue( Lock::free_lock( $lock ) );
		$this->assertSame( 0, Lock::get_lock_value( $lock ) );
	}

	public function test_reset_invalidates_old_owners() {
		global $wpdb;
		$lock = 'reset-' . wp_generate_uuid4();
		$this->assertTrue( Lock::check_lock( $lock, 1 ) );
		$property = new \ReflectionProperty( Lock::class, 'acquired_locks' );
		$property->setAccessible( true );
		$owners = $property->getValue();
		$old_owner = $owners[ $wpdb->options ][ $lock ][0];
		$this->assertTrue( Lock::reset_lock( $lock ) );
		$this->assertSame( 0, Lock::get_lock_value( $lock ) );
		$this->assertGreaterThan( time() - 2, Lock::get_lock_timestamp( $lock ) );
		$this->assertFalse( Lock::free_lock( $lock ) );
		$this->assertTrue( Lock::check_lock( $lock, 1 ) );
		$owners = $property->getValue();
		$owners[ $wpdb->options ][ $lock ][] = $old_owner;
		$property->setValue( null, $owners );
		$this->assertTrue( Lock::free_lock( $lock ) );
		$this->assertSame( 1, Lock::get_lock_value( $lock ) );
		$this->assertTrue( Lock::free_lock( $lock ) );
	}

	public function test_hyperdb_reads_use_primary_and_restore_routing() {
		global $wpdb;
		$original = $wpdb;
		$wpdb = new class() {
			public $options = 'wp_options';
			public $srtm = array( 'existing_table' => true );
			public $last_error = '';
			public $observed_srtm;

			public function prepare( $query, ...$args ) {
				return $query;
			}

			public function get_var( $query ) {
				$this->observed_srtm = $this->srtm;
				return null;
			}
		};
		try {
			$this->assertSame( 0, Lock::get_lock_value( 'routing' ) );
			$this->assertTrue( $wpdb->observed_srtm );
			$this->assertSame( array( 'existing_table' => true ), $wpdb->srtm );
		} finally {
			$wpdb = $original;
		}
	}

	public function test_ownership_is_scoped_to_the_site() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Requires multisite.' );
		}
		$site = self::factory()->blog->create();
		$lock = 'site-' . wp_generate_uuid4();
		$this->assertTrue( Lock::check_lock( $lock, 1 ) );
		switch_to_blog( $site );
		try {
			$this->assertTrue( Lock::check_lock( $lock, 1 ) );
		} finally {
			restore_current_blog();
		}
		$this->assertTrue( Lock::free_lock( $lock ) );
		$this->assertSame( 0, Lock::get_lock_value( $lock ) );
		switch_to_blog( $site );
		try {
			$this->assertSame( 1, Lock::get_lock_value( $lock ) );
			$this->assertTrue( Lock::free_lock( $lock ) );
			$this->assertSame( 0, Lock::get_lock_value( $lock ) );
		} finally {
			restore_current_blog();
		}
	}
}
