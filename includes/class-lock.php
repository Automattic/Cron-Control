<?php
/**
 * Concurrency locks.
 *
 * @package a8c_Cron_Control
 */

namespace Automattic\WP\Cron_Control;

/** Atomic per-owner leases stored in the existing options table. */
class Lock {
	private static $acquired_locks = array();
	private const MAX_ATTEMPTS = 20;

	/**
	 * Acquire a lease when the number of active owners is below the limit.
	 *
	 * The whole owner list is replaced only if the stored state still matches
	 * what we read. Each revision changes even when a state returns to the same
	 * owner count, preventing an older snapshot from matching a replacement row.
	 * Contended updates retry a bounded number of times and then fail closed.
	 */
	public static function check_lock( $lock, $limit = null, $timeout = null ) {
		global $wpdb;

		$limit = is_numeric( $limit ) ? (int) $limit : LOCK_DEFAULT_LIMIT;
		$timeout = is_numeric( $timeout ) ? (int) $timeout : LOCK_DEFAULT_TIMEOUT_IN_MINUTES * \MINUTE_IN_SECONDS;
		if ( $limit < 1 || $timeout < 1 ) {
			return false;
		}

		$table = $wpdb->options;
		$key = self::get_key( $lock );
		$token = self::generate_id();

		for ( $attempt = 0; $attempt < self::MAX_ATTEMPTS; ++$attempt ) {
			$previous = self::read_state( $table, $key );
			if ( false === $previous ) {
				return false;
			}

			$now = time();
			if ( null === $previous ) {
				$state = array( 'updated' => $now, 'revision' => self::generate_id(), 'owners' => array( $token => $now + $timeout ) );
				$result = $wpdb->query( $wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name comes from wpdb.
					"INSERT IGNORE INTO `$table` (`option_name`, `option_value`, `autoload`) VALUES (%s, %s, 'no')",
					$key,
					wp_json_encode( $state )
				) );
			} else {
				$state = self::decode_state( $previous );
				if ( null === $state ) {
					return false;
				}
				if ( isset( $state['owners'][ $token ] ) ) {
					$token = self::generate_id();
					continue;
				}
				$state['owners'] = array_filter( $state['owners'], static function ( $expiry ) use ( $now ) {
					return $expiry >= $now;
				} );
				if ( count( $state['owners'] ) >= $limit ) {
					return false;
				}
				$state['owners'][ $token ] = $now + $timeout;
				$state['updated'] = $now;
				$state['revision'] = self::generate_id();
				$result = self::replace_state( $table, $key, $previous, $state );
			}

			if ( false === $result ) {
				return false;
			}
			if ( $result > 0 ) {
				self::$acquired_locks[ $table ][ $lock ][] = $token;
				return true;
			}
			usleep( 1000 );
		}

