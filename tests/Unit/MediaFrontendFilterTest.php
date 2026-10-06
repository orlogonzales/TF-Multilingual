<?php
/**
 * MediaFrontendFilter Unit Tests.
 *
 * @package TF\Multilingual\Tests\Unit
 */

declare( strict_types=1 );

namespace TF\Multilingual\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TF\Multilingual\Domain\Language\Language;
use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Domain\Language\SettingsRepository;
use TF\Multilingual\Domain\Media\MediaFrontendFilter;
use TF\Multilingual\Domain\Media\MediaTranslation;
use TF\Multilingual\Domain\Media\MediaTranslationRepository;
use TF\Multilingual\Domain\Media\MediaTranslationResolver;
use TF\Multilingual\Routing\CurrentLanguageResolver;
use TF\Multilingual\Routing\UrlLanguageResolver;
use TF\Multilingual\Tests\Doubles\TestableWpdb;
use WP_Post;

/**
 * Class MediaFrontendFilterTest
 */
class MediaFrontendFilterTest extends TestCase {

	/**
	 * Database double.
	 *
	 * @var TestableWpdb
	 */
	private TestableWpdb $db;

	/**
	 * Repository.
	 *
	 * @var MediaTranslationRepository
	 */
	private MediaTranslationRepository $repository;

	/**
	 * Resolver.
	 *
	 * @var MediaTranslationResolver
	 */
	private MediaTranslationResolver $resolver;

	/**
	 * Filter under test.
	 *
	 * @var MediaFrontendFilter
	 */
	private MediaFrontendFilter $filter;

	/**
	 * Current language resolver.
	 *
	 * @var CurrentLanguageResolver
	 */
	private CurrentLanguageResolver $lang_resolver;

	/**
	 * Setup.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->db                    = new TestableWpdb();
		$GLOBALS['wp_test_posts']    = array();
		$GLOBALS['wp_test_postmeta'] = array();

		$settings_repo = new SettingsRepository();
		$registry      = new LanguageRegistry( $settings_repo );
		$registry->add_language( Language::create( 'es', 'es_ES', 'Spanish', 'Español', true, 10 ), true );
		$registry->add_language( Language::create( 'en', 'en_US', 'English', 'English', true, 20 ) );

		$url_resolver        = new UrlLanguageResolver( $registry );
		$this->lang_resolver = new CurrentLanguageResolver( $url_resolver );
		$this->lang_resolver->set_current_language( 'en' );

		$this->repository = new MediaTranslationRepository( $this->db, $registry );
		$this->resolver   = new MediaTranslationResolver( $this->repository, $registry );
		$this->filter     = new MediaFrontendFilter( $this->resolver, $this->lang_resolver, $this->repository );

		$p               = new WP_Post();
		$p->ID           = 200;
		$p->post_type    = 'attachment';
		$p->post_title   = 'Attachment Core Title';
		$p->post_excerpt = 'Attachment Core Caption';
		$p->post_content = 'Attachment Core Description';

		$GLOBALS['wp_test_posts'][200]                                = $p;
		$GLOBALS['wp_test_postmeta'][200]['_wp_attachment_image_alt'] = 'Attachment Core Alt';
	}

	/**
	 * Tear down.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$GLOBALS['wp_test_posts']    = array();
		$GLOBALS['wp_test_postmeta'] = array();
		parent::tearDown();
	}

	/**
	 * Tests filter_attachment_image_attributes replaces alt text with translated variant.
	 *
	 * @return void
	 */
	public function test_filter_image_attributes_with_translation(): void {
		$this->repository->save( MediaTranslation::create( 200, 'en', 'Translated English Alt' ) );

		$post            = new WP_Post();
		$post->ID        = 200;
		$post->post_type = 'attachment';

		$attr     = array(
			'src' => 'https://example.com/img.jpg',
			'alt' => 'Old Alt',
		);
		$filtered = $this->filter->filter_attachment_image_attributes( $attr, $post, 'thumbnail' );

		$this->assertSame( 'Translated English Alt', $filtered['alt'] );
		$this->assertSame( 'https://example.com/img.jpg', $filtered['src'] );
	}

	/**
	 * Tests filter_attachment_image_attributes honors decorative empty alt.
	 *
	 * @return void
	 */
	public function test_filter_image_attributes_decorative_empty_alt(): void {
		$this->repository->save( MediaTranslation::create( 200, 'en', '' ) );

		$post            = new WP_Post();
		$post->ID        = 200;
		$post->post_type = 'attachment';

		$attr     = array( 'alt' => 'Original Core Alt' );
		$filtered = $this->filter->filter_attachment_image_attributes( $attr, $post, 'full' );

		$this->assertSame( '', $filtered['alt'] );
	}

	/**
	 * Tests filter_attachment_image_attributes falls back to Core alt when untranslated.
	 *
	 * @return void
	 */
	public function test_filter_image_attributes_fallback_to_core(): void {
		$post            = new WP_Post();
		$post->ID        = 200;
		$post->post_type = 'attachment';

		$attr     = array( 'alt' => 'Default' );
		$filtered = $this->filter->filter_attachment_image_attributes( $attr, $post, 'medium' );

		$this->assertSame( 'Attachment Core Alt', $filtered['alt'] );
	}

	/**
	 * Tests filter_attachment_caption replaces caption with translated variant.
	 *
	 * @return void
	 */
	public function test_filter_caption_with_translation(): void {
		$this->repository->save( MediaTranslation::create( 200, 'en', 'Alt', 'Title', 'Translated English Caption' ) );

		$filtered = $this->filter->filter_attachment_caption( 'Old Caption', 200 );
		$this->assertSame( 'Translated English Caption', $filtered );
	}

	/**
	 * Tests filter_attachment_caption falls back to Core caption when untranslated.
	 *
	 * @return void
	 */
	public function test_filter_caption_fallback(): void {
		$filtered = $this->filter->filter_attachment_caption( 'Fallback Caption', 200 );
		$this->assertSame( 'Fallback Caption', $filtered );
	}

	/**
	 * Tests on_delete_attachment deletes all translations and flushes cache.
	 *
	 * @return void
	 */
	public function test_handle_delete_attachment(): void {
		$this->repository->save( MediaTranslation::create( 200, 'en', 'Alt EN' ) );
		$this->repository->save( MediaTranslation::create( 200, 'es', 'Alt ES' ) );

		$this->assertTrue( $this->repository->exists( 200, 'en' ) );
		$this->assertTrue( $this->repository->exists( 200, 'es' ) );

		$this->filter->on_delete_attachment( 200 );

		$this->assertFalse( $this->repository->exists( 200, 'en' ) );
		$this->assertFalse( $this->repository->exists( 200, 'es' ) );
	}
}
