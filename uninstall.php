<?php
/**
 * TF Multilingual Uninstall Handler.
 *
 * @package TF\Multilingual
 */

declare( strict_types=1 );

// If uninstall not called from WordPress, exit.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/*
 * In Phase 1.0 (Baseline), uninstallation preserves all data.
 * Destructive data purging and optional table cleanup will be implemented
 * in subsequent microfases according to the approved architecture.
 */