		return false;
	}

	/**
	 * Release only a lease acquired in the current request and specified site.
	 *
	 * @param string   $lock Lock name.
	 * @param int      $expires Retained for compatibility; leases carry their own expiry.
	 * @param int|null $site_id Original site, or null for the current site.
	 * @return bool
	 */
	public static function free_lock( $lock, $expires = 0, $site_id = null ) {
		global $wpdb;
		$table = null === $site_id ? $wpdb->options : $wpdb->get_blog_prefix( (int) $site_id ) . 'options';
		if ( empty( self::$acquired_locks[ $table ][ $lock ] ) ) {
			return false;
		}
		$token = end( self::$acquired_locks[ $table ][ $lock ] );
		$key = self::get_key( $lock );

		for ( $attempt = 0; $attempt < self::MAX_ATTEMPTS; ++$attempt ) {
			$previous = self::read_state( $table, $key );
			if ( false === $previous ) {
				return false;
			}
			if ( null === $previous ) {
				array_pop( self::$acquired_locks[ $table ][ $lock ] );
				return true;
			}
			$state = self::decode_state( $previous );
			if ( null === $state ) {
				return false;
			}
			if ( ! isset( $state['owners'][ $token ] ) ) {
				array_pop( self::$acquired_locks[ $table ][ $lock ] );
				return true;
			}

			unset( $state['owners'][ $token ] );
			$state['updated'] = time();
			$state['revision'] = self::generate_id();
			$result = self::replace_state( $table, $key, $previous, $state );
			if ( false === $result ) {
				return false;
			}
			if ( $result > 0 ) {
				if ( empty( $state['owners'] ) ) {
					$wpdb->query( $wpdb->prepare(
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name comes from wpdb.
						"DELETE FROM `$table` WHERE `option_name` = %s AND BINARY `option_value` = BINARY %s",
						$key,
						wp_json_encode( $state )
					) );
				}
				array_pop( self::$acquired_locks[ $table ][ $lock ] );
				return true;
			}
			usleep( 1000 );
		}

		return false;
	}

	/** Compatibility no-op: acquisition creates the state. */
	public static function prime_lock( $lock, $expires = 0 ) {
		return null;
	}

	/** Return the number of unexpired leases. */
	public static function get_lock_value( $lock ) {
		global $wpdb;
		$state = self::decode_state( self::read_state( $wpdb->options, self::get_key( $lock ) ) );
		if ( null === $state ) {
			return 0;
		}
		$now = time();
		return count( array_filter( $state['owners'], static function ( $expiry ) use ( $now ) {
			return $expiry >= $now;
		} ) );
	}

	/** Return the last state change time. */
	public static function get_lock_timestamp( $lock ) {
		global $wpdb;
		$state = self::decode_state( self::read_state( $wpdb->options, self::get_key( $lock ) ) );
		return null === $state ? 0 : $state['updated'];
	}

	/** Clear the lock, invalidating all existing owners. */
	public static function reset_lock( $lock, $expires = 0 ) {
		global $wpdb;
		$table = $wpdb->options;
		$state = wp_json_encode( array( 'updated' => time(), 'revision' => self::generate_id(), 'owners' => array() ) );
		$result = $wpdb->query( $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name comes from wpdb.
			"INSERT INTO `$table` (`option_name`, `option_value`, `autoload`) VALUES (%s, %s, 'no') ON DUPLICATE KEY UPDATE `option_value` = %s",
			self::get_key( $lock ),
			$state,
			$state
		) );
		if ( false !== $result ) {
			unset( self::$acquired_locks[ $table ][ $lock ] );
		}
		return false !== $result;
	}

	private static function get_key( $lock ) {
		return "a8ccc_lock_{$lock}";
	}

	private static function generate_id() {
		return sprintf( '%08x%08x%08x%08x', \wp_rand( 0, 0x7fffffff ), \wp_rand( 0, 0x7fffffff ), \wp_rand( 0, 0x7fffffff ), \wp_rand( 0, 0x7fffffff ) );
	}

	/**
	 * Read the authoritative row; HyperDB otherwise may route reads to a replica.
	 *
	 * @return string|null|false Stored state, null when absent, false on error.
	 */
	private static function read_state( $table, $key ) {
		global $wpdb;
		$has_srtm = property_exists( $wpdb, 'srtm' );
		if ( $has_srtm ) {
			$previous_srtm = $wpdb->srtm;
			$wpdb->srtm = true;
		}
		try {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name comes from wpdb.
			$value = $wpdb->get_var( $wpdb->prepare( "SELECT `option_value` FROM `$table` WHERE `option_name` = %s", $key ) );
			return ! empty( $wpdb->last_error ) ? false : $value;
		} finally {
			if ( $has_srtm ) {
				$wpdb->srtm = $previous_srtm;
			}
		}
	}

	/** Replace only the exact state that was read. */
	private static function replace_state( $table, $key, $previous, $state ) {
		global $wpdb;
		return $wpdb->query( $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name comes from wpdb.
			"UPDATE `$table` SET `option_value` = %s WHERE `option_name` = %s AND BINARY `option_value` = BINARY %s",
			wp_json_encode( $state ),
			$key,
			$previous
		) );
	}

	private static function decode_state( $value ) {
		if ( ! is_string( $value ) ) {
			return null;
		}
		$state = json_decode( $value, true );
		if ( ! is_array( $state ) || ! isset( $state['updated'], $state['revision'], $state['owners'] ) || ! is_int( $state['updated'] ) || ! is_string( $state['revision'] ) || ! is_array( $state['owners'] ) ) {
			return null;
		}
		foreach ( $state['owners'] as $owner => $expiry ) {
			if ( ! is_string( $owner ) || ! is_int( $expiry ) ) {
				return null;
			}
		}
		return $state;
	}
}
