<?php
/**
 * Plugin Lifecycle Handler.
 *
 * @package TF\Multilingual\Infrastructure
 */

declare( strict_types=1 );

namespace TF\Multilingual\Infrastructure;

use TF\Multilingual\Core\Plugin;
use TF\Multilingual\Infrastructure\Persistence\SchemaManager;
use Throwable;

/**
 * Class Lifecycle
 *
 * Handles activation, deactivation and baseline system verification.
 */
class Lifecycle {

	/**
	 * Executes activation tasks.
	 *
	 * In Phase 1.1, activation validates runtime requirements and executes
	 * idempotent database schema installation via SchemaManager.
	 *
	 * @param bool $network_wide Whether the plugin is being activated network-wide in Multisite.
	 * @return void
	 */
	public static function activate( bool $network_wide = false ): void {
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

		// Multisite network activation guard: per-site activation only in MVP.
		if ( is_multisite() && $network_wide ) {
			deactivate_plugins( plugin_basename( TFML_PLUGIN_FILE ) );
			wp_die(
				esc_html__( 'TF Multilingual network activation is not supported in this version. Please activate on individual sites.', 'tf-multilingual' ),
				esc_html__( 'Network Activation Unsupported', 'tf-multilingual' ),
				array( 'back_link' => true )
			);
		}

		// Execute database schema installation.
		try {
			$schema_manager = new SchemaManager();
			$schema_manager->install();
		} catch ( Throwable $e ) {
			deactivate_plugins( plugin_basename( TFML_PLUGIN_FILE ) );
			wp_die(
				sprintf(
					/* translators: %s: Error message detail */
					esc_html__( 'TF Multilingual database schema installation failed: %s', 'tf-multilingual' ),
					esc_html( $e->getMessage() )
				),
				esc_html__( 'Database Installation Error', 'tf-multilingual' ),
				array( 'back_link' => true )
			);
		}
	}

	/**
	 * Executes deactivation tasks.
	 *
	 * Strictly non-destructive: preserves all tables, settings and content.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		// Clean transitories or temporary caches if needed.
		// Content, relations and schema remain 100% intact.
	}
}
