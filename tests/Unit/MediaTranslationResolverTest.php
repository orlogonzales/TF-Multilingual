<?php
/**
 * MediaTranslationResolver Unit Tests.
 *
 * @package TF\Multilingual\Tests\Unit
 */

declare( strict_types=1 );

namespace TF\Multilingual\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use TF\Multilingual\Domain\Language\Language;
use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Domain\Language\SettingsRepository;
use TF\Multilingual\Domain\Media\MediaTranslation;
use TF\Multilingual\Domain\Media\MediaTranslationRepository;
use TF\Multilingual\Domain\Media\MediaTranslationResolver;
use TF\Multilingual\Tests\Doubles\TestableWpdb;
use WP_Post;

/**
 * Class MediaTranslationResolverTest
 */
class MediaTranslationResolverTest extends TestCase {

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
	private LanguageRegistry $registry;

	/**
	 * Media repository.
	 *
	 * @var MediaTranslationRepository
	 */
	private MediaTranslationRepository $repository;

	/**
	 * Resolver under test.
	 *
	 * @var MediaTranslationResolver
	 */
	private MediaTranslationResolver $resolver;

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

		$settings_repo  = new SettingsRepository();
		$this->registry = new LanguageRegistry( $settings_repo );
		$this->registry->add_language( Language::create( 'es', 'es_ES', 'Spanish', 'Español', true, 10 ), true );
		$this->registry->add_language( Language::create( 'en', 'en_US', 'English', 'English', true, 20 ) );
		$this->registry->add_language( Language::create( 'pt', 'pt_PT', 'Portuguese', 'Português', true, 30 ) );

		$this->repository = new MediaTranslationRepository( $this->db, $this->registry );
		$this->resolver   = new MediaTranslationResolver(
			$this->repository,
			$this->registry
		);

		// Setup Core fixture attachment #100.
		$p               = new WP_Post();
		$p->ID           = 100;
		$p->post_type    = 'attachment';
		$p->post_title   = 'Foto Base Core';
		$p->post_excerpt = 'Leyenda Core';
		$p->post_content = 'Descripción Core';

		$GLOBALS['wp_test_posts'][100]                                = $p;
		$GLOBALS['wp_test_postmeta'][100]['_wp_attachment_image_alt'] = 'Alt Core';
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
	 * Tests resolve throws on invalid attachment ID.
	 *
	 * @return void
	 */
	public function test_resolve_invalid_id(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->resolver->resolve( 0 );
	}

	/**
	 * Tests resolve fallback when translation is missing in repository.
	 *
	 * Must fall back strictly to native Core metadata, NEVER to another language translation.
	 *
	 * @return void
	 */
	public function test_resolve_fallback_to_core(): void {
		$result = $this->resolver->resolve( 100, 'en' );

		$this->assertNotNull( $result );
		$this->assertTrue( $result->is_fallback() );
		$this->assertSame( 'en', $result->get_language_code() );
		$this->assertSame( 'Alt Core', $result->get_alt_text() );
		$this->assertSame( 'Foto Base Core', $result->get_title() );
		$this->assertSame( 'Leyenda Core', $result->get_caption() );
		$this->assertSame( 'Descripción Core', $result->get_description() );
	}

	/**
	 * Tests resolve returns TFML translated variant when present.
	 *
	 * @return void
	 */
	public function test_resolve_returns_tfml_variant(): void {
		$translation = MediaTranslation::create(
			100,
			'en',
			'English Alt',
			'English Title',
			'English Caption',
			'English Description'
		);
		$this->repository->save( $translation );

		$result = $this->resolver->resolve( 100, 'en' );

		$this->assertNotNull( $result );
		$this->assertFalse( $result->is_fallback() );
		$this->assertSame( 'en', $result->get_language_code() );
		$this->assertSame( 'English Alt', $result->get_alt_text() );
		$this->assertSame( 'English Title', $result->get_title() );
		$this->assertSame( 'English Caption', $result->get_caption() );
		$this->assertSame( 'English Description', $result->get_description() );
	}

