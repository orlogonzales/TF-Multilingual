<?php
/**
 * String Translation Service Test.
 *
 * @package TF\Multilingual\Tests\Unit
 */

declare( strict_types=1 );

namespace TF\Multilingual\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TF\Multilingual\Domain\Language\Language;
use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Domain\Language\SettingsRepository;
use TF\Multilingual\Domain\Strings\StringRepository;
use TF\Multilingual\Domain\Strings\StringStatus;
use TF\Multilingual\Domain\Strings\StringTranslation;
use TF\Multilingual\Domain\Strings\StringTranslationService;
use TF\Multilingual\Domain\Strings\TranslatableString;
use TF\Multilingual\Routing\CurrentLanguageResolver;
use TF\Multilingual\Routing\UrlLanguageResolver;

/**
 * Class StringTranslationServiceTest
 */
class StringTranslationServiceTest extends TestCase {

	/**
	 * Setup language registry with ES (default), EN (active), PT (active), and FR (inactive).
	 *
	 * @return LanguageRegistry
	 */
	private function create_language_registry(): LanguageRegistry {
		$GLOBALS['wp_test_options'] = array();
		$registry                   = new LanguageRegistry( new SettingsRepository() );

		$es = new Language( 'es', 'es_ES', 'Español', 'Spanish', true, 1 );
		$en = new Language( 'en', 'en_US', 'English', 'English', true, 2 );
		$pt = new Language( 'pt', 'pt_PT', 'Português', 'Portuguese', true, 3 );
		$fr = new Language( 'fr', 'fr_FR', 'Français', 'French', false, 4 );

		$registry->add_language( $es, true );
		$registry->add_language( $en );
		$registry->add_language( $pt );
		$registry->add_language( $fr );

		return $registry;
	}

	/**
	 * Test default language returns source/default text without DB queries.
	 */
	public function test_default_language_returns_source_text(): void {
		$registry     = $this->create_language_registry();
		$url_resolver = new UrlLanguageResolver( $registry );
		$current_lang = new CurrentLanguageResolver( $url_resolver );
		$current_lang->set_current_language( 'es' );

		$repo = $this->createMock( StringRepository::class );
		$repo->expects( $this->never() )->method( 'get_preloaded_translation' );
		$repo->expects( $this->never() )->method( 'find_by_domain_and_key' );

		$service = new StringTranslationService( $repo, $registry, $current_lang );
		$result  = $service->translate( 'theme', 'header.cta', 'Reservar ahora' );

		$this->assertSame( 'Reservar ahora', $result );
	}

	/**
	 * Test preloaded translation returns translated value in secondary language.
	 */
	public function test_secondary_language_returns_preloaded_translation(): void {
		$registry     = $this->create_language_registry();
		$url_resolver = new UrlLanguageResolver( $registry );
		$current_lang = new CurrentLanguageResolver( $url_resolver );
		$current_lang->set_current_language( 'en' );

		$translation = new StringTranslation( 1, 10, 'en', 'Book now', 1, StringStatus::DB_UP_TO_DATE );

		$repo = $this->createMock( StringRepository::class );
		$repo->expects( $this->once() )
			->method( 'get_preloaded_translation' )
			->with( 'theme', 'header.cta', 'en' )
			->willReturn( $translation );

		$service = new StringTranslationService( $repo, $registry, $current_lang );
		$result  = $service->translate( 'theme', 'header.cta', 'Reservar ahora' );

		$this->assertSame( 'Book now', $result );
	}

	/**
	 * Test translation in status REVIEW is returned in frontend (visual continuity).
	 */
	public function test_review_status_returns_translation_in_frontend(): void {
		$registry     = $this->create_language_registry();
		$url_resolver = new UrlLanguageResolver( $registry );
		$current_lang = new CurrentLanguageResolver( $url_resolver );
		$current_lang->set_current_language( 'en' );

		$translation = new StringTranslation( 1, 10, 'en', 'Book now (v1)', 1, StringStatus::DB_NEEDS_REVIEW );

		$repo = $this->createMock( StringRepository::class );
		$repo->expects( $this->once() )
			->method( 'get_preloaded_translation' )
			->with( 'theme', 'header.cta', 'en' )
			->willReturn( $translation );

		$service = new StringTranslationService( $repo, $registry, $current_lang );
		$result  = $service->translate( 'theme', 'header.cta', 'Reservar tu tour' );

		$this->assertSame( 'Book now (v1)', $result );
	}

