<?php

namespace Automattic\WP\Cron_Control\Tests;

use Automattic\WP\Cron_Control\Events;
use Automattic\WP\Cron_Control\Event;
use Automattic\WP\Cron_Control\Lock;
use WP_Error;

require_once __DIR__ . '/fixtures/class-transition-failure-events.php';

class Events_Tests extends \WP_UnitTestCase {
	public function setUp(): void {
		parent::setUp();
		Utils::clear_cron_table();
	}

	public function tearDown(): void {
		Utils::clear_cron_table();
		parent::tearDown();
	}

	// The actual query functionality is largely tested in the data store already, so here we just ensure the returns are as expected.
	public function test_query() {
		// Create 2 test events.
		Utils::create_test_event( [ 'action' => 'test_query_action', 'args' => [ 'first' ], 'timestamp' => 1 ] );
		Utils::create_test_event( [ 'action' => 'test_query_action', 'args' => [ 'second' ], 'timestamp' => 2 ] );

		// Ensure both are returned
		$events = Events::query( [ 'action' => 'test_query_action', 'limit' => 100 ] );
		$this->assertEquals( count( $events ), 2, 'Correct number of events returned' );
		$this->assertEquals( $events[0]->get_args(), [ 'first' ], 'Oldest event returned first by default' );
		$this->assertEquals( $events[1]->get_args(), [ 'second' ], 'Correct second event also found.' );

		// Empty array when none are found.
		$events = Events::query( [ 'action' => 'non_existent_action', 'limit' => 100 ] );
		$this->assertEquals( $events, [], 'Returns empty array when no results found' );
	}

	public function test_format_events_for_wp() {
		$events = $this->create_test_events();

		$expected_format = [
			$events['A']->get_timestamp() => [ // A & B & C share timestamps
				$events['A']->get_action() => [ // A & B share action
					$events['A']->get_instance() => [
						'schedule' => false,
						'args'     => [],
					],
					$events['B']->get_instance() => [
						'schedule' => 'hourly',
						'interval' => HOUR_IN_SECONDS,
						'args'     => [ 'B' ],
					],
				],
				$events['C']->get_action() => [ // C has it's own action
					$events['C']->get_instance() => [
						'schedule' => false,
						'args'     => [ 'C' ],
					],
				],
			],
			$events['D']->get_timestamp() => [ // D is on it's own since the timestamp is unique.
				$events['D']->get_action() => [
					$events['D']->get_instance() => [
						'schedule' => false,
						'args'     => [ 'D' ],
					],
				],
			],
		];

		$formatted = Events::format_events_for_wp( array_values( $events ) );
		$this->assertEquals( $formatted, $expected_format, 'Returns the correct array format' );

		$empty_formatted = Events::format_events_for_wp( [] );
		$this->assertEquals( $empty_formatted, [], 'Returns empty array when no events to format' );
	}

	public function test_flatten_wp_events_array() {
		// Setup an events array the way WP gives it to us.
		$events = $this->create_test_events();
		$formatted = Events::format_events_for_wp( array_values( $events ) );
		$formatted['version'] = 2;

		// Ensure we flatten it w/ all events accounted for.
		$flattened = Events::flatten_wp_events_array( $formatted );
		$this->assertEquals( count( $flattened ), 4, 'Returns all expected events' );
		// Could maybe test more here, but honestly feels like it would couple too closely to the implementation itself.
	}

	public function test_run_event_does_not_run_a_callback_after_a_failed_complete() {
		$this->assert_transition_failure_prevents_callback( array() );
	}

	public function test_run_event_does_not_run_a_callback_after_a_failed_reschedule() {
		$this->assert_transition_failure_prevents_callback(
			array(
				'schedule' => 'hourly',
				'interval' => HOUR_IN_SECONDS,
			)
		);
	}

	public function test_run_event_releases_only_the_action_lock_for_an_internal_event_after_a_failed_transition() {
		$this->assert_transition_failure_prevents_callback(
			array(
				'action' => 'a8c_cron_control_purge_completed_events',
			)
		);
	}

