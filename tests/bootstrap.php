<?php
/**
 * PHPUnit Test Bootstrap.
 *
 * @package TF\Multilingual\Tests
 */

declare( strict_types=1 );

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

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
		 * Stubs charset collate retrieval.
		 *
		 * @return string
		 */
		public function get_charset_collate(): string {
			return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
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
