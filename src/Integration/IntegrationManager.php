<?php
/**
 * Third-Party Integration Manager.
 *
 * @package TF\Multilingual\Integration
 */

declare( strict_types=1 );

namespace TF\Multilingual\Integration;

use TF\Multilingual\Domain\CustomField\CustomFieldPolicyRegistry;
use TF\Multilingual\Domain\Media\MediaTranslationResolver;
use TF\Multilingual\Integration\Acf\AcfIntegration;
use TF\Multilingual\Integration\WPBakery\WPBakeryIntegration;

/**
 * Class IntegrationManager
 *
 * Coordinates optional third-party integrations (e.g. ACF, WPBakery).
 * Strictly decoupled: checks for availability before loading integration logic.
 */
class IntegrationManager {

	/**
	 * Custom field policy registry.
	 *
	 * @var CustomFieldPolicyRegistry
	 */
	private CustomFieldPolicyRegistry $policy_registry;

	/**
	 * Media translation resolver.
	 *
	 * @var MediaTranslationResolver|null
	 */
	private ?MediaTranslationResolver $media_resolver = null;

	/**
	 * ACF integration component.
	 *
	 * @var AcfIntegration|null
	 */
	private ?AcfIntegration $acf_integration = null;

	/**
	 * WPBakery integration component.
	 *
	 * @var WPBakeryIntegration|null
	 */
	private ?WPBakeryIntegration $wpbakery_integration = null;

	/**
	 * Constructor.
	 *
	 * @param CustomFieldPolicyRegistry      $policy_registry Policy registry.
	 * @param MediaTranslationResolver|null $media_resolver  Optional media translation resolver.
	 */
	public function __construct(
		CustomFieldPolicyRegistry $policy_registry,
		?MediaTranslationResolver $media_resolver = null
	) {
		$this->policy_registry = $policy_registry;
		$this->media_resolver  = $media_resolver;
	}

	/**
	 * Initializes active integrations.
	 *
	 * @return void
	 */
	public function init(): void {
		if ( $this->is_acf_active() ) {
			$this->acf_integration = new AcfIntegration( $this->policy_registry );
			$this->acf_integration->init_hooks();
		}

		if ( $this->is_wpbakery_active() ) {
			$this->wpbakery_integration = new WPBakeryIntegration(
				$this->policy_registry,
				$this->media_resolver
			);
			$this->wpbakery_integration->init_hooks();
		}
	}

	/**
	 * Checks whether Advanced Custom Fields is currently installed and active.
	 *
	 * @return bool True if ACF is active.
	 */
	public function is_acf_active(): bool {
		return class_exists( 'ACF' ) || function_exists( 'acf' );
	}

	/**
	 * Checks whether WPBakery Page Builder is currently installed and active.
	 *
	 * @return bool True if WPBakery is active.
	 */
	public function is_wpbakery_active(): bool {
		return defined( 'WPB_VC_VERSION' ) || class_exists( 'Vc_Manager' );
	}

	/**
	 * Gets the ACF integration instance, if active.
	 *
	 * @return AcfIntegration|null
	 */
	public function get_acf_integration(): ?AcfIntegration {
		return $this->acf_integration;
	}

	/**
	 * Gets the WPBakery integration instance, if active.
	 *
	 * @return WPBakeryIntegration|null
	 */
	public function get_wpbakery_integration(): ?WPBakeryIntegration {
		return $this->wpbakery_integration;
	}
}
