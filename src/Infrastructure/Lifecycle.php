<?php
/**
 * Plugin Lifecycle Handler.
 *
 * @package TF\Multilingual\Infrastructure
 */

declare( strict_types=1 );

namespace TF\Multilingual\Infrastructure;

use TF\Multilingual\Core\Plugin;

/**
 * Class Lifecycle
 *
 * Handles activation, deactivation and baseline system verification.
 */
class Lifecycle {

	/**
	 * Executes activation tasks.
	 *
	 * In Phase 1.0, activation only verifies system requirements.
	 * Schema creation and table migrations are deferred to subsequent microfases.
	 *
	 * @return void
	 */
	public static function activate(): void {
		global $wp_version;

		$plugin = Plugin::get_instance();
		if ( ! $plugin->check_requirements( PHP_VERSION, (string) $wp_version ) ) {
			deactivate_plugins( plugin_basename( TFML_PLUGIN_FILE ) );
			wp_die(
				esc_html__( 'TF Multilingual requires PHP 8.1+ and WordPress 6.8+.', 'tf-multilingual' ),
				esc_html__( 'Plugin Activation Error', 'tf-multilingual' ),
				array( 'back_link' => true )
			);
		}
	}

	/**
	 * Executes deactivation tasks.
	 *
	 * Preserves all domain data and settings.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		// Clean transitories or temporary caches if needed.
		// Content and relations remain 100% intact.
	}
}
