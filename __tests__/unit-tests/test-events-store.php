<?php

namespace Automattic\WP\Cron_Control\Tests;

use Automattic\WP\Cron_Control\Events_Store;
use Automattic\WP\Cron_Control\Event;

class Events_Store_Tests extends \WP_UnitTestCase {
	function setUp() {
		parent::setUp();
		Utils::clear_cron_table();
	}

	function tearDown() {
		Utils::clear_cron_table();
		parent::tearDown();
	}

	function test_table_exists() {
		global $wpdb;

		$table_name = Utils::get_table_name();
		$this->assertEquals( count( $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) ), 1 );

		$this->assertTrue( Events_Store::is_installed() );
	}

	function test_event_creation() {
		$store = Events_Store::instance();

		// We don't validate fields here, so not much to test other than return values.
		$result = $store->_create_event( [
			'status'        => Events_Store::STATUS_PENDING,
			'action'        => 'test_raw_event',
			'action_hashed' => md5( 'test_raw_event' ),
			'timestamp'     => 1637447873,
			'args'          => serialize( [] ),
			'instance'      => Event::create_instance_hash( [] ),
		] );
		$this->assertTrue( is_int( $result ) && $result > 0, 'event was inserted' );

		$empty_result = $store->_create_event( [] );
		$this->assertTrue( 0 === $empty_result, 'empty event was not inserted' );
	}

	function test_event_updates() {
		$store = Events_Store::instance();

		// Make a valid event.
		$event = new Event();
		$event->set_action( 'test_get_action' );
		$event->set_timestamp( 1637447875 );
		$event->save();

		// Successful update.
		$update_result = $store->_update_event( $event->get_id(), [ 'timestamp' => 1637447875 + 100 ] );
		$this->assertTrue( $update_result, 'event was updated' );
		$this->assertEquals( 1637447875 + 100, $store->_get_event_raw( $event->get_id() )->timestamp, 'properties were updated correctly' );

		// Successful update with additional "where".
		$second_update = $store->_update_event( $event->get_id(), [ 'timestamp' => 1637447875 + 200 ], [ 'status' => Events_Store::STATUS_PENDING ] );
		$this->assertTrue( $second_update, 'event was updated with additional where clause' );
		$this->assertEquals( 1637447875 + 200, $store->_get_event_raw( $event->get_id() )->timestamp, 'properties were updated correctly' );

		// Invalid ID.
		$failed_id_result = $store->_update_event( PHP_INT_MAX, [ 'timestamp' => 1 ] );
		$this->assertFalse( $failed_id_result, 'invalid event id was not updated' );

		// Invalid args results in failure.
		$failed_args_result = $store->_update_event( $event->get_id(), [] );
		$this->assertFalse( $failed_args_result, 'event was not updated due to invalid args' );

		// No update to perform due to additional "where" clause (the event's status is not currently "running").
		$failed_where_update = $store->_update_event( $event->get_id(), [ 'status' => Events_Store::STATUS_PENDING ], [ 'status' => Events_Store::STATUS_RUNNING ] );
		$this->assertFalse( $failed_where_update, 'event was not updated due to additional where clause' );
		$this->assertEquals( Events_Store::STATUS_PENDING, $store->_get_event_raw( $event->get_id() )->status, 'status was not updated' );

		// Cannot override the ID in "where" clause.
		$additional_event = Utils::create_test_event();
		$override_prevention = $store->_update_event( $event->get_id(), [ 'timestamp' => 1637447875 + 300 ], [ 'ID' => $additional_event->get_id() ] );
		$this->assertTrue( $override_prevention, 'event was still updated' );
		$this->assertEquals( 1637447875 + 300, $store->_get_event_raw( $event->get_id() )->timestamp, 'properties were still updated correctly' );
		$this->assertNotEquals( 1637447875 + 300, $store->_get_event_raw( $additional_event->get_id() )->timestamp, 'the other event was not updated' );
	}

	function test_get_raw_event() {
		$store = Events_Store::instance();

		$result = $store->_get_event_raw( -1 );
		$this->assertNull( $result, 'returns null when given invalid ID' );

		$result = $store->_get_event_raw( PHP_INT_MAX );
		$this->assertNull( $result, 'returns null when given an non-existant ID' );

		// Event w/ all defaults.
		$this->run_get_raw_event_test( [
			'creation_args' => [
				'action'    => 'test_event',
				'timestamp' => 1637447873,
			],
			'expected_data' => [
				'status'    => Events_Store::STATUS_PENDING,
				'action'    => 'test_event',
				'args'      => [],
				'schedule'  => null,
				'interval'  => 0,
				'timestamp' => 1637447873,
			],
		] );

		// Event w/ all non-defaults.
		$this->run_get_raw_event_test( [
			'creation_args' => [
				'status'    => Events_Store::STATUS_COMPLETED,
				'action'    => 'test_event',
				'args'      => [ 'some' => 'data' ],
				'schedule'  => 'hourly',
				'interval'  => HOUR_IN_SECONDS,
				'timestamp' => 1637447873,
			],
			'expected_data' => [
				'status'    => Events_Store::STATUS_COMPLETED,
				'action'    => 'test_event',
				'args'      => [ 'some' => 'data' ],
				'schedule'  => 'hourly',
				'interval'  => HOUR_IN_SECONDS,
				'timestamp' => 1637447873,
			],
		] );
	}

	private function run_get_raw_event_test( array $event_data ) {
		$test_event = Utils::create_test_event( $event_data['creation_args'] );

		$expected_data = $event_data['expected_data'];
		$expected_data['id'] = $test_event->get_id();

		Utils::assert_event_object_matches_database( $test_event, $expected_data, $this );
	}

	public function test_query_raw_events() {
		$store = Events_Store::instance();

		$args = [
			'status'    => Events_Store::STATUS_PENDING,
			'action'    => 'test_query_raw_events',
			'args'      => [ 'some' => 'data' ],
			'schedule'  => 'hourly',
			'interval'  => HOUR_IN_SECONDS,
		];

		$event_one   = Utils::create_test_event( array_merge( $args, [ 'timestamp' => 1 ] ) );
		$event_two   = Utils::create_test_event( array_merge( $args, [ 'timestamp' => 2 ] ) );
		$event_three = Utils::create_test_event( array_merge( $args, [ 'timestamp' => 3 ] ) );
		$event_four  = Utils::create_test_event( array_merge( $args, [ 'timestamp' => 4 ] ) );

		// Should give us just the first event that has the oldest timestamp.
		$result = $store->_query_events_raw( [
			'status'   => [ Events_Store::STATUS_PENDING ],
			'action'   => 'test_query_raw_events',
			'args'     => [ 'some' => 'data' ],
			'schedule' => 'hourly',
			'limit'    => 1,
		] );

		$this->assertEquals( 1, count( $result ), 'returns one event w/ oldest timestamp' );
		$this->assertEquals( $event_one->get_timestamp(), $result[0]->timestamp, 'found the right event' );

		// Should give two events now, in desc order
		$result = $store->_query_events_raw( [
			'status'   => [ Events_Store::STATUS_PENDING ],
			'action'   => 'test_query_raw_events',
			'args'     => [ 'some' => 'data' ],
			'schedule' => 'hourly',
			'limit'    => 2,
			'order'    => 'desc',
		] );

		$this->assertEquals( 2, count( $result ), 'returned 2 events' );
		$this->assertEquals( $event_four->get_timestamp(), $result[0]->timestamp, 'found the right event' );
		$this->assertEquals( $event_three->get_timestamp(), $result[1]->timestamp, 'found the right event' );

		// Should find just the middle two events that match the timeframe.
		$result = $store->_query_events_raw( [
			'status'    => [ Events_Store::STATUS_PENDING ],
			'action'    => 'test_query_raw_events',
			'args'      => [ 'some' => 'data' ],
			'schedule'  => 'hourly',
			'limit'     => 100,
			'timestamp' => [ 'from' => 2, 'to' => 3 ],
		] );

		$this->assertEquals( 2, count( $result ), 'returned middle events that match the timeframe' );
		$this->assertEquals( $event_two->get_timestamp(), $result[0]->timestamp, 'found the right event' );
		$this->assertEquals( $event_three->get_timestamp(), $result[1]->timestamp, 'found the right event' );

		$event_five = Utils::create_test_event( array_merge( $args, [ 'timestamp' => time() + 5 ] ) );

		// Should find all but the last event that is not due yet.
		$result = $store->_query_events_raw( [
			'status'    => [ Events_Store::STATUS_PENDING ],
			'action'    => 'test_query_raw_events',
			'args'      => [ 'some' => 'data' ],
			'schedule'  => 'hourly',
			'limit'     => 100,
			'timestamp' => 'due_now',
		] );

		$this->assertEquals( 4, count( $result ), 'returned all due now events' );
		$this->assertEquals( $event_one->get_timestamp(), $result[0]->timestamp, 'found the right event' );
		$this->assertEquals( $event_four->get_timestamp(), $result[3]->timestamp, 'found the right event' );

		// Grab the second page.
		$result = $store->_query_events_raw( [
			'status'   => [ Events_Store::STATUS_PENDING ],
			'action'   => 'test_query_raw_events',
			'args'     => [ 'some' => 'data' ],
			'schedule' => 'hourly',
			'limit'    => 1,
			'page'     => 2,
		] );

		$this->assertEquals( 1, count( $result ), 'returned event from second page' );
		$this->assertEquals( $event_two->get_timestamp(), $result[0]->timestamp, 'found the right event' );
	}
}
