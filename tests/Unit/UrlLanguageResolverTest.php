<?php
/**
 * URL Language Resolver Test.
 *
 * @package TF\Multilingual\Tests\Unit
 */

declare( strict_types=1 );

namespace TF\Multilingual\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TF\Multilingual\Domain\Language\Language;
use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Domain\Language\SettingsRepository;
use TF\Multilingual\Routing\UrlLanguageResolution;
use TF\Multilingual\Routing\UrlLanguageResolver;

/**
 * Class UrlLanguageResolverTest
 */
class UrlLanguageResolverTest extends TestCase {

	/**
	 * Language registry.
	 *
	 * @var LanguageRegistry
	 */
	private LanguageRegistry $registry;

	/**
	 * Resolver instance.
	 *
	 * @var UrlLanguageResolver
	 */
	private UrlLanguageResolver $resolver;

	/**
	 * Sets up test environment.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->registry = new LanguageRegistry( new SettingsRepository() );
		$this->registry->add_language( new Language( 'es', 'es_ES', 'Spanish', 'Español', true, 1 ), true );
		$this->registry->add_language( new Language( 'en', 'en_US', 'English', 'English', true, 2 ) );
		$this->registry->add_language( new Language( 'pt-br', 'pt_BR', 'Portuguese', 'Português', true, 3 ) );
		$this->registry->add_language( new Language( 'fr', 'fr_FR', 'French', 'Français', false, 4 ) ); // Inactive!

		$this->resolver = new UrlLanguageResolver( $this->registry, 'http://example.com' );
	}

	/**
	 * Tests root URL resolves to default language without prefix.
	 *
	 * @return void
	 */
	public function test_root_resolves_to_default_language(): void {
		$this->assertSame( 'es', $this->resolver->resolve_from_url( 'http://example.com/' ) );
		$this->assertSame( 'es', $this->resolver->resolve_from_url( '/' ) );
		$this->assertSame( 'es', $this->resolver->resolve_from_url( '' ) );

		$res = $this->resolver->resolve( 'http://example.com/' );
		$this->assertSame( UrlLanguageResolution::STATUS_ACTIVE, $res->get_status() );
		$this->assertSame( 'es', $res->get_language_code() );
		$this->assertFalse( $res->has_explicit_prefix() );
	}

	/**
	 * Tests default language path without prefix resolves to default language.
	 *
	 * @return void
	 */
	public function test_unprefixed_path_resolves_to_default_language(): void {
		$this->assertSame( 'es', $this->resolver->resolve_from_url( 'http://example.com/tours/inca-trail/' ) );
		$this->assertSame( 'es', $this->resolver->resolve_from_url( '/contacto/' ) );

		$res = $this->resolver->resolve( '/tours/inca-trail/' );
		$this->assertSame( 'es', $res->get_language_code() );
		$this->assertFalse( $res->has_explicit_prefix() );
	}

	/**
	 * Tests active secondary language prefix resolves correctly.
	 *
	 * @return void
	 */
	public function test_secondary_language_prefix_resolves(): void {
		$this->assertSame( 'en', $this->resolver->resolve_from_url( 'http://example.com/en/' ) );
		$this->assertSame( 'en', $this->resolver->resolve_from_url( 'http://example.com/en/tours/inca-trail/' ) );
		$this->assertSame( 'pt-br', $this->resolver->resolve_from_url( 'http://example.com/pt-br/hotel/' ) );

		$res = $this->resolver->resolve( '/en/tours/inca-trail/' );
		$this->assertSame( 'en', $res->get_language_code() );
		$this->assertTrue( $res->has_explicit_prefix() );
	}

	/**
	 * Tests registered but inactive language NEVER falls back to default.
	 *
	 * @return void
	 */
	public function test_registered_inactive_language_does_not_fall_back_to_default(): void {
		// MUST NOT resolve to 'es'.
		$this->assertNull( $this->resolver->resolve_from_url( 'http://example.com/fr/tours/' ) );
		$this->assertNull( $this->resolver->resolve_from_url( '/fr/' ) );
		$this->assertNull( $this->resolver->resolve_from_url( '/fr/tours/inca-trail/' ) );

		$res = $this->resolver->resolve( 'http://example.com/fr/tours/' );
		$this->assertSame( UrlLanguageResolution::STATUS_INACTIVE, $res->get_status() );
		$this->assertSame( 'fr', $res->get_language_code() );
		$this->assertTrue( $res->is_inactive() );
		$this->assertFalse( $res->is_active() );
		$this->assertFalse( $res->is_valid() );
	}

	/**
	 * Tests unknown slug does NOT get hijacked as language error and resolves as default path.
	 *
	 * @return void
	 */
	public function test_unknown_slug_is_not_hijacked_and_resolves_to_default(): void {
		$this->assertSame( 'es', $this->resolver->resolve_from_url( 'http://example.com/blog/my-post/' ) );
		$this->assertSame( 'es', $this->resolver->resolve_from_url( 'http://example.com/hotel/' ) );
		$this->assertSame( 'es', $this->resolver->resolve_from_url( 'http://example.com/de/tours/' ) ); // 'de' not registered.

		$res = $this->resolver->resolve( 'http://example.com/hotel/' );
		$this->assertTrue( $res->is_active() );
		$this->assertSame( 'es', $res->get_language_code() );
		$this->assertFalse( $res->has_explicit_prefix() );
	}

