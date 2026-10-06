<?php
/**
 * SharedMetaSynchronizer Unit Tests.
 *
 * @package TF\Multilingual\Tests\Unit
 */

declare( strict_types=1 );

namespace TF\Multilingual\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TF\Multilingual\Domain\CustomField\CustomFieldPolicy;
use TF\Multilingual\Domain\CustomField\CustomFieldPolicyRegistry;
use TF\Multilingual\Domain\CustomField\SharedMetaSynchronizer;
use TF\Multilingual\Domain\Language\Language;
use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Domain\Language\SettingsRepository;
use TF\Multilingual\Domain\Translation\ContentTranslationResolver;
use TF\Multilingual\Domain\Translation\TranslationElement;
use TF\Multilingual\Domain\Translation\TranslationGroup;
use TF\Multilingual\Domain\Translation\TranslationGroupRepository;
use TF\Multilingual\Domain\Translation\WordPressElementValidator;
use TF\Multilingual\Tests\Doubles\TestableWpdb;

/**
 * Class SharedMetaSynchronizerTest
 */
class SharedMetaSynchronizerTest extends TestCase {

	/**
	 * Database double.
	 *
	 * @var TestableWpdb
	 */
	private TestableWpdb $db;

	/**
	 * Language registry.
	 *
	 * @var LanguageRegistry
	 */
	private LanguageRegistry $language_registry;

	/**
	 * Group repository.
	 *
	 * @var TranslationGroupRepository
	 */
	private TranslationGroupRepository $group_repo;

	/**
	 * Content translation resolver.
	 *
	 * @var ContentTranslationResolver
	 */
	private ContentTranslationResolver $resolver;

	/**
	 * Policy registry.
	 *
	 * @var CustomFieldPolicyRegistry
	 */
	private CustomFieldPolicyRegistry $policy_registry;

	/**
	 * Synchronizer under test.
	 *
	 * @var SharedMetaSynchronizer
	 */
	private SharedMetaSynchronizer $synchronizer;

	/**
	 * Set up test environment before each test.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->db         = new TestableWpdb();
		$this->db->prefix = 'wp_';
		$GLOBALS['wpdb']  = $this->db;

		$GLOBALS['wp_test_options']        = array();
		$GLOBALS['wp_test_postmeta']       = array();
		$GLOBALS['wp_test_post_revisions'] = array();
		$GLOBALS['wp_test_post_autosaves'] = array();
		$GLOBALS['wp_test_actions']        = array();

		$settings_repo           = new SettingsRepository();
		$this->language_registry = new LanguageRegistry( $settings_repo );

		$es = Language::create( 'es', 'es_ES', 'Spanish', 'Español', true, 10 );
		$en = Language::create( 'en', 'en_US', 'English', 'English', true, 20 );
		$pt = Language::create( 'pt', 'pt_PT', 'Portuguese', 'Português', true, 30 );

		$this->language_registry->add_language( $es, true );
		$this->language_registry->add_language( $en );
		$this->language_registry->add_language( $pt );

		$validator             = new WordPressElementValidator( $this->db );
		$this->group_repo      = new TranslationGroupRepository( $this->db, $this->language_registry, $validator );
		$this->resolver        = new ContentTranslationResolver( $this->group_repo, $this->language_registry, $validator );
		$this->policy_registry = new CustomFieldPolicyRegistry( $settings_repo );

		$this->synchronizer = new SharedMetaSynchronizer(
			$this->policy_registry,
			$this->resolver
		);
		$this->synchronizer->init_hooks();
	}

	/**
	 * Tear down test environment after each test.
	 */
	protected function tearDown(): void {
		$this->synchronizer->remove_hooks();
		$GLOBALS['wp_test_options']        = array();
		$GLOBALS['wp_test_postmeta']       = array();
		$GLOBALS['wp_test_post_revisions'] = array();
		$GLOBALS['wp_test_post_autosaves'] = array();
		$GLOBALS['wp_test_actions']        = array();
		parent::tearDown();
	}

	/**
	 * Helper to create a translation group with posts in ES, EN, and PT.
	 *
	 * @param int $post_es Post ID for ES.
	 * @param int $post_en Post ID for EN.
	 * @param int $post_pt Post ID for PT.
	 * @return TranslationGroup
	 */
	private function create_three_language_group( int $post_es = 10, int $post_en = 20, int $post_pt = 30 ): TranslationGroup {
		$group = TranslationGroup::create( 'post', 'post' );
		$group->add_element( TranslationElement::create( 'post', $post_es, 'es' ) );
		$group->add_element( TranslationElement::create( 'post', $post_en, 'en' ) );
		$group->add_element( TranslationElement::create( 'post', $post_pt, 'pt' ) );

		$this->resolver->prime_cache( $group );

		return $group;
	}

