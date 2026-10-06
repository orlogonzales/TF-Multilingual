<?php
/**
 * CustomFieldPolicyRegistry Unit Tests.
 *
 * @package TF\Multilingual\Tests\Unit
 */

declare( strict_types=1 );

namespace TF\Multilingual\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use TF\Multilingual\Domain\CustomField\CustomFieldPolicy;
use TF\Multilingual\Domain\CustomField\CustomFieldPolicyRegistry;
use TF\Multilingual\Domain\Language\Language;
use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Domain\Language\SettingsRepository;

/**
 * Class CustomFieldPolicyRegistryTest
 */
class CustomFieldPolicyRegistryTest extends TestCase {

	/**
	 * Settings repository.
	 *
	 * @var SettingsRepository
	 */
	private SettingsRepository $repository;

	/**
	 * Registry under test.
	 *
	 * @var CustomFieldPolicyRegistry
	 */
	private CustomFieldPolicyRegistry $registry;

	/**
	 * Set up test environment before each test.
	 */
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['wp_test_options'] = array();
		$this->repository           = new SettingsRepository();
		$this->registry             = new CustomFieldPolicyRegistry( $this->repository );
	}

	/**
	 * Tear down test environment after each test.
	 */
	protected function tearDown(): void {
		parent::tearDown();
		$GLOBALS['wp_test_options'] = array();
	}

	/**
	 * Tests unknown key strictly defaults to IGNORE without heuristics.
	 */
	public function test_unconfigured_key_strictly_defaults_to_ignore(): void {
		$this->assertSame( CustomFieldPolicy::IGNORE, $this->registry->get_policy( 'unknown_field' ) );
		$this->assertSame( CustomFieldPolicy::IGNORE, $this->registry->get_policy( 'price' ) );
		$this->assertSame( CustomFieldPolicy::IGNORE, $this->registry->get_policy( '_thumbnail_id' ) );
		$this->assertSame( CustomFieldPolicy::IGNORE, $this->registry->get_policy( '' ) );

		$this->assertTrue( $this->registry->is_ignored( 'arbitrary_field' ) );
		$this->assertFalse( $this->registry->is_shared( 'arbitrary_field' ) );
		$this->assertFalse( $this->registry->is_translatable( 'arbitrary_field' ) );
	}

	/**
	 * Tests setting and retrieving policies.
	 */
	public function test_set_and_get_policy(): void {
		$this->registry->set_policy( '_regular_price', CustomFieldPolicy::SHARE );
		$this->registry->set_policy( 'product_description', CustomFieldPolicy::TRANSLATE );
		$this->registry->set_policy( '_internal_cache', CustomFieldPolicy::IGNORE );

		$this->assertSame( CustomFieldPolicy::SHARE, $this->registry->get_policy( '_regular_price' ) );
		$this->assertTrue( $this->registry->is_shared( '_regular_price' ) );

		$this->assertSame( CustomFieldPolicy::TRANSLATE, $this->registry->get_policy( 'product_description' ) );
		$this->assertTrue( $this->registry->is_translatable( 'product_description' ) );

		$this->assertSame( CustomFieldPolicy::IGNORE, $this->registry->get_policy( '_internal_cache' ) );
		$this->assertTrue( $this->registry->is_ignored( '_internal_cache' ) );

		$all = $this->registry->get_all_policies();
		$this->assertCount( 3, $all );
		$this->assertSame( CustomFieldPolicy::SHARE, $all['_regular_price'] );
	}

	/**
	 * Tests setting policy with empty key throws exception.
	 */
	public function test_set_policy_with_empty_key_throws_exception(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Meta key cannot be empty.' );

		$this->registry->set_policy( '   ', CustomFieldPolicy::SHARE );
	}

	/**
	 * Tests setting invalid policy throws exception.
	 */
	public function test_set_invalid_policy_throws_exception(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Invalid custom field policy "unsupported"' );

		$this->registry->set_policy( 'test_field', 'unsupported' );
	}

	/**
	 * Tests removing a configured policy reverts to default IGNORE.
	 */
	public function test_remove_policy_reverts_to_ignore(): void {
		$this->registry->set_policy( '_price', CustomFieldPolicy::SHARE );
		$this->assertSame( CustomFieldPolicy::SHARE, $this->registry->get_policy( '_price' ) );

		$this->registry->remove_policy( '_price' );
		$this->assertSame( CustomFieldPolicy::IGNORE, $this->registry->get_policy( '_price' ) );
		$this->assertArrayNotHasKey( '_price', $this->registry->get_all_policies() );
	}

	/**
	 * Tests persistence to wp_options and reloading.
	 */
	public function test_persist_and_reload(): void {
		$this->registry->set_policy( 'gallery_ids', CustomFieldPolicy::SHARE );
		$this->registry->set_policy( 'custom_subtitle', CustomFieldPolicy::TRANSLATE );

		$this->assertTrue( $this->registry->persist() );

		// Create a separate registry instance reading from same repository.
		$new_registry = new CustomFieldPolicyRegistry( $this->repository );

		$this->assertSame( CustomFieldPolicy::SHARE, $new_registry->get_policy( 'gallery_ids' ) );
		$this->assertSame( CustomFieldPolicy::TRANSLATE, $new_registry->get_policy( 'custom_subtitle' ) );
		$this->assertSame( CustomFieldPolicy::IGNORE, $new_registry->get_policy( 'not_configured' ) );
	}

	/**
	 * Tests coexistence: persisting custom field policies does not erase language settings,
	 * and persisting languages does not erase custom field policies.
	 */
	public function test_settings_coexistence_with_language_registry(): void {
		$lang_registry = new LanguageRegistry( $this->repository );
		$es            = Language::create( 'es', 'es_ES', 'Spanish', 'Español', true, 10 );
		$en            = Language::create( 'en', 'en_US', 'English', 'English', true, 20 );

		$lang_registry->add_language( $es, true );
		$lang_registry->add_language( $en );
		$lang_registry->persist();

		// Save custom field policies.
		$this->registry->set_policy( '_sku', CustomFieldPolicy::SHARE );
		$this->registry->persist();

		// Verify language settings still intact.
		$fresh_lang_registry = new LanguageRegistry( $this->repository );
		$this->assertTrue( $fresh_lang_registry->is_configured() );
		$this->assertSame( 'es', $fresh_lang_registry->get_default_code() );
		$this->assertTrue( $fresh_lang_registry->has( 'en' ) );

		// Save languages again.
		$fresh_lang_registry->persist();

		// Verify custom field policies still intact.
		$fresh_cf_registry = new CustomFieldPolicyRegistry( $this->repository );
		$this->assertSame( CustomFieldPolicy::SHARE, $fresh_cf_registry->get_policy( '_sku' ) );
	}
}
