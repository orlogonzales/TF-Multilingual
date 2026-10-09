<?php
/**
 * SEO Frontend Filter Tests.
 *
 * @package TF\Multilingual\Tests\Unit
 */

declare( strict_types=1 );

namespace TF\Multilingual\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TF\Multilingual\Domain\Language\Language;
use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Domain\Language\SettingsRepository;
use TF\Multilingual\Domain\Seo\CanonicalUrlManager;
use TF\Multilingual\Domain\Seo\CoreSitemapsFilter;
use TF\Multilingual\Domain\Seo\HreflangGenerator;
use TF\Multilingual\Domain\Seo\SeoFrontendFilter;
use TF\Multilingual\Domain\Translation\ContentTranslationResolver;
use TF\Multilingual\Domain\Translation\TranslationGroupRepository;
use TF\Multilingual\Routing\LocalizedUrlGenerator;
use TF\Multilingual\Routing\UrlLanguageResolver;
use TF\Multilingual\Tests\Doubles\TestableWpdb;

/**
 * Class SeoFrontendFilterTest
 */
class SeoFrontendFilterTest extends TestCase {

	/**
	 * Database double.
	 *
	 * @var TestableWpdb
	 */
	private TestableWpdb $wpdb;

	/**
	 * Settings repository.
	 *
	 * @var SettingsRepository
	 */
	private SettingsRepository $settings_repo;

	/**
	 * Language registry.
	 *
	 * @var LanguageRegistry
	 */
	private LanguageRegistry $lang_registry;

	/**
	 * Filter under test.
	 *
	 * @var SeoFrontendFilter
	 */
	private SeoFrontendFilter $filter;

	/**
	 * Sets up test environment.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->wpdb         = new TestableWpdb();
		$this->wpdb->prefix = 'wp_';
		$GLOBALS['wpdb']    = $this->wpdb;

		$GLOBALS['wp_test_actions'] = array();
		$GLOBALS['wp_test_filters'] = array();

		$this->settings_repo = new SettingsRepository();
		$this->lang_registry = new LanguageRegistry( $this->settings_repo );

		$es = Language::create( 'es', 'es_ES', 'Español', 'Español', true, 10 );
		$en = Language::create( 'en', 'en_US', 'English', 'English', true, 20 );
		$this->lang_registry->add_language( $es, true );
		$this->lang_registry->add_language( $en );

		$group_repo       = new TranslationGroupRepository( $this->wpdb, $this->lang_registry );
		$content_resolver = new ContentTranslationResolver( $group_repo, $this->lang_registry );
		$url_resolver     = new UrlLanguageResolver( $this->lang_registry, 'http://example.com' );
		$url_generator    = new LocalizedUrlGenerator(
			$this->lang_registry,
			$url_resolver,
			$content_resolver,
			'http://example.com'
		);

		$hreflang_generator = new HreflangGenerator(
			$this->lang_registry,
			$group_repo,
			$content_resolver,
			$url_generator
		);
		$canonical_manager  = new CanonicalUrlManager(
			$this->lang_registry,
			$content_resolver,
			$url_generator,
			$url_resolver
		);
		$sitemaps_filter    = new CoreSitemapsFilter(
			$this->lang_registry,
			$content_resolver,
			$url_generator
		);

		$this->filter = new SeoFrontendFilter(
			$hreflang_generator,
			$canonical_manager,
			$sitemaps_filter
		);
	}

	/**
	 * Tests init_hooks registers wp_head action and subordinate filters.
	 */
	public function test_init_hooks(): void {
		$this->filter->init_hooks();

		$this->assertNotEmpty( $GLOBALS['wp_test_actions']['wp_head'] );
		$this->assertSame( 2, $GLOBALS['wp_test_actions']['wp_head'][0]['priority'] );

		// Subordinates hooked.
		$this->assertNotEmpty( $GLOBALS['wp_test_filters']['wp_get_canonical_url'] );
		$this->assertNotEmpty( $GLOBALS['wp_test_filters']['wp_sitemaps_posts_query_args'] );
	}

	/**
	 * Tests render_hreflang_tags outputs formatted HTML link elements.
	 */
	public function test_render_hreflang_tags(): void {
		// Mock hreflang filter to return specific variants.
		add_filter(
			'tfml_hreflang_variants',
			static function () {
				return array(
					'es'        => 'http://example.com/hola/',
					'en'        => 'http://example.com/en/hello/',
					'x-default' => 'http://example.com/hola/',
				);
			}
		);

		ob_start();
		$this->filter->render_hreflang_tags();
		$output = ob_get_clean();

		$this->assertStringContainsString( '<link rel="alternate" hreflang="es" href="http://example.com/hola/" />', $output );
		$this->assertStringContainsString( '<link rel="alternate" hreflang="en" href="http://example.com/en/hello/" />', $output );
		$this->assertStringContainsString( '<link rel="alternate" hreflang="x-default" href="http://example.com/hola/" />', $output );
	}
}
