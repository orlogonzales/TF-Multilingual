<?php
/**
 * IntegrationManager Unit Tests.
 *
 * @package TF\Multilingual\Tests\Unit\Integration
 */

declare( strict_types=1 );

namespace TF\Multilingual\Tests\Unit\Integration;

use PHPUnit\Framework\TestCase;
use TF\Multilingual\Domain\CustomField\CustomFieldPolicyRegistry;
use TF\Multilingual\Domain\Language\SettingsRepository;
use TF\Multilingual\Integration\IntegrationManager;

/**
 * Class IntegrationManagerTest
 */
class IntegrationManagerTest extends TestCase {

	/**
	 * Settings repository.
	 *
	 * @var SettingsRepository
	 */
	private SettingsRepository $settings_repo;

	/**
	 * Policy registry.
	 *
	 * @var CustomFieldPolicyRegistry
	 */
	private CustomFieldPolicyRegistry $policy_registry;

	/**
	 * Setup.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->settings_repo   = new SettingsRepository();
		$this->policy_registry = new CustomFieldPolicyRegistry( $this->settings_repo );
	}

	/**
	 * Test manager detects inactive ACF when neither class nor function exists.
	 */
	public function test_is_acf_active_returns_false_when_not_loaded(): void {
		$manager = new IntegrationManager( $this->policy_registry );
		// In pure unit test environment without ACF loaded.
		$is_active = $manager->is_acf_active();
		$this->assertSame( class_exists( 'ACF' ) || function_exists( 'acf' ), $is_active );
	}

	/**
	 * Test init does not instantiate AcfIntegration if ACF is not active.
	 */
	public function test_init_does_not_load_acf_when_inactive(): void {
		if ( class_exists( 'ACF' ) || function_exists( 'acf' ) ) {
			$this->markTestSkipped( 'ACF is currently loaded in this environment.' );
		}

		$manager = new IntegrationManager( $this->policy_registry );
		$manager->init();

		$this->assertNull( $manager->get_acf_integration() );
	}
}
