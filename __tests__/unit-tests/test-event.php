<?php

namespace Automattic\WP\Cron_Control\Tests;

use Automattic\WP\Cron_Control\Events_Store;
use Automattic\WP\Cron_Control\Event;
use WP_Error;

class Event_Tests extends \WP_UnitTestCase {
	function setUp() {
		parent::setUp();
		Utils::clear_cron_table();
	}

	function tearDown() {
		Utils::clear_cron_table();
		parent::tearDown();
	}

	function test_run() {
		$successful_calls = 0;
		$callback_result  = null;
		add_action( 'test_run_event_action', function ( $arg_one, $arg_two ) use ( &$successful_calls, &$callback_result ) {
			$successful_calls++;
			$callback_result = [ $arg_one, $arg_two ];
		}, 10, 2 );
		add_action( 'test_run_event_action_failure', fn() => cause_a_fatal_with_not_a_real_function_call() );

		// Run a single event.
		$single_event = Utils::create_test_event( [ 'action' => 'test_run_event_action', 'args' => [ 'one', 'two' ] ] );
		$single_result = $single_event->run();
		$this->assertTrue( $single_result, 'run was successful' );
		$this->assertEquals( 1, $successful_calls, 'event callback was triggered once' );
		$this->assertEquals( [ 'one', 'two' ], $callback_result, 'event callback was triggered with correct args' );
		$this->assertEquals( Events_Store::STATUS_COMPLETED, $single_event->get_status(), 'event was marked as completed' );
		$successful_calls = 0;

		// Run a recurring event.
		$recurring_event = Utils::create_test_event( [ 'action' => 'test_run_event_action', 'args' => [ 'three', 'four' ], 'schedule' => 'hourly', 'interval' => \HOUR_IN_SECONDS, 'timestamp' => time() + 500 ] );
		$recurring_result = $recurring_event->run();
		$this->assertTrue( $recurring_result, 'run was successful' );
		$this->assertEquals( 1, $successful_calls, 'event callback was triggered once' );
		$this->assertEquals( [ 'three', 'four' ], $callback_result, 'event callback was triggered with correct args' );
		$this->assertEquals( Events_Store::STATUS_PENDING, $recurring_event->get_status(), 'event was rescheduled with pending status' );

		// Run a failing event.
		$failing_event = Utils::create_test_event( [ 'action' => 'test_run_event_action_failure' ] );
		$failed_result = @$failing_event->run(); // @codingStandardsIgnoreLine Error suppression operator is used to prevent test from outputting the fatals.
		$this->assertEquals( 'cron-control:event:error-thrown-during-run', $failed_result->get_error_code() );
		$this->assertEquals( Events_Store::STATUS_COMPLETED, $single_event->get_status(), 'failed event was still marked as completed' );
	}

	function test_run_if_allowed() {
		global $wpdb;
		$event = Utils::create_test_event( [ 'action' => 'test_run_if_allowed', 'timestamp' => time() + 1 ] );

		// Returns error if the timestamp isn't ready yet.
		$this->assertEquals( 'cron-control:event:not-ready-yet', $event->run_if_allowed()->get_error_code() );

		// The timestamp is due now, but still not ready to run since the status is not "pending".
		$event->set_timestamp( time() - 100 );
		$event->set_status( Events_Store::STATUS_RUNNING );
		$this->assertEquals( 'cron-control:event:not-ready-yet', $event->run_if_allowed()->get_error_code() );

		// Can't run if the action concurrency lock has already been claimed.
		$event->set_status( Events_Store::STATUS_PENDING );
		wp_cache_add( 'event_action_test_run_if_allowed', 1, 'cron-control-locks', 500 );
		$this->assertEquals( 'cron-control:event:action-lock-unavailable', $event->run_if_allowed()->get_error_code() );

		// Action concurrency lock is freed now, but "another request" concurrently already set the status to "running".
		$table_name = Utils::get_table_name();
		$wpdb->query( "UPDATE $table_name SET status = 'running' where ID = {$event->get_id()}" ); // @codingStandardsIgnoreLine direct SQL okay here.
		wp_cache_delete( 'event_action_test_run_if_allowed', 'cron-control-locks' );
		$this->assertEquals( 'cron-control:event:failed-to-set-running-status', $event->run_if_allowed()->get_error_code() );
		$this->assertFalse( wp_cache_get( 'event_action_test_run_if_allowed', 'cron-control-locks' ), 'action lock was cleared' );

		// Finally, all conditions are met and we can run the event!
		$wpdb->query( "UPDATE $table_name SET status = 'pending' where ID = {$event->get_id()}" );
		$this->assertTrue( $event->run_if_allowed() );
		$this->assertFalse( wp_cache_get( 'event_action_test_run_if_allowed', 'cron-control-locks' ), 'action lock was cleared' );
	}