	/**
	 * Tests safe degradation when configured default language is inactive or corrupt.
	 *
	 * @return void
	 */
	public function test_inconsistent_inactive_default_language_fails_safely(): void {
		// Simulate corrupted state: default language deactivated in repository data.
		$fake_repo = new class() extends SettingsRepository {
			/**
			 * Load fake payload with inactive default.
			 *
			 * @return array{default_language: string|null, languages: array<string, array<string, mixed>>}
			 */
			public function load(): array {
				return array(
					'default_language' => 'es',
					'languages'        => array(
						'es' => array(
							'code'        => 'es',
							'name'        => 'Spanish',
							'native_name' => 'Español',
							'locale'      => 'es_ES',
							'active'      => false, // Inactive default!
							'order'       => 1,
						),
						'en' => array(
							'code'        => 'en',
							'name'        => 'English',
							'native_name' => 'English',
							'locale'      => 'en_US',
							'active'      => true,
							'order'       => 2,
						),
					),
				);
			}
		};

		$registry = new LanguageRegistry( $fake_repo );
		$resolver = new UrlLanguageResolver( $registry, 'http://example.com' );

		$this->assertNull( $resolver->resolve_from_url( 'http://example.com/tours/' ) );
		$this->assertNull( $resolver->resolve_from_url( 'http://example.com/' ) );

		$res = $resolver->resolve( 'http://example.com/tours/' );
		$this->assertSame( UrlLanguageResolution::STATUS_DEFAULT_INACTIVE, $res->get_status() );
		$this->assertTrue( $res->is_default_inactive() );
		$this->assertFalse( $res->is_active() );
	}

	/**
	 * Tests unconfigured registry returns null and NOT_CONFIGURED status.
	 *
	 * @return void
	 */
	public function test_unconfigured_registry_returns_null(): void {
		$empty_registry = new LanguageRegistry( new SettingsRepository() );
		$resolver       = new UrlLanguageResolver( $empty_registry );

		$this->assertNull( $resolver->resolve_from_url( '/' ) );
		$this->assertNull( $resolver->resolve_from_url( '/en/' ) );

		$res = $resolver->resolve( '/en/' );
		$this->assertSame( UrlLanguageResolution::STATUS_NOT_CONFIGURED, $res->get_status() );
		$this->assertTrue( $res->is_not_configured() );
	}

	/**
	 * Tests system routes are excluded from multilingual resolution.
	 *
	 * @return void
	 */
	public function test_system_routes_are_excluded(): void {
		$this->assertNull( $this->resolver->resolve_from_url( 'http://example.com/wp-admin/' ) );
		$this->assertNull( $this->resolver->resolve_from_url( 'http://example.com/wp-login.php' ) );
		$this->assertNull( $this->resolver->resolve_from_url( 'http://example.com/wp-json/wp/v2/posts' ) );
		$this->assertNull( $this->resolver->resolve_from_url( 'http://example.com/xmlrpc.php' ) );
		$this->assertNull( $this->resolver->resolve_from_url( 'http://example.com/wp-cron.php' ) );

		$res = $this->resolver->resolve( 'http://example.com/wp-admin/edit.php' );
		$this->assertSame( UrlLanguageResolution::STATUS_EXCLUDED, $res->get_status() );
		$this->assertTrue( $res->is_excluded() );
	}

	/**
	 * Tests subdirectory installation paths.
	 *
	 * @return void
	 */
	public function test_subdirectory_installations(): void {
		$sub_resolver = new UrlLanguageResolver( $this->registry, 'http://example.com/cms' );

		$this->assertSame( 'es', $sub_resolver->resolve_from_url( 'http://example.com/cms/' ) );
		$this->assertSame( 'en', $sub_resolver->resolve_from_url( 'http://example.com/cms/en/' ) );
		$this->assertSame( 'en', $sub_resolver->resolve_from_url( 'http://example.com/cms/en/tours/' ) );
		$this->assertNull( $sub_resolver->resolve_from_url( 'http://example.com/cms/fr/tours/' ) );
	}

	/**
	 * Tests prefix extraction.
	 *
	 * @return void
	 */
	public function test_extract_prefix(): void {
		$this->assertSame( 'en', $this->resolver->extract_prefix( '/en/tours/' ) );
		$this->assertSame( 'pt-br', $this->resolver->extract_prefix( '/pt-br/hotel/' ) );
		$this->assertNull( $this->resolver->extract_prefix( '/es/tours/' ) ); // Default language unprefixed.
		$this->assertNull( $this->resolver->extract_prefix( '/fr/tours/' ) ); // Inactive language.
		$this->assertNull( $this->resolver->extract_prefix( '/hotel/' ) ); // Unknown slug.
	}

	/**
	 * Tests prefix stripping.
	 *
	 * @return void
	 */
	public function test_strip_prefix(): void {
		$this->assertSame( 'tours/', $this->resolver->strip_prefix( '/en/tours/' ) );
		$this->assertSame( 'hotel/', $this->resolver->strip_prefix( '/pt-br/hotel/' ) );
		$this->assertSame( 'tours/', $this->resolver->strip_prefix( '/es/tours/' ) );
		$this->assertSame( 'hotel/', $this->resolver->strip_prefix( '/hotel/' ) );
	}
}
