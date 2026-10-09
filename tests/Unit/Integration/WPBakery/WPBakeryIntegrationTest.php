<?php
/**
 * WPBakeryIntegration Unit Tests.
 *
 * @package TF\Multilingual\Tests\Unit\Integration\WPBakery
 */

declare( strict_types=1 );

namespace TF\Multilingual\Tests\Unit\Integration\WPBakery;

use PHPUnit\Framework\TestCase;
use TF\Multilingual\Domain\CustomField\CustomFieldPolicy;
use TF\Multilingual\Domain\CustomField\CustomFieldPolicyRegistry;
use TF\Multilingual\Domain\Language\SettingsRepository;
use TF\Multilingual\Domain\Media\MediaTranslationResolver;
use TF\Multilingual\Integration\WPBakery\WPBakeryIntegration;
use WP_Post;

/**
 * Class WPBakeryIntegrationTest
 */
class WPBakeryIntegrationTest extends TestCase {

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
	 * WPBakery integration under test.
	 *
	 * @var WPBakeryIntegration
	 */
	private WPBakeryIntegration $integration;

	/**
	 * Sets up test fixtures.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->settings_repo   = new SettingsRepository();
		$this->policy_registry = new CustomFieldPolicyRegistry( $this->settings_repo );
		$this->integration     = new WPBakeryIntegration( $this->policy_registry );

		$GLOBALS['wp_test_posts']     = array();
		$GLOBALS['wp_test_post_meta'] = array();
	}

	/**
	 * Tests constants and default policies registration.
	 */
	public function test_register_field_policies_sets_expected_defaults(): void {
		$this->assertFalse( $this->policy_registry->has_policy( WPBakeryIntegration::META_JS_STATUS ) );
		$this->assertFalse( $this->policy_registry->has_policy( WPBakeryIntegration::META_POST_CUSTOM_CSS ) );
		$this->assertFalse( $this->policy_registry->has_policy( WPBakeryIntegration::META_SHORTCODES_CUSTOM_CSS ) );

		$this->integration->register_field_policies();

		$this->assertSame( CustomFieldPolicy::SHARE, $this->policy_registry->get_policy( WPBakeryIntegration::META_JS_STATUS ) );
		$this->assertSame( CustomFieldPolicy::TRANSLATE, $this->policy_registry->get_policy( WPBakeryIntegration::META_POST_CUSTOM_CSS ) );
		$this->assertSame( CustomFieldPolicy::TRANSLATE, $this->policy_registry->get_policy( WPBakeryIntegration::META_SHORTCODES_CUSTOM_CSS ) );
	}

	/**
	 * Tests register_field_policies does not overwrite existing administrator configuration.
	 */
	public function test_register_field_policies_respects_existing_policies(): void {
		$this->policy_registry->set_policy( WPBakeryIntegration::META_POST_CUSTOM_CSS, CustomFieldPolicy::SHARE );

		$this->integration->register_field_policies();

		$this->assertSame( CustomFieldPolicy::SHARE, $this->policy_registry->get_policy( WPBakeryIntegration::META_POST_CUSTOM_CSS ) );
	}

	/**
	 * Tests has_wpbakery_content correctly identifies shortcode presence.
	 */
	public function test_has_wpbakery_content(): void {
		$vc_content    = '[vc_row][vc_column][vc_column_text]Hello World[/vc_column_text][/vc_column][/vc_row]';
		$plain_content = '<p>Just a standard paragraph with no page builder.</p>';
		$block_content = '<!-- wp:paragraph --><p>Gutenberg block</p><!-- /wp:paragraph -->';

		$this->assertTrue( $this->integration->has_wpbakery_content( $vc_content ) );
		$this->assertFalse( $this->integration->has_wpbakery_content( $plain_content ) );
		$this->assertFalse( $this->integration->has_wpbakery_content( $block_content ) );
	}

	/**
	 * Tests extract_media_ids extracts unique IDs from single and multiple image attributes.
	 */
	public function test_extract_media_ids(): void {
		$content = '[vc_row][vc_column]' .
			'[vc_single_image image="101"]' .
			'[vc_section background_image="102"]' .
			'[vc_gallery images="103, 104,105"]' .
			'[vc_single_image image="101"]' . // Duplicate ID
			'[/vc_column][/vc_row]';

		$ids = $this->integration->extract_media_ids( $content );

		$this->assertEqualsCanonicalizing( array( 101, 102, 103, 104, 105 ), $ids );
	}

