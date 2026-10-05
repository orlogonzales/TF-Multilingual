<?php
/**
 * PHPUnit Test Bootstrap.
 *
 * @package TF\Multilingual\Tests
 */

declare( strict_types=1 );

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

// Stub minimal WordPress global class wpdb if running pure unit tests without Core loaded.
if ( ! class_exists( 'wpdb' ) ) {
	/**
	 * Minimal stub for wpdb class.
	 */
	// phpcs:ignore PEAR.NamingConventions.ValidClassName.StartWithCapital
	class wpdb {

		/**
		 * Table prefix.
		 *
		 * @var string
		 */
		public string $prefix = 'wp_';

		/**
		 * Last insert ID.
		 *
		 * @var int
		 */
		public int $insert_id = 0;

		/**
		 * Last error string.
		 *
		 * @var string
		 */
		public string $last_error = '';

		/**
		 * Stubs charset collate retrieval.
		 *
		 * @return string
		 */
		public function get_charset_collate(): string {
			return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
		}

		/**
		 * Stubs query preparation.
		 *
		 * @param string $query Query template.
		 * @param mixed  ...$args Query arguments.
		 * @return string
		 */
		public function prepare( string $query, ...$args ): string {
			if ( isset( $args[0] ) && is_array( $args[0] ) && 1 === count( $args ) ) {
				$args = $args[0];
			}

			$pattern = '/(%[sdfF])/';
			$parts   = preg_split( $pattern, $query, -1, PREG_SPLIT_DELIM_CAPTURE );
			if ( false === $parts ) {
				return $query;
			}

			$result  = '';
			$arg_idx = 0;
			foreach ( $parts as $part ) {
				if ( '%s' === $part ) {
					$result .= "'" . addslashes( (string) ( $args[ $arg_idx++ ] ?? '' ) ) . "'";
				} elseif ( '%d' === $part ) {
					$result .= (int) ( $args[ $arg_idx++ ] ?? 0 );
				} elseif ( '%f' === $part || '%F' === $part ) {
					$result .= (float) ( $args[ $arg_idx++ ] ?? 0.0 );
				} else {
					$result .= $part;
				}
			}

			return $result;
		}

		/**
		 * Stubs insert method.
		 *
		 * @param string               $table  Table name.
		 * @param array<string, mixed> $data   Data array.
		 * @param array<string>        $format Format array.
		 * @return int|false
		 */
		public function insert( string $table, array $data, array $format = array() ): int|false {
			return 1;
		}

		/**
		 * Stubs update method.
		 *
		 * @param string               $table        Table name.
		 * @param array<string, mixed> $data         Data array.
		 * @param array<string, mixed> $where        Where array.
		 * @param array<string>        $format       Format array.
		 * @param array<string>        $where_format Where format array.
		 * @return int|false
		 */
		public function update(
			string $table,
			array $data,
			array $where,
			array $format = array(),
			array $where_format = array()
		): int|false {
			return 1;
		}

		/**
		 * Stubs delete method.
		 *
		 * @param string               $table        Table name.
		 * @param array<string, mixed> $where        Where array.
		 * @param array<string>        $where_format Where format array.
		 * @return int|false
		 */
		public function delete( string $table, array $where, array $where_format = array() ): int|false {
			return 1;
		}

		/**
		 * Stubs get_row method.
		 *
		 * @param string $query  SQL query.
		 * @param string $output Output format.
		 * @return mixed
		 */
		public function get_row( string $query, string $output = 'OBJECT' ): mixed {
			return null;
		}

		/**
		 * Stubs get_var method.
		 *
		 * @param string $query SQL query.
		 * @return mixed
		 */
		public function get_var( string $query ): mixed {
			return null;
		}

		/**
		 * Stubs get_results method.
		 *
		 * @param string $query  SQL query.
		 * @param string $output Output format.
		 * @return mixed
		 */
		public function get_results( string $query, string $output = 'OBJECT' ): mixed {
			return array();
		}

		/**
		 * Stubs query method.
		 *
		 * @param string $query SQL query.
		 * @return int|bool
		 */
		public function query( string $query ): int|bool {
			return true;
		}
	}
}

// In-memory options storage for unit tests.
$GLOBALS['wp_test_options'] = array();

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * Stub for get_option.
	 *
	 * @param string $option        Option key.
	 * @param mixed  $default_value Default value.
	 * @return mixed
	 */
	function get_option( string $option, mixed $default_value = false ): mixed {
		return $GLOBALS['wp_test_options'][ $option ] ?? $default_value;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	/**
	 * Stub for update_option.
	 *
	 * @param string $option   Option key.
	 * @param mixed  $value    Option value.
	 * @param mixed  $autoload Autoload flag.
	 * @return bool
	 */
	function update_option( string $option, mixed $value, mixed $autoload = null ): bool {
		$GLOBALS['wp_test_options'][ $option ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	/**
	 * Stub for delete_option.
	 *
	 * @param string $option Option key.
	 * @return bool
	 */
	function delete_option( string $option ): bool {
		if ( array_key_exists( $option, $GLOBALS['wp_test_options'] ) ) {
			unset( $GLOBALS['wp_test_options'][ $option ] );
			return true;
		}
		return false;
	}
}

if ( ! function_exists( 'current_time' ) ) {
	/**
	 * Stub for current_time.
	 *
	 * @param string $type Timestamp type.
	 * @param mixed  $gmt  GMT flag.
	 * @return string
	 */
	function current_time( string $type, mixed $gmt = 0 ): string {
		return gmdate( 'Y-m-d H:i:s' );
	}
}
