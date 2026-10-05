<?php
/**
 * Localized URL Generator Test.
 *
 * @package TF\Multilingual\Tests\Unit
 */

declare( strict_types=1 );

namespace TF\Multilingual\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TF\Multilingual\Domain\Language\Exceptions\InvalidLanguageException;
use TF\Multilingual\Domain\Language\Exceptions\LanguageNotFoundException;
use TF\Multilingual\Domain\Language\Language;
use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Domain\Language\SettingsRepository;
use TF\Multilingual\Domain\Translation\ContentTranslationResolver;
use TF\Multilingual\Domain\Translation\TranslationGroupRepository;
use TF\Multilingual\Routing\LocalizedUrlGenerator;
use TF\Multilingual\Routing\UrlLanguageResolver;
use TF\Multilingual\Tests\Doubles\TestableWpdb;

/**
 * Class LocalizedUrlGeneratorTest
 */
class LocalizedUrlGeneratorTest extends TestCase {

	/**
	 * Registry.
	 *
	 * @var LanguageRegistry
	 */
	private LanguageRegistry $registry;

	/**
	 * Generator.
	 *
	 * @var LocalizedUrlGenerator
	 */
	private LocalizedUrlGenerator $generator;

	/**
	 * Translation repository.
	 *
	 * @var TranslationGroupRepository
	 */
	private TranslationGroupRepository $repository;

	/**
	 * Sets up test environment.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->registry = new LanguageRegistry( new SettingsRepository() );
		$this->registry->add_language( new Language( 'es', 'es_ES', 'Spanish', 'Español', true, 1 ), true );
		$this->registry->add_language( new Language( 'en', 'en_US', 'English', 'English', true, 2 ) );
		$this->registry->add_language( new Language( 'pt-br', 'pt_BR', 'Portuguese', 'Português', true, 3 ) );
		$this->registry->add_language( new Language( 'fr', 'fr_FR', 'French', 'Français', false, 4 ) ); // Inactive.

		$url_resolver = new UrlLanguageResolver( $this->registry, 'http://example.com' );
		$wpdb         = new TestableWpdb();

		$this->repository = new TranslationGroupRepository( $wpdb );
		$content_resolver = new ContentTranslationResolver( $this->repository, $this->registry );
		$this->generator  = new LocalizedUrlGenerator( $this->registry, $url_resolver, $content_resolver, 'http://example.com' );
	}

	/**
	 * Tests home URL generation.
	 *
	 * @return void
	 */
	public function test_home_url(): void {
		$this->assertSame( 'http://example.com/', $this->generator->home_url( 'es' ) );
		$this->assertSame( 'http://example.com/en/', $this->generator->home_url( 'en' ) );
		$this->assertSame( 'http://example.com/pt-br/', $this->generator->home_url( 'pt-br' ) );
		$this->assertSame( 'http://example.com/en/tours/', $this->generator->home_url( 'en', 'tours' ) );
	}

	/**
	 * Tests localize_url preserves query strings.
	 *
	 * @return void
	 */
	public function test_localize_url_preserves_query_string(): void {
		$url      = 'http://example.com/tours/?foo=bar&baz=1';
		$expected = 'http://example.com/en/tours/?foo=bar&baz=1';

		$this->assertSame( $expected, $this->generator->localize_url( $url, 'en' ) );
	}

	/**
	 * Tests localize_url preserves fragments.
	 *
	 * @return void
	 */
	public function test_localize_url_preserves_fragment(): void {
		$url      = 'http://example.com/tours/foo/#section';
		$expected = 'http://example.com/en/tours/foo/#section';

		$this->assertSame( $expected, $this->generator->localize_url( $url, 'en' ) );
	}

	/**
	 * Tests localize_url preserves custom ports.
	 *
	 * @return void
	 */
	public function test_localize_url_preserves_port(): void {
		$url      = 'http://example.com:8080/tours/';
		$expected = 'http://example.com:8080/en/tours/';

		$this->assertSame( $expected, $this->generator->localize_url( $url, 'en' ) );
	}

	/**
	 * Tests external URLs remain completely unmodified.
	 *
	 * @return void
	 */
	public function test_external_urls_remain_unmodified(): void {
		$external = 'https://external.example/tours/?ref=123#about';

		$this->assertSame( $external, $this->generator->localize_url( $external, 'en' ) );
		$this->assertSame( $external, $this->generator->localize_url( $external, 'es' ) );
	}

	/**
	 * Tests subdirectory installation paths are correctly preserved.
	 *
	 * @return void
	 */
	public function test_subdirectory_installation_urls(): void {
		$wpdb             = new TestableWpdb();
		$content_resolver = new ContentTranslationResolver( new TranslationGroupRepository( $wpdb ), $this->registry );
		$sub_resolver     = new UrlLanguageResolver( $this->registry, 'http://example.com/wordpress' );
		$generator        = new LocalizedUrlGenerator( $this->registry, $sub_resolver, $content_resolver, 'http://example.com/wordpress' );

		// Base home in subdirectory.
		$this->assertSame( 'http://example.com/wordpress/en/', $generator->localize_url( 'http://example.com/wordpress/', 'en' ) );

		// Path in subdirectory: must be /wordpress/en/tours/, NOT /en/wordpress/tours/.
		$this->assertSame( 'http://example.com/wordpress/en/tours/', $generator->localize_url( 'http://example.com/wordpress/tours/', 'en' ) );

		// Stripping secondary from subdirectory.
		$this->assertSame( 'http://example.com/wordpress/tours/', $generator->localize_url( 'http://example.com/wordpress/en/tours/', 'es' ) );
	}

	/**
	 * Tests swapping between secondary languages without double prefixes.
	 *
	 * @return void
	 */
	public function test_secondary_to_secondary_swap(): void {
		$url      = 'http://example.com/en/tours/';
		$expected = 'http://example.com/pt-br/tours/';

		$this->assertSame( $expected, $this->generator->localize_url( $url, 'pt-br' ) );
	}

	/**
	 * Tests inactive language throws InvalidLanguageException.
	 *
	 * @return void
	 */
	public function test_inactive_target_language_throws_exception(): void {
		$this->expectException( InvalidLanguageException::class );
		$this->generator->localize_url( 'http://example.com/tours/', 'fr' );
	}

	/**
	 * Tests non-existent language throws LanguageNotFoundException.
	 *
	 * @return void
	 */
	public function test_nonexistent_target_language_throws_exception(): void {
		$this->expectException( LanguageNotFoundException::class );
		$this->generator->localize_url( 'http://example.com/tours/', 'de' );
	}

	/**
	 * Tests get_post_translation_url returns null when untranslated.
	 *
	 * @return void
	 */
	public function test_untranslated_post_returns_null(): void {
		$this->assertNull( $this->generator->get_post_translation_url( 9999, 'en' ) );
	}

	/**
	 * Tests get_term_translation_url returns null when untranslated.
	 *
	 * @return void
	 */
	public function test_untranslated_term_returns_null(): void {
		$this->assertNull( $this->generator->get_term_translation_url( 8888, 'en' ) );
	}
}
