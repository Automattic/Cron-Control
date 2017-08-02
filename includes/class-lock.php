<?php

namespace Automattic\WP\Cron_Control;

class Lock {
	/**
	 * Set a lock and limit how many concurrent jobs are permitted
	 *
	 * @param $lock     string  Lock name
	 * @param $limit    int     Concurrency limit
	 * @param $timeout  int     Timeout in seconds
	 *
	 * @return bool
	 */
	public static function check_lock( $lock, $limit = null, $timeout = null ) {
		// Timeout, should a process die before its lock is freed
		if ( ! is_numeric( $timeout ) ) {
			$timeout = LOCK_DEFAULT_TIMEOUT_IN_MINUTES * \MINUTE_IN_SECONDS;
		}

		// Check for, and recover from, deadlock
		if ( self::get_lock_timestamp( $lock ) < time() - $timeout ) {
			self::reset_lock( $lock );
			return true;
		}

		// Default limit for concurrent events
		if ( ! is_numeric( $limit ) ) {
			$limit = LOCK_DEFAULT_LIMIT;
		}

		// Check lock according to limit
		if ( 1 === $limit ) {
			return self::check_single_lock( $lock );
		} else {
			return self::check_multi_lock( $lock, $limit, $timeout );
		}
	}

	/**
	 * Check a single-concurrency lock
	 *
	 * @param string $lock
	 * @return bool
	 */
	private static function check_single_lock( $lock ) {
		if ( self::get_lock_value( $lock ) >= 1 ) {
			return false;
		} else {
			wp_cache_incr( self::get_key( $lock ) );
			return true;
		}
	}

	/**
	 * Check a multiple-concurrency lock
	 *
	 * @param string $lock
	 * @param int    $limit
	 * @param int    $timeout
	 * @return bool
	 */
	private static function check_multi_lock( $lock, $limit, $timeout ) {
		$value = self::get_lock_value( $lock );

		// Upgrade to timestamped multi-lock, otherwise clear deadlocks
		if ( is_int( $value ) ) {
			$value = array_fill( 0, $value, time() );
		} elseif ( is_array( $value ) ) {
			$value = empty( $value ) ? array() : self::purge_stale_values( $value, $timeout );
		} else {
			$value = array();
		}

		// Still locked
		if ( count( $value ) >= $limit ) {
			return false;
		}

		// Available, claim a slot
		$value[] = time();
		wp_cache_set( self::get_key( $lock ), $value );

		return true;
	}

	/**
	 * When event completes, allow another
	 */
	public static function free_lock( $lock, $expires = 0 ) {
		$lock_value = self::get_lock_value( $lock );

		if ( empty( $lock_value ) ) {
			wp_cache_set( self::get_key( $lock, 'timestamp' ), time(), null, $expires );
			return true;
		}

		if ( is_int( $lock_value ) ) {
			self::free_single_lock( $lock, $lock_value, $expires );
		} else {
			self::free_multi_lock( $lock, $lock_value, $expires );
		}

		return true;
	}

	/**
	 * Free a single-concurrency lock
	 *
	 * @param string $lock
	 * @param mixed  $lock_value
	 * @param int    $expires
	 */
	private static function free_single_lock( $lock, $lock_value, $expires ) {
		if ( $lock_value > 1 ) {
			wp_cache_decr( self::get_key( $lock ) );
		} else {
			wp_cache_set( self::get_key( $lock ), 0, null, $expires );
		}

		wp_cache_set( self::get_key( $lock, 'timestamp' ), time(), null, $expires );
	}

	/**
	 * Free old multi-concurrency lock
	 *
	 * @param string $lock
	 * @param mixed  $lock_value
	 * @param int    $expires
	 */
	private static function free_multi_lock( $lock, $lock_value, $expires ) {
		sort( $lock_value, SORT_NUMERIC );
		array_shift( $lock_value );
		wp_cache_set( self::get_key( $lock ), $lock_value, null, $expires );
		wp_cache_set( self::get_key( $lock, 'timestamp' ), time(), null, $expires );
	}

	/**
	 * Build cache key
	 */
	private static function get_key( $lock, $type = 'lock' ) {
		switch ( $type ) {
			case 'lock' :
				return "a8ccc_lock_{$lock}";
				break;

			case 'timestamp' :
				return "a8ccc_lock_ts_{$lock}";
				break;
		}

		return false;
	}

	/**
	 * Ensure lock entries are initially set
	 */
	public static function prime_lock( $lock, $expires = 0 ) {
		wp_cache_add( self::get_key( $lock ), 0, null, $expires );
		wp_cache_add( self::get_key( $lock, 'timestamp' ), time(), null, $expires );

		return null;
	}

	/**
	 * Retrieve a lock from cache
	 */
	public static function get_lock_value( $lock ) {
		$value = wp_cache_get( self::get_key( $lock ), null, true );

		if ( ! is_numeric( $value ) && ! is_array( $value ) ) {
			self::reset_lock( $lock );
			return 0;
		}

		if ( is_numeric( $value ) ) {
			$value = (int) $value;
		}

		return $value;
	}

	/**
	 * Retrieve a lock's timestamp
	 */
	public static function get_lock_timestamp( $lock ) {
		return (int) wp_cache_get( self::get_key( $lock, 'timestamp' ), null, true );
	}

	/**
	 * Clear a lock's current values, in order to free it
	 */
	public static function reset_lock( $lock, $expires = 0 ) {
		wp_cache_set( self::get_key( $lock ), 0, null, $expires );
		wp_cache_set( self::get_key( $lock, 'timestamp' ), time(), null, $expires );

		return true;
	}

	/**
	 * Remove stale lock entries
	 *
	 * @param array $locks
	 * @param int   $timeout
	 * @return array
	 */
	private static function purge_stale_values( $locks, $timeout ) {
		return array_filter( $locks, function( $lock ) use( $timeout ) {
			return $lock > time() - $timeout;
		} );
	}
}
