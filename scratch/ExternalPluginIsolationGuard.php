<?php
/**
 * External Plugin Isolation Guard for Verification Scripts.
 *
 * Temporarily isolates external plugin callbacks (e.g. WPML / SitePress)
 * that attempt to intercept WordPress Core entity lifecycle hooks (add_attachment, save_post, etc.)
 * during lab execution, preventing unauthorized writes to third-party tables (*_icl_*).
 *
 * Restores all unhooked callbacks deterministically in a finally block.
 *
 * @package TF\Multilingual
 */

declare( strict_types=1 );

namespace TF\Multilingual\Scratch;

final class ExternalPluginIsolationGuard {

	/**
	 * List of removed callbacks to restore.
	 *
	 * @var array<int, array{hook: string, priority: int, function: mixed, accepted_args: int, caller: string}>
	 */
	private array $removed_callbacks = array();

	/**
	 * Hooks to inspect and isolate.
	 *
	 * @var array<int, string>
	 */
	private array $target_hooks = array(
		'add_attachment',
		'edit_attachment',
		'attachment_updated',
		'save_post',
		'wp_insert_post',
		'wp_after_insert_post',
		'transition_post_status',
		'create_term',
		'created_term',
		'edit_term',
		'edited_term',
		'create_nav_menu',
		'wp_create_nav_menu',
		'wp_update_nav_menu_item',
		'wp_add_nav_menu_item',
		'set_object_terms',
		'wp_get_nav_menu_items',
	);

	/**
	 * Identifiers of external plugins to isolate from fixture creation.
	 *
	 * @var array<int, string>
	 */
	private array $isolated_prefixes = array(
		'wpml',
		'sitepress',
		'icl',
	);

	/**
	 * Inspects global hooks and removes external plugin callbacks.
	 *
	 * @return int Number of callbacks isolated.
	 */
	public function isolate(): int {
		global $wp_filter;

		$count = 0;
		foreach ( $this->target_hooks as $hook ) {
			if ( ! isset( $wp_filter[ $hook ] ) ) {
				continue;
			}

			foreach ( $wp_filter[ $hook ]->callbacks as $prio => $callbacks ) {
				foreach ( $callbacks as $cb_id => $cb_data ) {
					$fn     = $cb_data['function'];
					$caller = $this->resolve_caller_name( $fn );

					if ( $this->should_isolate( $caller ) ) {
						$this->removed_callbacks[] = array(
							'hook'          => $hook,
							'priority'      => (int) $prio,
							'function'      => $fn,
							'accepted_args' => (int) $cb_data['accepted_args'],
							'caller'        => $caller,
						);
						remove_action( $hook, $fn, (int) $prio );
						$count++;
					}
				}
			}
		}

		return $count;
	}

	/**
	 * Restores all isolated callbacks back to their original hooks and priorities.
	 *
	 * @return int Number of callbacks restored.
	 */
	public function restore(): int {
		$count = 0;
		foreach ( $this->removed_callbacks as $cb ) {
			add_action( $cb['hook'], $cb['function'], $cb['priority'], $cb['accepted_args'] );
			$count++;
		}
		$this->removed_callbacks = array();

		return $count;
	}

	/**
	 * Gets count of currently isolated callbacks.
	 */
	public function get_isolated_count(): int {
		return count( $this->removed_callbacks );
	}

	/**
	 * Determines if a callback caller string matches isolated external plugin signatures.
	 */
	private function should_isolate( string $caller ): bool {
		$lower = strtolower( $caller );
		foreach ( $this->isolated_prefixes as $prefix ) {
			if ( str_contains( $lower, $prefix ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Resolves human-readable caller name.
	 *
	 * @param mixed $fn Callback function/method.
	 */
	private function resolve_caller_name( mixed $fn ): string {
		if ( is_array( $fn ) ) {
			$class = is_object( $fn[0] ) ? get_class( $fn[0] ) : (string) $fn[0];
			$method = (string) ( $fn[1] ?? '' );
			return $class . '::' . $method;
		}

		if ( is_string( $fn ) ) {
			return $fn;
		}

		if ( $fn instanceof \Closure ) {
			try {
				$ref = new \ReflectionFunction( $fn );
				return 'Closure@' . (string) $ref->getFileName();
			} catch ( \Throwable ) {
				return 'Closure';
			}
		}

		return 'Unknown';
	}
}
