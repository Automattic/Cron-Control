<?php

namespace WP_Cron_Control_Revisited_Tests;

class Utils {
	/**
	 * Build a test event
	 */
	static function create_test_event() {
		$event = array(
			'timestamp' => time(),
			'action'    => 'wpccr_test_event',
			'args'      => array(),
		);

		$next = wp_next_scheduled( $event['action'], $event['args'] );

		if ( $next ) {
			$event['timestamp'] = $next;
		} else {
			wp_schedule_single_event( $event[ 'timestamp' ], $event[ 'action' ], $event[ 'args' ] );
		}

		return $event;
	}

	/**
	 * Check that two arrays are equal
	 */
	static function compare_arrays( $expected, $test, $context ) {
		$tested_data = array();
		foreach( $expected as $key => $value ) {
			if ( isset( $test[ $key ] ) ) {
				$tested_data[ $key ] = $test[ $key ];
			} else {
				$tested_data[ $key ] = null;
			}
		}

		$context->assertEquals( $expected, $tested_data );
	}
}
