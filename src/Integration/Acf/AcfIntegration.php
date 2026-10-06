<?php
/**
 * Advanced Custom Fields (ACF) Integration Component.
 *
 * @package TF\Multilingual\Integration\Acf
 */

declare( strict_types=1 );

namespace TF\Multilingual\Integration\Acf;

use TF\Multilingual\Domain\CustomField\CustomFieldPolicy;
use TF\Multilingual\Domain\CustomField\CustomFieldPolicyRegistry;

/**
 * Class AcfIntegration
 *
 * Integrates TF Multilingual with Advanced Custom Fields (ACF Free).
 * Decoupled: does not hard-depend on ACF and only activates when ACF is present.
 */
class AcfIntegration {

	/**
	 * Custom field policy registry.
	 *
	 * @var CustomFieldPolicyRegistry
	 */
	private CustomFieldPolicyRegistry $policy_registry;

	/**
	 * Constructor.
	 *
	 * @param CustomFieldPolicyRegistry $policy_registry Policy registry.
	 */
	public function __construct( CustomFieldPolicyRegistry $policy_registry ) {
		$this->policy_registry = $policy_registry;
	}

	/**
	 * Registers WordPress action and filter hooks for ACF.
	 *
	 * @return void
	 */
	public function init_hooks(): void {
		if ( function_exists( 'add_action' ) ) {
			add_action( 'acf/render_field_settings', array( $this, 'render_field_settings' ), 10, 1 );
			add_action( 'acf/delete_field', array( $this, 'delete_field' ), 10, 1 );
		}

		if ( function_exists( 'add_filter' ) ) {
			add_filter( 'acf/load_field', array( $this, 'load_field' ), 10, 1 );
			add_filter( 'acf/update_field', array( $this, 'update_field' ), 10, 1 );
		}
	}

	/**
	 * Removes WordPress action and filter hooks.
	 *
	 * @return void
	 */
	public function remove_hooks(): void {
		if ( function_exists( 'remove_action' ) ) {
			remove_action( 'acf/render_field_settings', array( $this, 'render_field_settings' ), 10 );
			remove_action( 'acf/delete_field', array( $this, 'delete_field' ), 10 );
		}

		if ( function_exists( 'remove_filter' ) ) {
			remove_filter( 'acf/load_field', array( $this, 'load_field' ), 10 );
			remove_filter( 'acf/update_field', array( $this, 'update_field' ), 10 );
		}
	}

	/**
	 * Renders TFML policy setting inside the ACF field edit screen.
	 *
	 * @param array<string, mixed> $field ACF field array.
	 * @return void
	 */
	public function render_field_settings( array $field ): void {
		if ( ! function_exists( 'acf_render_field_setting' ) ) {
			return;
		}

		$storage_key = $this->get_field_storage_key( $field );
		$current     = $this->policy_registry->get_policy( $storage_key );

		if ( isset( $field['tfml_policy'] ) && '' !== $field['tfml_policy'] ) {
			$current = (string) $field['tfml_policy'];
		}

		$setting = array(
			'label'         => __( 'Política Multilingüe (TFML)', 'tf-multilingual' ),
			'instructions'  => __( 'Define cómo gestiona TF Multilingual este campo entre las traducciones.', 'tf-multilingual' ),
			'name'          => 'tfml_policy',
			'type'          => 'select',
			'choices'       => array(
				CustomFieldPolicy::IGNORE    => __( 'Ignorar (sin gestión)', 'tf-multilingual' ),
				CustomFieldPolicy::TRANSLATE => __( 'Traducir (independiente)', 'tf-multilingual' ),
				CustomFieldPolicy::SHARE     => __( 'Compartir (sincronizar)', 'tf-multilingual' ),
			),
			'default_value' => CustomFieldPolicy::IGNORE,
			'value'         => $current,
		);

		acf_render_field_setting( $field, $setting, true );
	}

