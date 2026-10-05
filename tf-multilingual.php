<?php
/**
 * Plugin Name: TF Multilingual
 * Description: Multilingual content management for WordPress.
 * Version: 0.1.0
 * Requires at least: 6.8
 * Requires PHP: 8.1
 * Text Domain: tf-multilingual
 *
 * @package TF\Multilingual
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

// Define base technical constants.
if ( ! defined( 'TFML_VERSION' ) ) {
	define( 'TFML_VERSION', '0.1.0' );
}

if ( ! defined( 'TFML_PLUGIN_FILE' ) ) {
	define( 'TFML_PLUGIN_FILE', __FILE__ );
}

if ( ! defined( 'TFML_PLUGIN_DIR' ) ) {
	define( 'TFML_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'TFML_PLUGIN_URL' ) ) {
	define( 'TFML_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}

// Load Composer autoloader if available.
$tfml_autoloader = TFML_PLUGIN_DIR . 'vendor/autoload.php';
if ( file_exists( $tfml_autoloader ) ) {
	require_once $tfml_autoloader;
}

/**
 * Initializes TF Multilingual Core Plugin.
 *
 * @return void
 */
function tfml_init(): void {
	if ( ! class_exists( 'TF\Multilingual\Core\Plugin' ) ) {
		return;
	}

	$plugin = TF\Multilingual\Core\Plugin::get_instance();
	$plugin->init();
}
add_action( 'plugins_loaded', 'tfml_init', 0 );

// Register lifecycle hooks.
register_activation_hook(
	TFML_PLUGIN_FILE,
	array( 'TF\Multilingual\Infrastructure\Lifecycle', 'activate' )
);

register_deactivation_hook(
	TFML_PLUGIN_FILE,
	array( 'TF\Multilingual\Infrastructure\Lifecycle', 'deactivate' )
);

/**
 * Global accessor function for TF Multilingual Core Plugin.
 *
 * @return TF\Multilingual\Core\Plugin|null
 */
function tfml(): ?TF\Multilingual\Core\Plugin {
	if ( class_exists( 'TF\Multilingual\Core\Plugin' ) ) {
		return TF\Multilingual\Core\Plugin::get_instance();
	}

	return null;
}