	function test_complete() {
		// Mock up an event, but try to complete it before saving.
		$event = new Event();
		$event->set_action( 'test_complete' );
		$event->set_timestamp( time() );
		$event->set_args( [ 'test', 'args' ] );
		$result = $event->complete();
		$this->assertEquals( 'cron-control:event:cannot-complete', $result->get_error_code() );

		// Now save the event and make sure props were updated correctly.
		$event->save();
		$result = $event->complete();
		$this->assertTrue( $result, 'event was successfully completed' );
		$this->assertEquals( Events_Store::STATUS_COMPLETED, $event->get_status(), 'the status was updated' );
		$this->assertNotEquals( Event::create_instance_hash( [ 'test', 'args' ] ), $event->get_instance(), 'the instance was updated/randomized' );
	}

	function test_reschedule() {
		global $wpdb;

		// Try to reschedule a non-recurring event.
		$event = new Event();
		$event->set_action( 'test_reschedule' );
		$event->set_timestamp( time() + 10 );
		$event->save();
		$result = $event->reschedule();
		$this->assertEquals( 'cron-control:event:cannot-reschedule', $result->get_error_code() );
		$this->assertEquals( Events_Store::STATUS_COMPLETED, $event->get_status() );

		// Mock up recurring event, but try to reschedule before saving.
		$event = new Event();
		$event->set_action( 'test_reschedule' );
		$event->set_timestamp( time() + 10 );
		$event->set_schedule( 'hourly', HOUR_IN_SECONDS );
		$result = $event->reschedule();
		$this->assertEquals( 'cron-control:event:cannot-reschedule', $result->get_error_code() );

		// Now save the event and make sure props were updated correctly.
		$event->set_status( Events_Store::STATUS_RUNNING );
		$event->save();
		$result = $event->reschedule();
		$this->assertTrue( $result, 'event was successfully rescheduled' );
		$this->assertEquals( Events_Store::STATUS_PENDING, $event->get_status() );
		$this->assertEquals( time() + HOUR_IN_SECONDS, $event->get_timestamp() );

		// Simulate a concurrent request completing/deleting the event whilst it was running in this request.
		$table_name = Utils::get_table_name();
		$wpdb->query( "UPDATE $table_name SET status = 'complete' where ID = {$event->get_id()}" ); // @codingStandardsIgnoreLine direct SQL okay here.
		$result = $event->reschedule();
		$this->assertEquals( 'cron-control:event:cannot-reschedule-completed-event', $result->get_error_code() );
	}

	function test_exists() {
		$event = new Event();
		$event->set_action( 'test_exists' );
		$event->set_timestamp( time() );
		$this->assertFalse( $event->exists() );

		$event->save();
		$this->assertTrue( $event->exists() );
	}

	function test_create_instance_hash() {
		$empty_args = Event::create_instance_hash( [] );
		$this->assertEquals( md5( serialize( [] ) ), $empty_args );

		$has_args = Event::create_instance_hash( [ 'some', 'data' ] );
		$this->assertEquals( md5( serialize( [ 'some', 'data' ] ) ), $has_args );
	}

