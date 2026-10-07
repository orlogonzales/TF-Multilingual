<?php
/**
 * Block Navigation Frontend Filter Tests (Gutenberg / FSE).
 *
 * @package TF\Multilingual\Tests\Unit
 */

declare( strict_types=1 );

namespace TF\Multilingual\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TF\Multilingual\Domain\Language\Language;
use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Domain\Language\SettingsRepository;
use TF\Multilingual\Domain\Navigation\BlockNavigationFrontendFilter;
use TF\Multilingual\Domain\Translation\ContentTranslationResolver;
use TF\Multilingual\Domain\Translation\TranslationElement;
use TF\Multilingual\Domain\Translation\TranslationGroup;
use TF\Multilingual\Domain\Translation\TranslationGroupRepository;
use TF\Multilingual\Routing\CurrentLanguageResolver;
use TF\Multilingual\Tests\Doubles\TestableWpdb;

/**
 * Class BlockNavigationFrontendFilterTest
 */
class BlockNavigationFrontendFilterTest extends TestCase {

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
	 * Block filter under test.
	 *
	 * @var BlockNavigationFrontendFilter
	 */
	private BlockNavigationFrontendFilter $filter;

	/**
	 * Simulated current language.
	 *
	 * @var string
	 */
	private string $current_language = 'es';

	/**
	 * Sets up test fixtures.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->wpdb         = new TestableWpdb();
		$this->wpdb->prefix = 'wp_';
		$GLOBALS['wpdb']    = $this->wpdb;

		$GLOBALS['wp_test_options'] = array();

		$this->settings_repo = new SettingsRepository();
		$this->lang_registry = new LanguageRegistry( $this->settings_repo );

		$es = Language::create( 'es', 'es_ES', 'Español', 'Español', true, 10 );
		$en = Language::create( 'en', 'en_US', 'English', 'English', true, 20 );

		$this->lang_registry->add_language( $es, true );
		$this->lang_registry->add_language( $en );

		$this->group_repo = new TranslationGroupRepository( $this->wpdb, $this->lang_registry );
		$this->resolver   = new ContentTranslationResolver( $this->group_repo, $this->lang_registry );

		$current_lang_resolver = $this->createMock( CurrentLanguageResolver::class );
		$current_lang_resolver->method( 'get_current_language' )
			->willReturnCallback( fn(): string => $this->current_language );

		$this->filter = new BlockNavigationFrontendFilter(
			$current_lang_resolver,
			$this->lang_registry,
			$this->resolver
		);
	}

	/**
	 * Tears down test environment.
	 */
	protected function tearDown(): void {
		$GLOBALS['wpdb'] = new \wpdb();
		unset( $GLOBALS['wp_test_options'] );
		parent::tearDown();
	}

	/**
	 * Tests non-navigation blocks are returned unmodified.
	 */
	public function test_filter_ignores_non_navigation_blocks(): void {
		$block = array(
			'blockName' => 'core/paragraph',
			'attrs'     => array( 'content' => 'Hello' ),
		);

		$result = $this->filter->filter_render_block_data( $block, $block );
		$this->assertSame( $block, $result );
	}

	/**
	 * Tests core/navigation blocks are untouched in default language.
	 */
	public function test_filter_leaves_navigation_block_untouched_in_default_language(): void {
		$this->current_language = 'es';

		$block = array(
			'blockName' => 'core/navigation',
			'attrs'     => array( 'ref' => 1001 ),
		);

		$result = $this->filter->filter_render_block_data( $block, $block );
		$this->assertSame( 1001, $result['attrs']['ref'] );
	}

	/**
	 * Tests core/navigation block swaps ref attribute to translated wp_navigation post in secondary language.
	 */
	public function test_filter_swaps_ref_to_translated_navigation_post(): void {
		$this->current_language = 'en';

		// Group linking wp_navigation post 1001 (es) to 1002 (en)
		$group = $this->group_repo->create_group( 'post', 'wp_navigation', 1001, 'es' );
		$this->group_repo->add_translation( $group->get_id(), 1002, 'en' );

		$source_block = array(
			'blockName' => 'core/navigation',
			'attrs'     => array( 'ref' => 1001 ),
		);
		$parsed_block = $source_block;

		$result = $this->filter->filter_render_block_data( $parsed_block, $source_block );

		// Verifies ephemeral swap during render
		$this->assertSame( 1002, $result['attrs']['ref'] );
		// Verifies source block in memory is NOT mutated
		$this->assertSame( 1001, $source_block['attrs']['ref'] );
	}

	/**
	 * Tests untranslated wp_navigation block retains original ref as fallback.
	 */
	public function test_untranslated_navigation_block_retains_original_ref(): void {
		$this->current_language = 'en';

		$block = array(
			'blockName' => 'core/navigation',
			'attrs'     => array( 'ref' => 9999 ),
		);

		$result = $this->filter->filter_render_block_data( $block, $block );
		$this->assertSame( 9999, $result['attrs']['ref'] );
	}
}
