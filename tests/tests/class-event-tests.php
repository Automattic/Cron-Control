<?php
/**
 * Test the Event class.
 */

namespace Automattic\WP\Cron_Control\Tests;

use Automattic\WP\Cron_Control\Events_Store;
use Automattic\WP\Cron_Control\Event;

class Event_Tests extends \WP_UnitTestCase {
	function setUp() {
		parent::setUp();

		// delete existing crons
		_set_cron_array( [] );
	}

	function tearDown() {
		_set_cron_array( [] );
		parent::tearDown();
	}

	function test_set_status() {
		$event = new Event();

		$event->set_status( 1 );
		$this->assertEquals( Events_Store::STATUS_PENDING, $event->get_status(), 'invalid status is set to the default' );
		$event->set_status( 'COMPLETE' );
		$this->assertEquals( Events_Store::STATUS_COMPLETED, $event->get_status(), 'status is matched w/ lowercase versions' );
	}

	function test_set_action() {
		$event = new Event();

		$event->set_action( '' );
		$this->assertEquals( null, $event->get_action(), 'action is not set if invalid' );
	}

	function test_set_schedule() {
		$event = new Event();

		$event->set_schedule( '', HOUR_IN_SECONDS );
		$this->assertEquals( null, $event->get_schedule(), 'schedule is not set if name is invalid' );
		$event->set_schedule( 'hourly', 0 );
		$this->assertEquals( null, $event->get_schedule(), 'schedule is not set if interval is invalid' );
	}

	function test_set_timestamp() {
		$event = new Event();

		$event->set_timestamp( -1 );
		$this->assertEquals( null, $event->get_timestamp(), 'timestamp is not set if invalid' );
	}

	function test_run() {
		$called = 0;
		add_action( 'test_run_event_action', function () use ( &$called ) {
			$called++;
		} );

		$event = new Event();
		$event->set_action( 'test_run_event_action' );
		$event->run();

		$this->assertEquals( 1, $called, 'event callback was triggered once' );
	}

	function test_complete() {
		// Mock up an event, but try to complete it before saving.
		$event = new Event();
		$event->set_action( 'test_action' );
		$event->set_timestamp( time() );
		$result = $event->complete();
		$this->assertEquals( 'cron-control:event:cannot-complete', $result->get_error_code() );

		// Now save the event and make sure props were updated correctly.
		$event->save();
		$result = $event->complete();
		$this->assertTrue( $result, 'event was successfully completed' );
		$this->assertEquals( Events_Store::STATUS_COMPLETED, $event->get_status() );
	}

	function test_reschedule() {
		// Try to reschedule a non-recurring event.
		$event = new Event();
		$event->set_action( 'test_action' );
		$event->set_timestamp( time() + 10 );
		$event->save();
		$result = $event->reschedule();
		$this->assertEquals( 'cron-control:event:cannot-reschedule', $result->get_error_code() );
		$this->assertEquals( Events_Store::STATUS_COMPLETED, $event->get_status() );

		// Mock up recurring event, but try to reschedule before saving.
		$event = new Event();
		$event->set_action( 'test_action' );
		$event->set_timestamp( time() + 10 );
		$event->set_schedule( 'hourly', HOUR_IN_SECONDS );
		$result = $event->reschedule();
		$this->assertEquals( 'cron-control:event:cannot-reschedule', $result->get_error_code() );

		// Now save the event and make sure props were updated correctly.
		$event->save();
		$result = $event->reschedule();
		$this->assertTrue( $result, 'event was successfully rescheduled' );
		$this->assertEquals( Events_Store::STATUS_PENDING, $event->get_status() );
		$this->assertEquals( time() + HOUR_IN_SECONDS, $event->get_timestamp() );
	}

	function test_exists() {
		$event = new Event();
		$event->set_action( 'test_action' );
		$event->set_timestamp( time() );
		$this->assertFalse( $event->exists() );

		$event->save();
		$this->assertTrue( $event->exists() );

		$missing_event = new Event( PHP_INT_MAX );
		$this->assertFalse( $missing_event->exists() );
	}

	function test_create_instance_hash() {
		$empty_args = Event::create_instance_hash( [] );
		$this->assertEquals( md5( serialize( [] ) ), $empty_args );

		$has_args = Event::create_instance_hash( [ 'some', 'data' ] );
		$this->assertEquals( md5( serialize( [ 'some', 'data' ] ) ), $has_args );
	}