	function test_get_wp_event_format() {
		$event = new Event();
		$event->set_action( 'test_get_wp_event_format' );
		$event->set_timestamp( 123 );
		$event->save();

		$this->assertEquals( (object) [
			'hook'      => 'test_get_wp_event_format',
			'timestamp' => 123,
			'schedule'  => false,
			'args'      => [],
		], $event->get_wp_event_format() );

		$event->set_schedule( 'hourly', HOUR_IN_SECONDS );
		$event->set_args( [ 'args' ] );
		$event->save();

		$this->assertEquals( (object) [
			'hook'      => 'test_get_wp_event_format',
			'timestamp' => 123,
			'schedule'  => 'hourly',
			'interval'  => HOUR_IN_SECONDS,
			'args'      => [ 'args' ],
		], $event->get_wp_event_format() );
	}

	function test_get() {
		$test_event = new Event();
		$test_event->set_action( 'test_get_action' );
		$test_event->set_timestamp( 1637447875 );
		$test_event->save();

		// Successful get by ID.
		$event = Event::get( $test_event->get_id() );
		$this->assertEquals( 'test_get_action', $event->get_action(), 'found event by id' );

		// Failed get by ID.
		$event = Event::get( PHP_INT_MAX );
		$this->assertNull( $event, 'could not find event by ID' );
	}

	function test_find() {
		$test_event = new Event();
		$test_event->set_action( 'test_find_action' );
		$test_event->set_timestamp( 1637447876 );
		$test_event->save();

		// Successful find by args.
		$event = Event::find( [ 'action' => 'test_find_action', 'timestamp' => 1637447876 ] );
		$this->assertEquals( 'test_find_action', $event->get_action(), 'found event by args' );

		// Failed find by args.
		$event = Event::find( [ 'action' => 'non_existant_action', 'timestamp' => 1637447876 ] );
		$this->assertNull( $event, 'could not find event by args' );
	}

	function test_validate_props() {
		// Invalid status.
		$this->run_event_save_test( [
			'creation' => [
				'args' => [
					'timestamp' => 1637447873,
					'action'    => 'test_event',
					'status'    => 'invalid_status',
				],
				'result' => new WP_Error( 'cron-control:event:prop-validation:invalid-status' ),
			],
		] );

		// Invalid/missing action.
		$this->run_event_save_test( [
			'creation' => [
				'args' => [
					'timestamp' => 1637447873,
					'action'    => '',
				],
				'result' => new WP_Error( 'cron-control:event:prop-validation:invalid-action' ),
			],
		] );

		// Missing timestamp.
		$this->run_event_save_test( [
			'creation' => [
				'args' => [ 'action' => 'test_event' ],
				'result' => new WP_Error( 'cron-control:event:prop-validation:invalid-timestamp' ),
			],
		] );

		// Invalid timestamp.
		$this->run_event_save_test( [
			'creation' => [
				'args' => [
					'timestamp' => -100,
					'action'    => 'test_event',
				],
				'result' => new WP_Error( 'cron-control:event:prop-validation:invalid-timestamp' ),
			],
		] );

		// Invalid schedule.
		$this->run_event_save_test( [
			'creation' => [
				'args' => [
					'timestamp' => 1637447873,
					'action'    => 'test_event',
					'schedule'  => '',
					'interval'  => HOUR_IN_SECONDS,
				],
				'result' => new WP_Error( 'cron-control:event:prop-validation:invalid-schedule' ),
			],
		] );

		// Invalid interval.
		$this->run_event_save_test( [
			'creation' => [
				'args' => [
					'timestamp' => 1637447873,
					'action'    => 'test_event',
					'schedule'  => 'hourly',
					'interval'  => 0,
				],
				'result' => new WP_Error( 'cron-control:event:prop-validation:invalid-schedule' ),
			],
		] );
	}

