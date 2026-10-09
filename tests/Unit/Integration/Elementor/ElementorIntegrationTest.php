<?php
/**
 * ElementorIntegration Unit Tests.
 *
 * @package TF\Multilingual\Tests\Unit\Integration\Elementor
 */

declare( strict_types=1 );

namespace TF\Multilingual\Tests\Unit\Integration\Elementor;

use PHPUnit\Framework\TestCase;
use TF\Multilingual\Domain\CustomField\CustomFieldPolicy;
use TF\Multilingual\Domain\CustomField\CustomFieldPolicyRegistry;
use TF\Multilingual\Domain\Language\SettingsRepository;
use TF\Multilingual\Domain\Media\MediaTranslationResolver;
use TF\Multilingual\Domain\Translation\ContentTranslationResolver;
use TF\Multilingual\Integration\Elementor\ElementorIntegration;
use WP_Post;

/**
 * Class ElementorIntegrationTest
 */
class ElementorIntegrationTest extends TestCase {

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
	 * Elementor integration under test.
	 *
	 * @var ElementorIntegration
	 */
	private ElementorIntegration $integration;

	/**
	 * Sets up test fixtures.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->settings_repo   = new SettingsRepository();
		$this->policy_registry = new CustomFieldPolicyRegistry( $this->settings_repo );
		$this->integration     = new ElementorIntegration( $this->policy_registry );

		$GLOBALS['wp_test_posts']     = array();
		$GLOBALS['wp_test_post_meta'] = array();
	}

	/**
	 * Tests constants and default policies registration.
	 */
	public function test_register_field_policies_sets_expected_defaults(): void {
		$this->assertFalse( $this->policy_registry->has_policy( ElementorIntegration::META_EDIT_MODE ) );
		$this->assertFalse( $this->policy_registry->has_policy( ElementorIntegration::META_DATA ) );
		$this->assertFalse( $this->policy_registry->has_policy( ElementorIntegration::META_PAGE_SETTINGS ) );
		$this->assertFalse( $this->policy_registry->has_policy( ElementorIntegration::META_CSS ) );

		$this->integration->register_field_policies();

		$this->assertSame( CustomFieldPolicy::SHARE, $this->policy_registry->get_policy( ElementorIntegration::META_EDIT_MODE ) );
		$this->assertSame( CustomFieldPolicy::SHARE, $this->policy_registry->get_policy( ElementorIntegration::META_TEMPLATE_TYPE ) );
		$this->assertSame( CustomFieldPolicy::SHARE, $this->policy_registry->get_policy( ElementorIntegration::META_VERSION ) );
		$this->assertSame( CustomFieldPolicy::SHARE, $this->policy_registry->get_policy( ElementorIntegration::META_PRO_VERSION ) );
		$this->assertSame( CustomFieldPolicy::SHARE, $this->policy_registry->get_policy( ElementorIntegration::META_PAGE_TEMPLATE ) );
		$this->assertSame( CustomFieldPolicy::TRANSLATE, $this->policy_registry->get_policy( ElementorIntegration::META_DATA ) );
		$this->assertSame( CustomFieldPolicy::TRANSLATE, $this->policy_registry->get_policy( ElementorIntegration::META_PAGE_SETTINGS ) );
		$this->assertSame( CustomFieldPolicy::IGNORE, $this->policy_registry->get_policy( ElementorIntegration::META_CSS ) );
	}

	/**
	 * Tests register_field_policies respects existing administrator configuration.
	 */
	public function test_register_field_policies_respects_existing_policies(): void {
		$this->policy_registry->set_policy( ElementorIntegration::META_DATA, CustomFieldPolicy::SHARE );

		$this->integration->register_field_policies();

		$this->assertSame( CustomFieldPolicy::SHARE, $this->policy_registry->get_policy( ElementorIntegration::META_DATA ) );
	}