	/**
	 * Tests update of SHARE field propagates to all siblings.
	 */
	public function test_shared_field_propagates_to_siblings_on_update(): void {
		$this->create_three_language_group( 10, 20, 30 );
		$this->policy_registry->set_policy( '_tour_price', CustomFieldPolicy::SHARE );

		// Update post 10 (ES).
		update_post_meta( 10, '_tour_price', 150 );

		// Check siblings 20 (EN) and 30 (PT) received the update.
		$this->assertSame( 150, get_post_meta( 20, '_tour_price', true ) );
		$this->assertSame( 150, get_post_meta( 30, '_tour_price', true ) );
	}

	/**
	 * Tests reverse synchronization: updating EN propagates to ES and PT.
	 */
	public function test_shared_field_reverse_sync_propagates_to_all_siblings(): void {
		$this->create_three_language_group( 10, 20, 30 );
		$this->policy_registry->set_policy( '_tour_capacity', CustomFieldPolicy::SHARE );

		// Update sibling 20 (EN).
		update_post_meta( 20, '_tour_capacity', 25 );

		// Check ES (10) and PT (30) received the update.
		$this->assertSame( 25, get_post_meta( 10, '_tour_capacity', true ) );
		$this->assertSame( 25, get_post_meta( 30, '_tour_capacity', true ) );
	}

	/**
	 * Tests TRANSLATE field update does NOT propagate to siblings.
	 */
	public function test_translate_field_does_not_propagate_to_siblings(): void {
		$this->create_three_language_group( 10, 20, 30 );
		$this->policy_registry->set_policy( 'tour_subtitle', CustomFieldPolicy::TRANSLATE );

		update_post_meta( 10, 'tour_subtitle', 'Subtítulo en Español' );

		$this->assertSame( 'Subtítulo en Español', get_post_meta( 10, 'tour_subtitle', true ) );
		$this->assertSame( '', get_post_meta( 20, 'tour_subtitle', true ) );
		$this->assertSame( '', get_post_meta( 30, 'tour_subtitle', true ) );
	}

	/**
	 * Tests IGNORE (and unconfigured) field update does NOT propagate to siblings.
	 */
	public function test_ignore_field_does_not_propagate_to_siblings(): void {
		$this->create_three_language_group( 10, 20, 30 );
		$this->policy_registry->set_policy( '_edit_lock', CustomFieldPolicy::IGNORE );

		update_post_meta( 10, '_edit_lock', '1234567890:1' );
		update_post_meta( 10, 'unconfigured_arbitrary_field', 'some_value' );

		$this->assertSame( '', get_post_meta( 20, '_edit_lock', true ) );
		$this->assertSame( '', get_post_meta( 30, '_edit_lock', true ) );
		$this->assertSame( '', get_post_meta( 20, 'unconfigured_arbitrary_field', true ) );
		$this->assertSame( '', get_post_meta( 30, 'unconfigured_arbitrary_field', true ) );
	}

	/**
	 * Tests delete of SHARE field deletes the field on all siblings.
	 */
	public function test_delete_shared_field_propagates_deletion_to_siblings(): void {
		$this->create_three_language_group( 10, 20, 30 );
		$this->policy_registry->set_policy( '_tour_sku', CustomFieldPolicy::SHARE );

		// Populate field on all posts.
		update_post_meta( 10, '_tour_sku', 'SKU-99' );
		$this->assertSame( 'SKU-99', get_post_meta( 20, '_tour_sku', true ) );
		$this->assertSame( 'SKU-99', get_post_meta( 30, '_tour_sku', true ) );

		// Delete on post 30 (PT).
		delete_post_meta( 30, '_tour_sku' );

		// Check deleted on 10 (ES) and 20 (EN).
		$this->assertSame( '', get_post_meta( 10, '_tour_sku', true ) );
		$this->assertSame( '', get_post_meta( 20, '_tour_sku', true ) );
		$this->assertSame( '', get_post_meta( 30, '_tour_sku', true ) );
	}