	/**
	 * Tests isolation: language A does not fall back to language B.
	 *
	 * @return void
	 */
	public function test_no_cross_language_fallback(): void {
		// Save an ES translation with custom text.
		$this->repository->save( MediaTranslation::create( 100, 'es', 'Alt Español Personalizado' ) );

		// Resolve for 'pt' which has no translation.
		$pt_result = $this->resolver->resolve( 100, 'pt' );

		$this->assertNotNull( $pt_result );
		$this->assertTrue( $pt_result->is_fallback() );
		$this->assertSame( 'pt', $pt_result->get_language_code() );
		// Must be Core 'Alt Core', NOT 'Alt Español Personalizado'.
		$this->assertSame( 'Alt Core', $pt_result->get_alt_text() );
	}

	/**
	 * Tests in-memory caching and cache flush.
	 *
	 * @return void
	 */
	public function test_memory_caching_and_flush(): void {
		// Resolve initial fallback.
		$res1 = $this->resolver->resolve( 100, 'es' );
		$this->assertTrue( $res1->is_fallback() );

		// Save a translation in DB.
		$this->repository->save( MediaTranslation::create( 100, 'es', 'New Spanish Alt' ) );

		// Without flush, memory cache returns initial cached result.
		$res2 = $this->resolver->resolve( 100, 'es' );
		$this->assertSame( $res1, $res2 );

		// Flush cache for attachment 100.
		$this->resolver->flush_cache( 100 );

		// Now resolve retrieves updated translation from DB.
		$res3 = $this->resolver->resolve( 100, 'es' );
		$this->assertFalse( $res3->is_fallback() );
		$this->assertSame( 'New Spanish Alt', $res3->get_alt_text() );
	}

	/**
	 * Tests prime_cache batch loads translations in single operation.
	 *
	 * @return void
	 */
	public function test_prime_cache(): void {
		$p101               = new WP_Post();
		$p101->ID           = 101;
		$p101->post_type    = 'attachment';
		$p101->post_title   = 'Att 101';
		$p101->post_excerpt = '';
		$p101->post_content = '';

		$GLOBALS['wp_test_posts'][101] = $p101;

		$this->repository->save( MediaTranslation::create( 100, 'en', 'Alt 100 EN' ) );
		$this->repository->save( MediaTranslation::create( 101, 'en', 'Alt 101 EN' ) );

		// Prime cache for both.
		$this->resolver->prime_cache( array( 100, 101 ), 'en' );

		// Resolving now consumes from cache.
		$res100 = $this->resolver->resolve( 100, 'en' );
		$res101 = $this->resolver->resolve( 101, 'en' );

		$this->assertSame( 'Alt 100 EN', $res100->get_alt_text() );
		$this->assertSame( 'Alt 101 EN', $res101->get_alt_text() );
	}

	/**
	 * Tests adopt_core_metadata creates record if not existing.
	 *
	 * @return void
	 */
	public function test_adopt_core_metadata(): void {
		$this->assertFalse( $this->repository->exists( 100, 'es' ) );

		$adopted = $this->resolver->adopt_core_metadata( 100, 'es' );

		$this->assertFalse( $adopted->is_fallback() );
		$this->assertSame( 'es', $adopted->get_language_code() );
		$this->assertSame( 'Alt Core', $adopted->get_alt_text() );
		$this->assertSame( 'Foto Base Core', $adopted->get_title() );
		$this->assertSame( 'Leyenda Core', $adopted->get_caption() );
		$this->assertSame( 'Descripción Core', $adopted->get_description() );
		$this->assertTrue( $this->repository->exists( 100, 'es' ) );

		// Calling adopt again re-adopts current Core metadata.
		$this->repository->save( MediaTranslation::create( 100, 'es', 'Manually Edited Alt' ) );
		$re_adopted = $this->resolver->adopt_core_metadata( 100, 'es' );
		$this->assertSame( 'Alt Core', $re_adopted->get_alt_text() );
	}
}