	/**
	 * Tests is_elementor_post detection.
	 */
	public function test_is_elementor_post(): void {
		$post_id = 401;

		$this->assertFalse( $this->integration->is_elementor_post( $post_id ) );

		update_post_meta( $post_id, ElementorIntegration::META_EDIT_MODE, 'builder' );
		$this->assertTrue( $this->integration->is_elementor_post( $post_id ) );

		delete_post_meta( $post_id, ElementorIntegration::META_EDIT_MODE );
		$this->assertFalse( $this->integration->is_elementor_post( $post_id ) );

		update_post_meta( $post_id, ElementorIntegration::META_DATA, '[{"id":"123","elType":"section"}]' );
		$this->assertTrue( $this->integration->is_elementor_post( $post_id ) );
	}

	/**
	 * Tests get_elementor_data decodes valid JSON.
	 */
	public function test_get_elementor_data(): void {
		$post_id = 402;
		$tree    = array(
			array(
				'id'       => 'sec1234',
				'elType'   => 'section',
				'settings' => array( 'layout' => 'boxed' ),
				'elements' => array(),
			),
		);

		update_post_meta( $post_id, ElementorIntegration::META_DATA, json_encode( $tree ) );

		$retrieved = $this->integration->get_elementor_data( $post_id );
		$this->assertSame( $tree, $retrieved );
	}

	/**
	 * Tests extract_media_ids recursively collects attachment IDs across widgets and repeaters.
	 */
	public function test_extract_media_ids(): void {
		$elements = array(
			array(
				'id'       => 'sec_1',
				'elType'   => 'section',
				'settings' => array(
					'background_image' => array(
						'id'  => 101,
						'url' => 'http://example.com/bg.jpg',
					),
				),
				'elements' => array(
					array(
						'id'       => 'col_1',
						'elType'   => 'column',
						'settings' => array(),
						'elements' => array(
							array(
								'id'         => 'wid_img',
								'elType'     => 'widget',
								'widgetType' => 'image',
								'settings'   => array(
									'image' => array(
										'id'  => 102,
										'url' => 'http://example.com/img.jpg',
									),
								),
							),
							array(
								'id'         => 'wid_gallery',
								'elType'     => 'widget',
								'widgetType' => 'image-gallery',
								'settings'   => array(
									'wp_gallery' => array(
										array(
											'id'  => 103,
											'url' => 'http://example.com/g1.jpg',
										),
										array(
											'id'  => 104,
											'url' => 'http://example.com/g2.jpg',
										),
									),
								),
							),
							array(
								'id'         => 'wid_repeater',
								'elType'     => 'widget',
								'widgetType' => 'custom-slider',
								'settings'   => array(
									'slides' => array(
										array(
											'_id'   => 'slide_a',
											'title' => 'Slide 1',
											'photo' => array(
												'id'  => 105,
												'url' => 'http://example.com/s1.jpg',
											),
										),
									),
								),
							),
						),
					),
				),
			),
		);

		$ids = $this->integration->extract_media_ids( $elements );

		$this->assertEqualsCanonicalizing( array( 101, 102, 103, 104, 105 ), $ids );
	}