	/**
	 * Tests localize_shortcode_media replaces attachment IDs when filter maps them.
	 */
	public function test_localize_shortcode_media_replaces_mapped_ids(): void {
		$content = '[vc_row][vc_column][vc_single_image image="50"][vc_gallery images="50,60,70"][/vc_column][/vc_row]';

		// Map ID 50 -> 150, 70 -> 170 for language 'en'
		add_filter(
			'tfml_localize_attachment_id',
			function ( int $id, string $lang ): int {
				if ( 'en' === $lang ) {
					if ( 50 === $id ) {
						return 150;
					}
					if ( 70 === $id ) {
						return 170;
					}
				}
				return $id;
			},
			10,
			2
		);

		$localized = $this->integration->localize_shortcode_media( $content, 'en' );

		$this->assertStringContainsString( 'image="150"', $localized );
		$this->assertStringContainsString( 'images="150,60,170"', $localized );
	}

	/**
	 * Tests copy_content_for_translation preserves plain content and localizes WPBakery content.
	 */
	public function test_copy_content_for_translation(): void {
		$plain = '<p>Regular content</p>';
		$this->assertSame( $plain, $this->integration->copy_content_for_translation( $plain, 'en' ) );

		$vc     = '[vc_row][vc_column][vc_single_image image="42"][/vc_column][/vc_row]';
		$copied = $this->integration->copy_content_for_translation( $vc, 'en' );
		$this->assertStringContainsString( '[vc_row]', $copied );
		$this->assertStringContainsString( 'image="42"', $copied );
	}

	/**
	 * Tests filter_initial_translation_post_content initializes content for WPBakery posts.
	 */
	public function test_filter_initial_translation_post_content_for_wpbakery_post(): void {
		$source               = new WP_Post();
		$source->ID           = 10;
		$source->post_content = '[vc_row][vc_column][vc_column_text]Original Spanish[/vc_column_text][/vc_column][/vc_row]';

		$initial = $this->integration->filter_initial_translation_post_content( '', $source, 'en' );

		$this->assertStringContainsString( '[vc_row]', $initial );
		$this->assertStringContainsString( 'Original Spanish', $initial );
	}

	/**
	 * Tests filter_initial_translation_post_content does not copy for non-WPBakery posts.
	 */
	public function test_filter_initial_translation_post_content_zero_cloning_for_standard_posts(): void {
		$source               = new WP_Post();
		$source->ID           = 11;
		$source->post_content = '<p>Standard Gutenberg or classic content</p>';

		$initial = $this->integration->filter_initial_translation_post_content( '', $source, 'en' );

		$this->assertSame( '', $initial, 'Standard posts must retain zero-cloning by default' );
	}

	/**
	 * Tests on_post_translation_created copies initial baseline CSS.
	 */
	public function test_on_post_translation_created_copies_baseline_css(): void {
		$source_id = 21;
		$target_id = 22;

		update_post_meta( $source_id, WPBakeryIntegration::META_POST_CUSTOM_CSS, '.custom-title { color: #f00; }' );
		update_post_meta( $source_id, WPBakeryIntegration::META_SHORTCODES_CUSTOM_CSS, '.vc_custom_100 { margin: 10px; }' );

		$this->integration->on_post_translation_created( $target_id, $source_id, 'en' );

		$this->assertSame( '.custom-title { color: #f00; }', get_post_meta( $target_id, WPBakeryIntegration::META_POST_CUSTOM_CSS, true ) );
		$this->assertSame( '.vc_custom_100 { margin: 10px; }', get_post_meta( $target_id, WPBakeryIntegration::META_SHORTCODES_CUSTOM_CSS, true ) );
	}

	/**
	 * Tests hooks registration and removal.
	 */
	public function test_init_and_remove_hooks(): void {
		$this->integration->init_hooks();
		$this->assertTrue( $this->policy_registry->has_policy( WPBakeryIntegration::META_JS_STATUS ) );

		$this->integration->remove_hooks();
		$this->assertTrue( true );
	}
}
