<?php
/**
 * Concurrency locks
 *
 * @package a8c_Cron_Control
 */

namespace Automattic\WP\Cron_Control;

/**
 * Lock class
 */
class Lock {
	private static $acquired_locks = array();

	/**
	 * Set a lock and limit how many concurrent jobs are permitted
	 *
	 * @param string $lock  Lock name.
	 * @param int    $limit Concurrency limit.
	 * @param int    $timeout Timeout in seconds.
	 * @return bool
	 */
	public static function check_lock( $lock, $limit = null, $timeout = null ) {
		global $wpdb;

		if ( ! is_numeric( $timeout ) ) {
			$timeout = LOCK_DEFAULT_TIMEOUT_IN_MINUTES * \MINUTE_IN_SECONDS;
		}

		if ( ! is_numeric( $limit ) ) {
			$limit = LOCK_DEFAULT_LIMIT;
		}

		$now          = time();
		$stale_before = $now - $timeout;
		$generation   = self::get_next_generation();

		// LAST_INSERT_ID() retains the generation from this atomic upsert on this database connection.
		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO `$wpdb->options` (`option_name`, `option_value`, `autoload`)
				VALUES (%s, CONCAT(1, ':', %d, ':', LAST_INSERT_ID(%d)), 'no')
				ON DUPLICATE KEY UPDATE `option_value` = IF(
					CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(`option_value`, ':', 2), ':', -1) AS UNSIGNED) < %d,
					CONCAT(1, ':', %d, ':', LAST_INSERT_ID(%d)),
					IF(
						CAST(SUBSTRING_INDEX(`option_value`, ':', 1) AS UNSIGNED) < %d,
						CONCAT(CAST(SUBSTRING_INDEX(`option_value`, ':', 1) AS UNSIGNED) + 1, ':', %d, ':', LAST_INSERT_ID(CAST(SUBSTRING_INDEX(`option_value`, ':', -1) AS UNSIGNED))),
						`option_value`
					)
				)",
				self::get_key( $lock ),
				$now,
				$generation,
				$stale_before,
				$now,
				$generation,
				$limit,
				$now
			)
		);

		if ( $result <= 0 ) {
			return false;
		}

		$generation = (int) $wpdb->insert_id;
		if ( $generation < 1 ) {
			return false;
		}

		if ( ! isset( self::$acquired_locks[ $lock ] ) ) {
			self::$acquired_locks[ $lock ] = array();
		}
		self::$acquired_locks[ $lock ][] = $generation;

		return true;
	}

	/**
	 * When event completes, allow another
	 *
	 * @param string $lock Lock name.
	 * @param int    $expires Lock expiration timestamp.
	 * @return bool
	 */
	public static function free_lock( $lock, $expires = 0 ) {
		global $wpdb;

		if ( empty( self::$acquired_locks[ $lock ] ) ) {
			return false;
		}

		$generation = array_pop( self::$acquired_locks[ $lock ] );
		$now        = time();

		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE `$wpdb->options`
				SET `option_value` = CONCAT(GREATEST(CAST(SUBSTRING_INDEX(`option_value`, ':', 1) AS UNSIGNED) - 1, 0), ':', %d, ':', %d)
				WHERE `option_name` = %s
					AND CAST(SUBSTRING_INDEX(`option_value`, ':', 1) AS UNSIGNED) > 0
					AND CAST(SUBSTRING_INDEX(`option_value`, ':', -1) AS UNSIGNED) = %d",
				$now,
				$generation,
				self::get_key( $lock ),
				$generation
			)
		);

		if ( $result > 0 ) {
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM `$wpdb->options`
					WHERE `option_name` = %s
						AND `option_value` = %s",
					self::get_key( $lock ),
					self::build_lock_state( 0, $now, $generation )
				)
			);
		}

		return false !== $result;
	}

	/**
	 * Build cache key
	 *
	 * @param string $lock Lock name.
	 * @return string
	 */
	private static function get_key( $lock ) {
		return "a8ccc_lock_{$lock}";
	}

	/**
	 * Ensure lock entries are initially set
	 *
	 * @param string $lock Lock name.
	 * @param int    $expires Lock expiration timestamp.
	 * @return null
	 */
	public static function prime_lock( $lock, $expires = 0 ) {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO `$wpdb->options` (`option_name`, `option_value`, `autoload`) VALUES (%s, %s, 'no')",
				self::get_key( $lock ),
				self::build_lock_state( 0, time(), self::get_next_generation() )
			)
		);

		return null;
	}

	/**
	 * Retrieve a lock from cache
	 *
	 * @param string $lock Lock name.
	 * @return int
	 */
	public static function get_lock_value( $lock ) {
		$state = self::get_lock_state( $lock );
		return null === $state ? 0 : $state['count'];
	}

	/**
	 * Retrieve a lock's timestamp
	 *
	 * @param string $lock Lock name.
	 * @return int
	 */
	public static function get_lock_timestamp( $lock ) {
		$state = self::get_lock_state( $lock );
		return null === $state ? 0 : $state['timestamp'];
	}

	/**
	 * Clear a lock's current values, in order to free it
	 *
	 * @param string $lock Lock name.
	 * @param int    $expires Lock expiration timestamp.
	 * @return bool
	 */
	public static function reset_lock( $lock, $expires = 0 ) {
		global $wpdb;

		$now        = time();
		$generation = self::get_next_generation();
		$result     = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO `$wpdb->options` (`option_name`, `option_value`, `autoload`)
				VALUES (%s, %s, 'no')
				ON DUPLICATE KEY UPDATE `option_value` = CONCAT(0, ':', %d, ':', %d)",
				self::get_key( $lock ),
				self::build_lock_state( 0, $now, $generation ),
				$now,
				$generation
			)
		);

		unset( self::$acquired_locks[ $lock ] );
		return false !== $result;
	}

	/**
	 * Get the lock state from its option row.
	 *
	 * @param string $lock Lock name.
	 * @return array|null
	 */
	private static function get_lock_state( $lock ) {
		global $wpdb;

		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT `option_value` FROM `$wpdb->options` WHERE `option_name` = %s",
				self::get_key( $lock )
			)
		);

		return self::parse_lock_state( $value );
	}

	/**
	 * Parse a lock state from its stored value.
	 *
	 * @param string|null $value Stored lock value.
	 * @return array|null
	 */
	private static function parse_lock_state( $value ) {
		$state = explode( ':', (string) $value );
		if ( 3 !== count( $state ) || ! ctype_digit( $state[0] ) || ! ctype_digit( $state[1] ) || ! ctype_digit( $state[2] ) ) {
			return null;
		}

		return array(
			'count'      => (int) $state[0],
			'timestamp'  => (int) $state[1],
			'generation' => (int) $state[2],
		);
	}

	/**
	 * Build a value suitable for storage in the lock option.
	 *
	 * @param int $count Lock count.
	 * @param int $timestamp Lock timestamp.
	 * @param int $generation Lock generation.
	 * @return string
	 */
	private static function build_lock_state( $count, $timestamp, $generation ) {
		return "{$count}:{$timestamp}:{$generation}";
	}

	/**
	 * Get a non-zero lock-row generation.
	 *
	 * @return int
	 */
	private static function get_next_generation() {
		return wp_rand( 1, \PHP_INT_MAX );
	}
}