	/**
	 * Tests localize_elementor_tree replaces media IDs and URLs while preserving technical keys.
	 */
	public function test_localize_elementor_tree_replaces_media_and_links(): void {
		add_filter(
			'tfml_localize_attachment_id',
			function ( int $id, string $lang ): int {
				if ( 'en' === $lang ) {
					if ( 101 === $id ) {
						return 201;
					}
					if ( 103 === $id ) {
						return 203;
					}
				}
				return $id;
			},
			10,
			2
		);

		add_filter(
			'tfml_elementor_localize_url',
			function ( string $url, string $lang ): string {
				if ( 'en' === $lang && 'http://example.com/es/contacto/' === $url ) {
					return 'http://example.com/en/contact/';
				}
				return $url;
			},
			10,
			2
		);

		$elements = array(
			array(
				'id'       => 'sec_original',
				'elType'   => 'section',
				'settings' => array(
					'bg' => array(
						'id'  => 101,
						'url' => 'http://example.com/bg-es.jpg',
					),
				),
				'elements' => array(
					array(
						'id'         => 'wid_btn',
						'elType'     => 'widget',
						'widgetType' => 'button',
						'settings'   => array(
							'text' => 'Contáctanos',
							'link' => array(
								'url'         => 'http://example.com/es/contacto/',
								'is_external' => '',
							),
						),
					),
				),
			),
		);

		$localized = $this->integration->localize_elementor_tree( $elements, 'en' );

		// Verify technical identifiers are preserved
		$this->assertSame( 'sec_original', $localized[0]['id'] );
		$this->assertSame( 'wid_btn', $localized[0]['elements'][0]['id'] );
		$this->assertSame( 'button', $localized[0]['elements'][0]['widgetType'] );

		// Verify media ID replaced
		$this->assertSame( 201, $localized[0]['settings']['bg']['id'] );

		// Verify link URL localized
		$this->assertSame( 'http://example.com/en/contact/', $localized[0]['elements'][0]['settings']['link']['url'] );
	}

	/**
	 * Tests copy_elementor_data_for_translation clones and persists data to target post.
	 */
	public function test_copy_elementor_data_for_translation(): void {
		$source_id = 501;
		$target_id = 502;

		$tree = array(
			array(
				'id'       => 's100',
				'elType'   => 'section',
				'settings' => array( 'layout' => 'full' ),
				'elements' => array(),
			),
		);

		update_post_meta( $source_id, ElementorIntegration::META_EDIT_MODE, 'builder' );
		update_post_meta( $source_id, ElementorIntegration::META_DATA, json_encode( $tree ) );
		update_post_meta( $source_id, ElementorIntegration::META_TEMPLATE_TYPE, 'page' );
		update_post_meta( $source_id, ElementorIntegration::META_PAGE_SETTINGS, array( 'custom_css' => '.header { font-size: 20px; }' ) );

		$copied = $this->integration->copy_elementor_data_for_translation( $source_id, $target_id, 'en' );
		$this->assertTrue( $copied );

		$this->assertSame( 'builder', get_post_meta( $target_id, ElementorIntegration::META_EDIT_MODE, true ) );
		$this->assertSame( 'page', get_post_meta( $target_id, ElementorIntegration::META_TEMPLATE_TYPE, true ) );

		$target_data = $this->integration->get_elementor_data( $target_id );
		$this->assertSame( $tree, $target_data );

		$target_settings = get_post_meta( $target_id, ElementorIntegration::META_PAGE_SETTINGS, true );
		$this->assertSame( array( 'custom_css' => '.header { font-size: 20px; }' ), $target_settings );
	}

	/**
	 * Tests filter_initial_translation_post_content preserves content for Elementor posts
	 * and maintains zero-cloning for standard posts.
	 */
	public function test_filter_initial_translation_post_content(): void {
		$elem_post               = new WP_Post();
		$elem_post->ID           = 601;
		$elem_post->post_content = '<div class="elementor-preview">Rendered HTML</div>';
		update_post_meta( 601, ElementorIntegration::META_EDIT_MODE, 'builder' );

		$initial = $this->integration->filter_initial_translation_post_content( '', $elem_post, 'en' );
		$this->assertSame( '<div class="elementor-preview">Rendered HTML</div>', $initial );

		$plain_post               = new WP_Post();
		$plain_post->ID           = 602;
		$plain_post->post_content = '<p>Standard Gutenberg content</p>';

		$plain_initial = $this->integration->filter_initial_translation_post_content( '', $plain_post, 'en' );
		$this->assertSame( '', $plain_initial, 'Standard post must adhere to zero-cloning' );
	}

	/**
	 * Tests hooks registration and removal.
	 */
	public function test_init_and_remove_hooks(): void {
		$this->integration->init_hooks();
		$this->assertTrue( $this->policy_registry->has_policy( ElementorIntegration::META_DATA ) );

		$this->integration->remove_hooks();
		$this->assertTrue( true );
	}
}
