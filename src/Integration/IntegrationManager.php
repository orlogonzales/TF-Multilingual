<?php
/**
 * Third-Party Integration Manager.
 *
 * @package TF\Multilingual\Integration
 */

declare( strict_types=1 );

namespace TF\Multilingual\Integration;

use TF\Multilingual\Domain\CustomField\CustomFieldPolicyRegistry;
use TF\Multilingual\Integration\Acf\AcfIntegration;

/**
 * Class IntegrationManager
 *
 * Coordinates optional third-party integrations (e.g. Advanced Custom Fields).
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
	 * ACF integration component.
	 *
	 * @var AcfIntegration|null
	 */
	private ?AcfIntegration $acf_integration = null;

	/**
	 * Constructor.
	 *
	 * @param CustomFieldPolicyRegistry $policy_registry Policy registry.
	 */
	public function __construct( CustomFieldPolicyRegistry $policy_registry ) {
		$this->policy_registry = $policy_registry;
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
	 * Gets the ACF integration instance, if active.
	 *
	 * @return AcfIntegration|null
	 */
	public function get_acf_integration(): ?AcfIntegration {
		return $this->acf_integration;
	}
}