	/**
	 * Tests delete of TRANSLATE or IGNORE field does NOT delete from siblings.
	 */
	public function test_delete_translate_field_does_not_affect_siblings(): void {
		$this->create_three_language_group( 10, 20, 30 );
		$this->policy_registry->set_policy( 'custom_badge', CustomFieldPolicy::TRANSLATE );

		// Set independent values.
		$GLOBALS['wp_test_postmeta'][10]['custom_badge'] = 'Oferta';
		$GLOBALS['wp_test_postmeta'][20]['custom_badge'] = 'Sale';

		delete_post_meta( 10, 'custom_badge' );

		$this->assertSame( '', get_post_meta( 10, 'custom_badge', true ) );
		$this->assertSame( 'Sale', get_post_meta( 20, 'custom_badge', true ) );
	}

	/**
	 * Tests unmanaged post does not synchronize (Section 31).
	 */
	public function test_unmanaged_post_does_not_synchronize(): void {
		$this->policy_registry->set_policy( '_global_setting', CustomFieldPolicy::SHARE );

		// Post 999 is unmanaged (no group).
		update_post_meta( 999, '_global_setting', 'val_999' );

		$this->assertSame( 'val_999', get_post_meta( 999, '_global_setting', true ) );
		// No fatal, no warning, nothing leaked to unrelated posts.
		$this->assertSame( '', get_post_meta( 10, '_global_setting', true ) );
	}

	/**
	 * Tests single-member group does not synchronize (Section 32).
	 */
	public function test_single_member_group_does_not_synchronize(): void {
		$group = TranslationGroup::create( 'post', 'post' );
		$group->add_element( TranslationElement::create( 'post', 50, 'es' ) );
		$this->resolver->prime_cache( $group );

		$this->policy_registry->set_policy( '_single_price', CustomFieldPolicy::SHARE );

		update_post_meta( 50, '_single_price', 299 );

		$this->assertSame( 299, get_post_meta( 50, '_single_price', true ) );
	}

	/**
	 * Tests revisions and autosaves are excluded and do not synchronize.
	 */
	public function test_revisions_and_autosaves_do_not_synchronize(): void {
		$this->create_three_language_group( 10, 20, 30 );
		$this->policy_registry->set_policy( '_revision_field', CustomFieldPolicy::SHARE );

		// Mark post 100 as revision of post 10.
		$GLOBALS['wp_test_post_revisions'][100] = 10;

		update_post_meta( 100, '_revision_field', 'revision_val' );

		$this->assertSame( '', get_post_meta( 10, '_revision_field', true ) );
		$this->assertSame( '', get_post_meta( 20, '_revision_field', true ) );
		$this->assertSame( '', get_post_meta( 30, '_revision_field', true ) );
	}

	/**
	 * Tests falsey and empty values are correctly propagated.
	 */
	public function test_falsey_values_are_correctly_propagated(): void {
		$this->create_three_language_group( 10, 20, 30 );
		$this->policy_registry->set_policy( '_zero_num', CustomFieldPolicy::SHARE );
		$this->policy_registry->set_policy( '_zero_str', CustomFieldPolicy::SHARE );
		$this->policy_registry->set_policy( '_empty_str', CustomFieldPolicy::SHARE );

		update_post_meta( 10, '_zero_num', 0 );
		update_post_meta( 10, '_zero_str', '0' );
		update_post_meta( 10, '_empty_str', '' );

		$this->assertSame( 0, get_post_meta( 20, '_zero_num', true ) );
		$this->assertSame( '0', get_post_meta( 20, '_zero_str', true ) );
		$this->assertSame( '', get_post_meta( 20, '_empty_str', true ) );

		$this->assertSame( 0, get_post_meta( 30, '_zero_num', true ) );
		$this->assertSame( '0', get_post_meta( 30, '_zero_str', true ) );
		$this->assertSame( '', get_post_meta( 30, '_empty_str', true ) );
	}

	/**
	 * Tests serialized array values are correctly propagated.
	 */
	public function test_array_values_are_correctly_propagated(): void {
		$this->create_three_language_group( 10, 20, 30 );
		$this->policy_registry->set_policy( '_complex_data', CustomFieldPolicy::SHARE );

		$array_data = array(
			'coords' => array( 40.7128, -74.0060 ),
			'flags'  => array(
				'active'   => true,
				'featured' => false,
			),
		);

		update_post_meta( 10, '_complex_data', $array_data );

		$this->assertSame( $array_data, get_post_meta( 20, '_complex_data', true ) );
		$this->assertSame( $array_data, get_post_meta( 30, '_complex_data', true ) );
	}
}
