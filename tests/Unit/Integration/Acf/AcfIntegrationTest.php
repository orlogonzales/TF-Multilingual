<?php
/**
 * AcfIntegration Unit Tests.
 *
 * @package TF\Multilingual\Tests\Unit\Integration\Acf
 */

declare( strict_types=1 );

namespace TF\Multilingual\Tests\Unit\Integration\Acf;

use PHPUnit\Framework\TestCase;
use TF\Multilingual\Domain\CustomField\CustomFieldPolicy;
use TF\Multilingual\Domain\CustomField\CustomFieldPolicyRegistry;
use TF\Multilingual\Domain\Language\SettingsRepository;
use TF\Multilingual\Integration\Acf\AcfIntegration;

/**
 * Class AcfIntegrationTest
 */
class AcfIntegrationTest extends TestCase {

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
	 * ACF integration instance.
	 *
	 * @var AcfIntegration
	 */
	private AcfIntegration $integration;

	/**
	 * Setup.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->settings_repo   = new SettingsRepository();
		$this->policy_registry = new CustomFieldPolicyRegistry( $this->settings_repo );
		$this->integration     = new AcfIntegration( $this->policy_registry );
	}

	/**
	 * Test get_field_storage_key with direct top-level field.
	 */
	public function test_get_field_storage_key_direct(): void {
		$field = array(
			'key'    => 'field_price',
			'name'   => 'tour_price',
			'parent' => 0,
		);

		$this->assertSame( 'tour_price', $this->integration->get_field_storage_key( $field ) );
	}

	/**
	 * Test get_field_storage_key with empty name.
	 */
	public function test_get_field_storage_key_empty(): void {
		$field = array(
			'key'    => 'field_tab',
			'name'   => '',
			'parent' => 0,
		);

		$this->assertSame( '', $this->integration->get_field_storage_key( $field ) );
	}

	/**
	 * Test load_field populates tfml_policy from registry if unset.
	 */
	public function test_load_field_populates_from_registry(): void {
		$this->policy_registry->set_policy( 'tour_guide', CustomFieldPolicy::SHARE );

		$field = array(
			'key'  => 'field_guide',
			'name' => 'tour_guide',
		);

		$loaded = $this->integration->load_field( $field );
		$this->assertSame( CustomFieldPolicy::SHARE, $loaded['tfml_policy'] );
	}

	/**
	 * Test load_field preserves existing tfml_policy.
	 */
	public function test_load_field_preserves_existing(): void {
		$this->policy_registry->set_policy( 'tour_guide', CustomFieldPolicy::SHARE );

		$field = array(
			'key'         => 'field_guide',
			'name'        => 'tour_guide',
			'tfml_policy' => CustomFieldPolicy::TRANSLATE,
		);

		$loaded = $this->integration->load_field( $field );
		$this->assertSame( CustomFieldPolicy::TRANSLATE, $loaded['tfml_policy'] );
	}

	/**
	 * Test update_field saves SHARE policy and registers paired reference key.
	 */
	public function test_update_field_share_registers_reference_key(): void {
		$field = array(
			'key'         => 'field_city',
			'name'        => 'tour_city',
			'tfml_policy' => CustomFieldPolicy::SHARE,
		);

		$this->integration->update_field( $field );

		$this->assertSame( CustomFieldPolicy::SHARE, $this->policy_registry->get_policy( 'tour_city' ) );
		$this->assertSame( CustomFieldPolicy::SHARE, $this->policy_registry->get_policy( '_tour_city' ) );
		$this->assertTrue( $this->policy_registry->is_shared( 'tour_city' ) );
		$this->assertTrue( $this->policy_registry->is_shared( '_tour_city' ) );
	}

	/**
	 * Test update_field saves TRANSLATE policy and removes paired reference key.
	 */
	public function test_update_field_translate_removes_reference_key(): void {
		// First set to share.
		$this->policy_registry->set_policy( 'tour_city', CustomFieldPolicy::SHARE );
		$this->policy_registry->set_policy( '_tour_city', CustomFieldPolicy::SHARE );

		$field = array(
			'key'         => 'field_city',
			'name'        => 'tour_city',
			'tfml_policy' => CustomFieldPolicy::TRANSLATE,
		);

		$this->integration->update_field( $field );

		$this->assertSame( CustomFieldPolicy::TRANSLATE, $this->policy_registry->get_policy( 'tour_city' ) );
		$this->assertFalse( $this->policy_registry->has_policy( '_tour_city' ) );
	}

	/**
	 * Test update_field saves IGNORE policy and removes paired reference key.
	 */
	public function test_update_field_ignore_removes_reference_key(): void {
		$this->policy_registry->set_policy( 'tour_city', CustomFieldPolicy::SHARE );
		$this->policy_registry->set_policy( '_tour_city', CustomFieldPolicy::SHARE );

		$field = array(
			'key'         => 'field_city',
			'name'        => 'tour_city',
			'tfml_policy' => CustomFieldPolicy::IGNORE,
		);

		$this->integration->update_field( $field );

		$this->assertSame( CustomFieldPolicy::IGNORE, $this->policy_registry->get_policy( 'tour_city' ) );
		$this->assertFalse( $this->policy_registry->has_policy( '_tour_city' ) );
	}

	/**
	 * Test delete_field removes policy and paired reference key.
	 */
	public function test_delete_field_cleans_up_registry(): void {
		$this->policy_registry->set_policy( 'tour_city', CustomFieldPolicy::SHARE );
		$this->policy_registry->set_policy( '_tour_city', CustomFieldPolicy::SHARE );

		$field = array(
			'key'  => 'field_city',
			'name' => 'tour_city',
		);

		$this->integration->delete_field( $field );

		$this->assertFalse( $this->policy_registry->has_policy( 'tour_city' ) );
		$this->assertFalse( $this->policy_registry->has_policy( '_tour_city' ) );
	}

	/**
	 * Test hooks registration and unregistration.
	 */
	public function test_init_and_remove_hooks(): void {
		$this->integration->init_hooks();
		$this->assertTrue( has_action( 'acf/render_field_settings' ) );
		$this->assertTrue( has_action( 'acf/delete_field' ) );
		$this->assertTrue( has_filter( 'acf/load_field' ) );
		$this->assertTrue( has_filter( 'acf/update_field' ) );

		$this->integration->remove_hooks();
		$this->assertFalse( has_action( 'acf/render_field_settings' ) );
		$this->assertFalse( has_action( 'acf/delete_field' ) );
		$this->assertFalse( has_filter( 'acf/load_field' ) );
		$this->assertFalse( has_filter( 'acf/update_field' ) );
	}
}