	function test_get_wp_event_format() {
		$event = new Event();
		$event->set_action( 'test_action' );
		$event->set_timestamp( 123 );
		$event->save();

		$this->assertEquals( (object) [
			'hook'      => 'test_action',
			'timestamp' => 123,
			'schedule'  => false,
			'args'      => [],
		], $event->get_wp_event_format() );

		$event->set_schedule( 'hourly', HOUR_IN_SECONDS );
		$event->set_args( [ 'args' ] );
		$event->save();

		$this->assertEquals( (object) [
			'hook'      => 'test_action',
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

		// Successful get by args.
		$event = Event::get( [ 'action' => 'test_get_action', 'timestamp' => 1637447875 ] );
		$this->assertEquals( 'test_get_action', $event->get_action(), 'found event by args' );

		// Failed get by args.
		$event = Event::get( [ 'action' => 'non_existant_action', 'timestamp' => 1637447875 ] );
		$this->assertNull( $event, 'could not find event by args' );
	}

	// Run through various flows of event creation.
	function test_event_creations() {
		// Create event w/ bare information to test the defaults.
		$this->run_event_creation_test( [
			'creation_args' => [
				'action'    => 'test_event',
				'timestamp' => 1637447872,
			],
			'expected_args' => [
				'status'    => 'pending',
				'action'    => 'test_event',
				'args'      => [],
				'schedule'  => null,
				'interval'  => 0,
				'timestamp' => 1637447872,
			],
		] );

		// Create event w/ all non-default data.
		$this->run_event_creation_test( [
			'creation_args' => [
				'status'    => 'complete',
				'action'    => 'test_event',
				'args'      => [ 'some' => 'data' ],
				'schedule'  => 'hourly',
				'interval'  => HOUR_IN_SECONDS,
				'timestamp' => 1637447873,
			],
			'expected_args' => [
				'status'    => 'complete',
				'action'    => 'test_event',
				'args'      => [ 'some' => 'data' ],
				'schedule'  => 'hourly',
				'interval'  => HOUR_IN_SECONDS,
				'timestamp' => 1637447873,
			],
		] );

		// Try to create event w/ missing action.
		$this->run_event_creation_test( [
			'creation_args' => [
				'timestamp' => 1637447873,
			],
			'expected_args' => false,
		] );

		// Try to create event w/ missing timestamp.
		$this->run_event_creation_test( [
			'creation_args' => [
				'action'    => 'test_event',
			],
			'expected_args' => false,
		] );
	}

	private function run_event_creation_test( array $event_data ) {
		$should_fail = false === $event_data['expected_args'];

		$test_event = new Event();
		Utils::apply_event_props( $test_event, $event_data['creation_args'] );
		$save_result = $test_event->save();

		// Check save results.
		if ( $should_fail ) {
			$this->assertEquals( 'cron-control:event:missing-props', $save_result->get_error_code(), 'save should fail due to missing props' );
		} else {
			$this->assertTrue( $save_result, 'event was saved' );

			// Changes should be reset, nothing should be attempted to be saved right away again.
			$second_save_result = $test_event->save();
			$this->assertEquals( 'cron-control:event:no-save-needed', $second_save_result->get_error_code() );
		}

		// 1) Grab straight from the DB so we can make sure the enclosed properties worked correctly.
		if ( ! $should_fail ) {
			$raw_event = Events_Store::_get_event_raw( $test_event->get_id() );
			$event_data['expected_args']['id'] = $test_event->get_id();
			Utils::assert_event_raw_data_equals( $raw_event, $event_data['expected_args'], $this );
		}

		// 2) Initiate the event again, testing the getters and ensuring data is hydrated correctly.
		if ( ! $should_fail ) {
			$check_event = new Event( $test_event->get_id() );
			$event_data['expected_args']['id'] = $test_event->get_id();
			$this->assert_props_are_correct( $check_event, $event_data['expected_args'] );
		}
	}

	// Run through various flows for event updates.
	function test_event_updates() {
		// All defaults, w/ a timestamp increase.
		$this->run_event_update_test( [
			'creation_args' => [
				'action'    => 'test_update_event',
				'timestamp' => 1637447872,
			],
			'update_args' => [
				'action'    => 'test_update_event',
				'timestamp' => 1637447872 + 500,
			],
			'expected_args' => [
				'status'    => 'pending',
				'action'    => 'test_update_event',
				'args'      => [],
				'schedule'  => null,
				'interval'  => 0,
				'timestamp' => 1637447872 + 500,
			],
		] );

		// Ensure invalid data does not trigger update.
		$this->run_event_update_test( [
			'creation_args' => [
				'action'    => 'test_update_event',
				'timestamp' => 1637447872,
			],
			'update_args' => [
				'action'    => '',
				'timestamp' => -1,
			],
			'expected_args' => [
				'status'    => 'pending',
				'action'    => 'test_update_event',
				'args'      => [],
				'schedule'  => null,
				'interval'  => 0,
				'timestamp' => 1637447872,
			],
		], true );
	}

	private function run_event_update_test( array $event_data, bool $update_should_fail = false ) {
		// Make the  intial test event.
		$test_event = new Event();
		Utils::apply_event_props( $test_event, $event_data['creation_args'] );
		$save_result = $test_event->save();
		$this->assertTrue( $save_result, 'event was saved' );

		// Apply updates
		Utils::apply_event_props( $test_event, $event_data['update_args'] );
		$update_result = $test_event->save();

		// 1) Check the update results
		if ( $update_should_fail ) {
			$this->assertTrue( is_wp_error( $update_result ), 'event was not updated' );
		} else {
			$this->assertTrue( $update_result, 'event was updated' );

			// Nothing left to update.
			$second_save_result = $test_event->save();
			$this->assertEquals( 'cron-control:event:no-save-needed', $second_save_result->get_error_code() );
		}

		// 2) Check updates in the DB.
		$raw_event = Events_Store::_get_event_raw( $test_event->get_id() );
		$event_data['expected_args']['id'] = $test_event->get_id();
		Utils::assert_event_raw_data_equals( $raw_event, $event_data['expected_args'], $this );

		// 3) Initiate the event again, and test it's props.
		$check_event = new Event( $test_event->get_id() );
		$this->assert_props_are_correct( $check_event, $event_data['expected_args'] );

		return $test_event;
	}

	public function test_populate_data() {
		$test_event = new Event();
		$test_event->set_action( 'test_populate_data' );
		$test_event->set_timestamp( 1637447875 );
		$test_event->save();

		$expected_result = [
			'id'        => $test_event->get_id(),
			'status'    => Events_Store::STATUS_PENDING,
			'action'    => 'test_populate_data',
			'args'      => [],
			'schedule'  => null,
			'timestamp' => 1637447875,
		];

		// Can populate event from valid ID.
		$by_id = new Event( $test_event->get_id() );
		$this->assert_props_are_correct( $by_id, $expected_result );
		$save_result = $by_id->save();
		$this->assertEquals( 'cron-control:event:no-save-needed', $save_result->get_error_code(), 'nothing to save yet' );

		$raw_event = Events_Store::_get_event_raw( $test_event->get_id() );

		// Can populate the event w/ full data from database.
		$by_data = new Event( $raw_event );
		$this->assert_props_are_correct( $by_id, $expected_result );
		$save_result = $by_id->save();
		$this->assertEquals( 'cron-control:event:no-save-needed', $save_result->get_error_code(), 'nothing to save yet' );

		// Cannot populate event w/ partial data from database.
		unset( $raw_event->ID );
		$by_missing_data = new Event( $raw_event );
		$this->assertFalse( $by_missing_data->exists(), 'event should not exist yet' );
		$this->assertEquals( $by_missing_data->get_action(), null, 'event should not have had any props set' );
	}

	private function assert_props_are_correct( Event $event, array $expected_data ) {
		$this->assertEquals( $event->get_id(), $expected_data['id'] );
		$this->assertEquals( $event->get_status(), $expected_data['status'] );
		$this->assertEquals( $event->get_action(), $expected_data['action'] );
		$this->assertEquals( $event->get_args(), $expected_data['args'] );
		$this->assertEquals( $event->get_schedule(), $expected_data['schedule'] );
		$this->assertEquals( $event->get_timestamp(), $expected_data['timestamp'] );
	}
}
