<?php
/**
 * TF Multilingual Uninstall Handler.
 *
 * @package TF\Multilingual
 */

declare( strict_types=1 );

// If uninstall not called from WordPress, exit.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Load Composer autoloader if Lifecycle class is not yet loaded.
if ( ! class_exists( 'TF\Multilingual\Infrastructure\Lifecycle' ) ) {
	$autoloader = __DIR__ . '/vendor/autoload.php';
	if ( file_exists( $autoloader ) ) {
		require_once $autoloader;
	}
}

if ( class_exists( 'TF\Multilingual\Infrastructure\Lifecycle' ) ) {
	TF\Multilingual\Infrastructure\Lifecycle::uninstall();
}