	/**
	 * Filters an ACF field when loaded, ensuring tfml_policy reflects the sovereign registry.
	 *
	 * @param array<string, mixed> $field ACF field array.
	 * @return array<string, mixed> Filtered field array.
	 */
	public function load_field( array $field ): array {
		$storage_key = $this->get_field_storage_key( $field );
		if ( '' !== $storage_key && ! isset( $field['tfml_policy'] ) ) {
			$field['tfml_policy'] = $this->policy_registry->get_policy( $storage_key );
		}

		return $field;
	}

	/**
	 * Filters an ACF field before it is saved, updating the sovereign CustomFieldPolicyRegistry.
	 *
	 * @param array<string, mixed> $field ACF field array.
	 * @return array<string, mixed> Updated field array.
	 */
	public function update_field( array $field ): array {
		$storage_key = $this->get_field_storage_key( $field );
		if ( '' === $storage_key ) {
			return $field;
		}

		if ( isset( $field['tfml_policy'] ) ) {
			$policy = strtolower( trim( (string) $field['tfml_policy'] ) );
			$valid  = array(
				CustomFieldPolicy::TRANSLATE,
				CustomFieldPolicy::SHARE,
				CustomFieldPolicy::IGNORE,
			);

			if ( in_array( $policy, $valid, true ) ) {
				$this->policy_registry->set_policy( $storage_key, $policy );
				$this->sync_reference_key( $storage_key, $policy );
				$this->policy_registry->persist();
			}
		}

		return $field;
	}

	/**
	 * Handles deletion of an ACF field, removing its policy and paired reference policy.
	 *
	 * @param array<string, mixed> $field ACF field array.
	 * @return void
	 */
	public function delete_field( array $field ): void {
		$storage_key = $this->get_field_storage_key( $field );
		if ( '' === $storage_key ) {
			return;
		}

		$this->policy_registry->remove_policy( $storage_key );
		$this->policy_registry->remove_policy( '_' . $storage_key );
		$this->policy_registry->persist();
	}

	/**
	 * Synchronizes the reference meta key (_{storage_key}) policy in the sovereign registry.
	 *
	 * @param string $storage_key Field storage key.
	 * @param string $policy      Policy (TRANSLATE, SHARE, IGNORE).
	 * @return void
	 */
	public function sync_reference_key( string $storage_key, string $policy ): void {
		$ref_key = '_' . $storage_key;
		if ( CustomFieldPolicy::SHARE === $policy ) {
			$this->policy_registry->set_policy( $ref_key, CustomFieldPolicy::SHARE );
		} else {
			$this->policy_registry->remove_policy( $ref_key );
		}
	}

	/**
	 * Recursion guard for field resolution.
	 *
	 * @var array<string, bool>
	 */
	private array $resolving = array();

	/**
	 * Resolves the physical wp_postmeta storage key for an ACF field, including nested groups.
	 *
	 * @param array<string, mixed> $field ACF field array.
	 * @return string Physical meta_key used in wp_postmeta.
	 */
	public function get_field_storage_key( array $field ): string {
		$name = (string) ( $field['name'] ?? '' );
		if ( '' === $name ) {
			return '';
		}

		$parent = $field['parent'] ?? 0;
		// Only recurse if parent is an actual ACF field key (starts with 'field_').
		// Field groups start with 'group_' or are numeric post IDs.
		if ( ! empty( $parent ) && is_string( $parent ) && str_starts_with( $parent, 'field_' ) ) {
			if ( ! isset( $this->resolving[ $parent ] ) && function_exists( 'acf_get_field' ) ) {
				$this->resolving[ $parent ] = true;
				try {
					$parent_field = acf_get_field( $parent );
					if ( is_array( $parent_field ) && ! empty( $parent_field['name'] ) ) {
						$parent_key = $this->get_field_storage_key( $parent_field );
						if ( '' !== $parent_key ) {
							return $parent_key . '_' . $name;
						}
					}
				} finally {
					unset( $this->resolving[ $parent ] );
				}
			}
		}

		return $name;
	}
}