	/**
	 * Test untranslated string falls back to default source text (Zero lateral fallback).
	 */
	public function test_untranslated_falls_back_to_default_text(): void {
		$registry     = $this->create_language_registry();
		$url_resolver = new UrlLanguageResolver( $registry );
		$current_lang = new CurrentLanguageResolver( $url_resolver );
		$current_lang->set_current_language( 'en' );

		$repo = $this->createMock( StringRepository::class );
		$repo->expects( $this->once() )
			->method( 'get_preloaded_translation' )
			->with( 'theme', 'untranslated.key', 'en' )
			->willReturn( null );

		$string = new TranslatableString( 20, 'theme', 'untranslated.key', '', 'Texto base', 'es', 1 );
		$repo->expects( $this->once() )
			->method( 'find_by_domain_and_key' )
			->with( 'theme', 'untranslated.key' )
			->willReturn( $string );

		$repo->expects( $this->once() )
			->method( 'get_translation' )
			->with( 20, 'en' )
			->willReturn( null );

		$service = new StringTranslationService( $repo, $registry, $current_lang );
		$result  = $service->translate( 'theme', 'untranslated.key', 'Texto base' );

		$this->assertSame( 'Texto base', $result );
	}

	/**
	 * Test inactive language returns default source text without lateral degradation.
	 */
	public function test_inactive_language_returns_default_text(): void {
		$registry     = $this->create_language_registry();
		$url_resolver = new UrlLanguageResolver( $registry );
		$current_lang = new CurrentLanguageResolver( $url_resolver );

		$repo = $this->createMock( StringRepository::class );
		$repo->expects( $this->never() )->method( 'get_preloaded_translation' );

		$service = new StringTranslationService( $repo, $registry, $current_lang );
		// FR is registered but inactive.
		$result = $service->translate( 'theme', 'header.cta', 'Reservar ahora', 'fr' );

		$this->assertSame( 'Reservar ahora', $result );
	}

	/**
	 * Test placeholder matching validation.
	 */
	public function test_placeholder_validation(): void {
		$registry     = $this->create_language_registry();
		$url_resolver = new UrlLanguageResolver( $registry );
		$current_lang = new CurrentLanguageResolver( $url_resolver );
		$repo         = $this->createMock( StringRepository::class );

		$service = new StringTranslationService( $repo, $registry, $current_lang );

		// Matching single placeholder.
		$this->assertTrue( $service->has_matching_placeholders( 'Hello %s', 'Hola %s' ) );

		// Matching multiple placeholders.
		$this->assertTrue( $service->has_matching_placeholders( '%d tours found in %s', '%d paseos encontrados en %s' ) );

		// Matching numbered placeholders.
		$this->assertTrue( $service->has_matching_placeholders( '%1$s has %2$d rooms', '%1$s tiene %2$d habitaciones' ) );

		// Mismatch in count.
		$this->assertFalse( $service->has_matching_placeholders( 'Hello %s %s', 'Hola %s' ) );

		// Mismatch in type.
		$this->assertFalse( $service->has_matching_placeholders( 'Price: %d', 'Price: %s' ) );

		// No placeholders.
		$this->assertTrue( $service->has_matching_placeholders( 'Plain text', 'Texto plano' ) );
	}

	/**
	 * Test preload domain delegation.
	 */
	public function test_preload_domain_delegates(): void {
		$registry     = $this->create_language_registry();
		$url_resolver = new UrlLanguageResolver( $registry );
		$current_lang = new CurrentLanguageResolver( $url_resolver );

		$repo = $this->createMock( StringRepository::class );
		$repo->expects( $this->once() )
			->method( 'preload_domain' )
			->with( 'travel-flow', 'en' );

		$service = new StringTranslationService( $repo, $registry, $current_lang );
		$service->preload_domain( 'travel-flow', 'en' );
	}
}
