<?php
/**
 * Main Plugin Orchestrator.
 *
 * @package TF\Multilingual\Core
 */

declare( strict_types=1 );

namespace TF\Multilingual\Core;

/**
 * Class Plugin
 *
 * Coordinates initialization and lifecycle verification for TF Multilingual.
 */
class Plugin {

	/**
	 * Plugin technical version.
	 */
	public const VERSION = '0.1.0';

	/**
	 * Minimum supported PHP version.
	 */
	public const MIN_PHP_VERSION = '8.1';

	/**
	 * Minimum supported WordPress version.
	 */
	public const MIN_WP_VERSION = '6.8';

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Initialization state flag.
	 *
	 * @var bool
	 */
	private bool $initialized = false;

	/**
	 * Retrieves the singleton instance.
	 *
	 * @return self
	 */
	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Resets the singleton instance (primarily for testing purposes).
	 */
	public static function reset_instance(): void {
		self::$instance = null;
	}

	/**
	 * Protected constructor to prevent direct instantiation.
	 */
	protected function __construct() {
		// Constructor intentionally left minimal in Phase 1.0.
	}

	/**
	 * Initializes the plugin subsystems.
	 *
	 * @return void
	 */
	public function init(): void {
		if ( $this->initialized ) {
			return;
		}

		$this->initialized = true;
	}

	/**
	 * Returns whether the plugin has been initialized.
	 *
	 * @return bool
	 */
	public function is_initialized(): bool {
		return $this->initialized;
	}

	/**
	 * Gets the current technical plugin version.
	 *
	 * @return string
	 */
	public function get_version(): string {
		return self::VERSION;
	}

	/**
	 * Validates environment requirements against runtime versions.
	 *
	 * @param string $current_php_version Current PHP version.
	 * @param string $current_wp_version  Current WordPress version.
	 * @return bool True if requirements are satisfied.
	 */
	public function check_requirements( string $current_php_version, string $current_wp_version ): bool {
		$php_valid = version_compare( $current_php_version, self::MIN_PHP_VERSION, '>=' );
		$wp_valid  = version_compare( $current_wp_version, self::MIN_WP_VERSION, '>=' );

		return $php_valid && $wp_valid;
	}
}
