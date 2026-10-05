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