	// Run through various flows of event saving.
	function test_event_save() {
		// Create event w/ bare information to test the defaults.
		// Then update the timestamp.
		$this->run_event_save_test( [
			'creation' => [
				'args' => [
					'action'    => 'test_event_creations_1',
					'timestamp' => 1637447872,
				],
				'result' => [
					'status'    => 'pending',
					'action'    => 'test_event_creations_1',
					'args'      => [],
					'schedule'  => null,
					'interval'  => 0,
					'timestamp' => 1637447872,
				],
			],
			'update' => [
				'args' => [ 'timestamp' => 1637447872 + 500 ],
				'result' => [
					'status'    => 'pending',
					'action'    => 'test_event_creations_1',
					'args'      => [],
					'schedule'  => null,
					'interval'  => 0,
					'timestamp' => 1637447872 + 500,
				],
			],
		] );

		// Create event w/ all non-default data.
		// Then try to update with invalid timestamp
		$this->run_event_save_test( [
			'creation' => [
				'args' => [
					'status'    => 'complete',
					'action'    => 'test_event_creations_2',
					'args'      => [ 'some' => 'data' ],
					'schedule'  => 'hourly',
					'interval'  => HOUR_IN_SECONDS,
					'timestamp' => 1637447873,
				],
				'result' => [
					'status'    => 'complete',
					'action'    => 'test_event_creations_2',
					'args'      => [ 'some' => 'data' ],
					'schedule'  => 'hourly',
					'interval'  => HOUR_IN_SECONDS,
					'timestamp' => 1637447873,
				],
			],
			'update' => [
				'args' => [ 'timestamp' => -1 ],
				'result' => new WP_Error( 'cron-control:event:prop-validation:invalid-timestamp' ),
			],
		] );
	}

	private function run_event_save_test( array $event_data ) {
		// 1) Create event.
		$test_event = new Event();
		Utils::apply_event_props( $test_event, $event_data['creation']['args'] );
		$save_result = $test_event->save();

		// 2) Verify event creation save results.
		$expected_result = $event_data['creation']['result'];
		$this->verify_save_result( $expected_result, $save_result, $test_event );

		if ( ! isset( $event_data['update'] ) ) {
			// No update tests to perform.
			return;
		}

		// 3) Apply event updates.
		Utils::apply_event_props( $test_event, $event_data['update']['args'] );
		$update_result = $test_event->save();

		// 4) Verify the update result.
		$expected_update_result = $event_data['update']['result'];
		$this->verify_save_result( $expected_update_result, $update_result, $test_event );
	}

	private function verify_save_result( $expected_result, $actual_result, $test_event ) {
		if ( is_wp_error( $expected_result ) ) {
			$this->assertEquals( $expected_result->get_error_code(), $actual_result->get_error_code(), 'save should fail w/ WP Error' );
			// Nothing more to test.
			return;
		}

		$this->assertTrue( $actual_result, 'event was saved' );
		$expected_result['id'] = $test_event->get_id();

		Utils::assert_event_object_matches_database( $test_event, $expected_result, $this );

		// Initiate the event again, testing the getters and ensuring data is hydrated correctly.
		$check_event = Event::get( $test_event->get_id() );
		Utils::assert_event_object_has_correct_props( $check_event, $expected_result, $this );
	}

	public function test_get_from_db_row() {
		// Create bare test event.
		$test_event = new Event();
		$test_event->set_action( 'test_get_from_db_row' );
		$test_event->set_timestamp( 1637447875 );
		$test_event->save();

		$expected_result = [
			'id'        => $test_event->get_id(),
			'status'    => Events_Store::STATUS_PENDING,
			'action'    => 'test_get_from_db_row',
			'args'      => [],
			'schedule'  => null,
			'interval'  => 0,
			'timestamp' => 1637447875,
		];

		$raw_event = Events_Store::instance()->_get_event_raw( $test_event->get_id() );

		// Will populate the event w/ full data from database.
		$event = Event::get_from_db_row( $raw_event );
		Utils::assert_event_object_has_correct_props( $event, $expected_result, $this );

		// Will not populate event w/ partial data from database.
		unset( $raw_event->ID );
		$by_missing_data = Event::get_from_db_row( $raw_event );
		$this->assertNull( $by_missing_data, 'event could not be populated' );
	}
}
