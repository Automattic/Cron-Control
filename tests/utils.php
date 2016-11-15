<?php

namespace WP_Cron_Control_Revisited_Tests;

class Utils {
	/**
	 * Build a test event
	 */
	static function create_test_event( $allow_multiple = false ) {
		$event = array(
			'timestamp' => time(),
			'action'    => 'wpccr_test_event',
			'args'      => array(),
		);

		if ( $allow_multiple ) {
			$event['action'] .= '_' . rand( 10, 100 );
		}

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

		if ( is_object( $context ) ) {
			$context->assertEquals( $expected, $tested_data );
		} else {
			return $tested_data;
		}
	}
}