	private function assert_transition_failure_prevents_callback( array $event_data ) {
		$called = 0;
		$action = $event_data['action'] ?? 'test_failed_transition_' . wp_generate_uuid4();
		add_action(
			$action,
			function () use ( &$called ) {
				++$called;
			}
		);

		$event_data['action']     = $action;
		$event_data['timestamp'] = time();
		$event                    = Utils::create_test_event( $event_data );
		$events                   = Transition_Failure_Events::instance();
		$error                    = new WP_Error( 'cron-control:event:failed-update' );
		$events->set_transition_error( $error );
		Lock::prime_lock( Events::LOCK );

		if ( $event->is_internal() ) {
			Lock::check_lock( Events::LOCK, 1 );
		}

		$result = $events->run_event( $event->get_timestamp(), md5( $event->get_action() ), $event->get_instance() );

		$this->assertSame( $error, $result );
		$this->assertSame( 0, $called );
		$this->assertSame( $event->is_internal() ? 1 : 0, Lock::get_lock_value( Events::LOCK ) );
		$this->assertSame( 0, Lock::get_lock_value( $events->get_lock_key_for_event_action( $event ) ) );
	}

	private function create_test_events() {
		$time_one = 1400000000;
		$time_two = 1500000000;

		$events_to_create = [
			'A' => [
				'action'    => 'test_format_events_for_wp',
				'args'      => [],
				'timestamp' => $time_one,
			],
			'B' => [
				'action'    => 'test_format_events_for_wp',
				'args'      => [ 'B' ],
				'timestamp' => $time_one,
				'schedule'  => 'hourly',
				'interval'  => HOUR_IN_SECONDS,
			],
			'C' => [
				'action'    => 'test_format_events_for_wp_two',
				'args'      => [ 'C' ],
				'timestamp' => $time_one,
			],
			'D' => [
				'action'    => 'test_format_events_for_wp',
				'args'      => [ 'D' ],
				'timestamp' => $time_two,
			],
		];

		$events = [];
		foreach ( $events_to_create as $event_key => $event_args ) {
			$events[ $event_key ] = Utils::create_test_event( $event_args );
		}

		return $events;
	}

	public function test_get_events() {
		$events = Events::instance();

		$test_events = $this->register_active_events_for_listing();

		// Fetch w/ default args = (10 + internal) max events, +30 seconds window.
		$results        = $events->get_events();
		$due_now_events = [ $test_events['test_event_1'], $test_events['test_event_2'], $test_events['test_event_3'] ];
		$this->check_get_events( $results, $due_now_events );

		// Fetch w/ 1 max queue size.
		$results     = $events->get_events( 1 );
		$first_event = [ $test_events['test_event_1'] ];
		$this->check_get_events( $results, $first_event );

		// Fetch w/ +11mins queue window (should exclude just our last event +30min event).
		$results       = $events->get_events( null, 60 * 11 );
		$window_events = [
			$test_events['test_event_1'],
			$test_events['test_event_2'],
			$test_events['test_event_3'],
			$test_events['test_event_4'],
			$test_events['test_event_5'],
		];
		$this->check_get_events( $results, $window_events );
	}

	private function check_get_events( $results, $desired_results ) {
		$this->assertEquals( count( $results['events'] ), count( $desired_results ), 'Incorrect number of events returned' );

		foreach ( $results['events'] as $event ) {
			$this->assertContains( $event['action'], wp_list_pluck( $desired_results, 'hashed' ), 'Missing registered event' );
		}
	}

	private function register_active_events_for_listing() {
		$test_events = [
			[ 'timestamp' => strtotime( '-1 minute' ), 'action' => 'test_event_1' ],
			[ 'timestamp' => time(), 'action' => 'test_event_2' ],
			[ 'timestamp' => time(), 'action' => 'test_event_3' ],
			[ 'timestamp' => strtotime( '+5 minutes' ), 'action' => 'test_event_4' ],
			[ 'timestamp' => strtotime( '+10 minutes' ), 'action' => 'test_event_5' ],
			[ 'timestamp' => strtotime( '+30 minutes' ), 'action' => 'test_event_6' ],
		];

		$scheduled = [];
		foreach ( $test_events as $test_event_args ) {
			$event = Utils::create_test_event( $test_event_args );
			$scheduled[ $event->get_action() ] = [
				'action' => $event->get_action(),
				'hashed' => md5( $event->get_action() ),
			];
		}

		return $scheduled;
	}
}
