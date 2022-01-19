<?php

namespace Automattic\WP\Cron_Control\Tests;

use Automattic\WP\Cron_Control;

class Internal_Events_Tests extends \WP_UnitTestCase {
	function setUp() {
		parent::setUp();
		Utils::clear_cron_table();
	}

	function tearDown() {
		Utils::clear_cron_table();
		parent::tearDown();
	}

	function test_internal_events_are_scheduled() {
		Cron_Control\Internal_Events::instance()->schedule_internal_events();
		$scheduled_events = Cron_Control\Events::query( [ 'limit' => 100 ] );

		$expected_count = 4; // Number of events created by the Internal_Events::prepare_internal_events() method, which is private.
		$expected_count += count( \CRON_CONTROL_ADDITIONAL_INTERNAL_EVENTS );
		$this->assertEquals( count( $scheduled_events ), $expected_count, 'Correct number of Internal Events registered' );

		foreach ( $scheduled_events as $scheduled_event ) {
			$this->assertTrue( $scheduled_event->is_internal(), sprintf( 'Action `%s` is not an Internal Event', $scheduled_event->get_action() ) );
		}
	}

	// TODO: Test the actual logic that internal events run.
}
