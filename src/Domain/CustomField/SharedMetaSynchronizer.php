<?php
/**
 * Shared Meta Synchronizer Domain Service.
 *
 * @package TF\Multilingual\Domain\CustomField
 */

declare( strict_types=1 );

namespace TF\Multilingual\Domain\CustomField;

use TF\Multilingual\Domain\Translation\ContentTranslationResolver;

/**
 * Class SharedMetaSynchronizer
 *
 * Synchronizes custom fields configured with SHARE policy across all language siblings in a translation group.
 * Strictly ignores TRANSLATE and IGNORE fields, excludes revisions/autosaves, and enforces robust recursion guards.
 */
class SharedMetaSynchronizer {

	/**
	 * Custom field policy registry.
	 *
	 * @var CustomFieldPolicyRegistry
	 */
	private CustomFieldPolicyRegistry $policy_registry;

	/**
	 * Content translation resolver.
	 *
	 * @var ContentTranslationResolver
	 */
	private ContentTranslationResolver $translation_resolver;

	/**
	 * Recursion guard tracking currently synchronizing meta keys.
	 *
	 * @var array<string, bool>
	 */
	private array $syncing = array();

	/**
	 * Constructor.
	 *
	 * @param CustomFieldPolicyRegistry  $policy_registry     Policy registry.
	 * @param ContentTranslationResolver $translation_resolver Translation resolver.
	 */
	public function __construct(
		CustomFieldPolicyRegistry $policy_registry,
		ContentTranslationResolver $translation_resolver
	) {
		$this->policy_registry      = $policy_registry;
		$this->translation_resolver = $translation_resolver;
	}

	/**
	 * Registers WordPress action hooks for meta mutations.
	 *
	 * @return void
	 */
	public function init_hooks(): void {
		if ( function_exists( 'add_action' ) ) {
			add_action( 'added_post_meta', array( $this, 'on_meta_changed' ), 10, 4 );
			add_action( 'updated_post_meta', array( $this, 'on_meta_changed' ), 10, 4 );
			add_action( 'deleted_post_meta', array( $this, 'on_meta_deleted' ), 10, 4 );
		}
	}

	/**
	 * Removes WordPress action hooks.
	 *
	 * @return void
	 */
	public function remove_hooks(): void {
		if ( function_exists( 'remove_action' ) ) {
			remove_action( 'added_post_meta', array( $this, 'on_meta_changed' ), 10 );
			remove_action( 'updated_post_meta', array( $this, 'on_meta_changed' ), 10 );
			remove_action( 'deleted_post_meta', array( $this, 'on_meta_deleted' ), 10 );
		}
	}

	/**
	 * Handles added or updated post metadata.
	 *
	 * @param int    $meta_id    Meta row ID.
	 * @param int    $object_id  Post ID.
	 * @param string $meta_key   Meta key name.
	 * @param mixed  $meta_value Meta value (unslashed).
	 * @return void
	 */
	public function on_meta_changed( int $meta_id, int $object_id, string $meta_key, mixed $meta_value ): void {
		// Prevent infinite recursion.
		if ( isset( $this->syncing[ $meta_key ] ) ) {
			return;
		}

		// Exclude revisions and autosaves.
		if ( $this->is_revision_or_autosave( $object_id ) ) {
			return;
		}

		// Only synchronize fields explicitly configured with SHARE policy.
		if ( ! $this->policy_registry->is_shared( $meta_key ) ) {
			return;
		}

		// Resolve translation group. Unmanaged posts do not synchronize.
		$group = $this->translation_resolver->get_group_for_element( 'post', $object_id );
		if ( null === $group ) {
			return;
		}

		$siblings = array();
		foreach ( $group->get_elements() as $element ) {
			if ( $element->get_element_id() !== $object_id ) {
				$siblings[] = $element->get_element_id();
			}
		}

		// Single-member groups have no siblings to synchronize.
		if ( empty( $siblings ) ) {
			return;
		}

		// Set recursion guard for this meta key during propagation.
		$this->syncing[ $meta_key ] = true;

		try {
			foreach ( $siblings as $sibling_id ) {
				$slashed = function_exists( 'wp_slash' ) ? wp_slash( $meta_value ) : $meta_value;
				if ( function_exists( 'update_post_meta' ) ) {
					update_post_meta( $sibling_id, $meta_key, $slashed );
				}
			}
		} finally {
			unset( $this->syncing[ $meta_key ] );
		}
	}

	/**
	 * Handles deleted post metadata.
	 *
	 * @param array<int> $meta_ids   Array of deleted meta IDs.
	 * @param int        $object_id  Post ID.
	 * @param string     $meta_key   Meta key name.
	 * @param mixed      $meta_value Meta value prior to deletion.
	 * @return void
	 */
	public function on_meta_deleted( array $meta_ids, int $object_id, string $meta_key, mixed $meta_value ): void {
		// Prevent infinite recursion.
		if ( isset( $this->syncing[ $meta_key ] ) ) {
			return;
		}

		// Exclude revisions and autosaves.
		if ( $this->is_revision_or_autosave( $object_id ) ) {
			return;
		}

		// Only synchronize fields explicitly configured with SHARE policy.
		if ( ! $this->policy_registry->is_shared( $meta_key ) ) {
			return;
		}

		// Resolve translation group. Unmanaged posts do not synchronize.
		$group = $this->translation_resolver->get_group_for_element( 'post', $object_id );
		if ( null === $group ) {
			return;
		}

		$siblings = array();
		foreach ( $group->get_elements() as $element ) {
			if ( $element->get_element_id() !== $object_id ) {
				$siblings[] = $element->get_element_id();
			}
		}

		// Single-member groups have no siblings to synchronize.
		if ( empty( $siblings ) ) {
			return;
		}

		// Set recursion guard for this meta key during propagation.
		$this->syncing[ $meta_key ] = true;

		try {
			foreach ( $siblings as $sibling_id ) {
				if ( function_exists( 'delete_post_meta' ) ) {
					delete_post_meta( $sibling_id, $meta_key );
				}
			}
		} finally {
			unset( $this->syncing[ $meta_key ] );
		}
	}

	/**
	 * Checks if a post ID represents a revision or autosave.
	 *
	 * @param int $post_id Post ID.
	 * @return bool True if revision or autosave.
	 */
	protected function is_revision_or_autosave( int $post_id ): bool {
		if ( $post_id <= 0 ) {
			return true;
		}

		if ( function_exists( 'wp_is_post_revision' ) && false !== wp_is_post_revision( $post_id ) ) {
			return true;
		}

		if ( function_exists( 'wp_is_post_autosave' ) && false !== wp_is_post_autosave( $post_id ) ) {
			return true;
		}

		if ( function_exists( 'get_post_type' ) && 'revision' === get_post_type( $post_id ) ) {
			return true;
		}

		return false;
	}
}
