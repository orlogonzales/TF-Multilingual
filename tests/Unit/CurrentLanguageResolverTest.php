<?php
/**
 * Current Language Resolver Test.
 *
 * @package TF\Multilingual\Tests\Unit
 */

declare( strict_types=1 );

namespace TF\Multilingual\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TF\Multilingual\Domain\Language\Language;
use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Domain\Language\SettingsRepository;
use TF\Multilingual\Routing\CurrentLanguageResolver;
use TF\Multilingual\Routing\UrlLanguageResolver;

/**
 * Class CurrentLanguageResolverTest
 */
class CurrentLanguageResolverTest extends TestCase {

	/**
	 * Registry.
	 *
	 * @var LanguageRegistry
	 */
	private LanguageRegistry $registry;

	/**
	 * Current language resolver.
	 *
	 * @var CurrentLanguageResolver
	 */
	private CurrentLanguageResolver $current_resolver;

	/**
	 * Sets up test environment.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->registry = new LanguageRegistry( new SettingsRepository() );
		$this->registry->add_language( new Language( 'es', 'es_ES', 'Spanish', 'Español', true, 1 ), true );
		$this->registry->add_language( new Language( 'en', 'en_US', 'English', 'English', true, 2 ) );
		$this->registry->add_language( new Language( 'fr', 'fr_FR', 'French', 'Français', false, 3 ) ); // Inactive.

		$url_resolver           = new UrlLanguageResolver( $this->registry, 'http://example.com' );
		$this->current_resolver = new CurrentLanguageResolver( $url_resolver );
	}

	/**
	 * Cleans up environment.
	 */
	protected function tearDown(): void {
		unset( $_SERVER['REQUEST_URI'] );
		parent::tearDown();
	}

	/**
	 * Tests default unprefixed request resolves to default language.
	 *
	 * @return void
	 */
	public function test_resolves_default_from_request_uri(): void {
		$_SERVER['REQUEST_URI'] = '/tours/inca-trail/';
		$this->current_resolver->reset();

		$this->assertSame( 'es', $this->current_resolver->get_current_language() );
	}

	/**
	 * Tests secondary active prefix resolves to secondary language.
	 *
	 * @return void
	 */
	public function test_resolves_secondary_from_request_uri(): void {
		$_SERVER['REQUEST_URI'] = '/en/tours/inca-trail/';
		$this->current_resolver->reset();

		$this->assertSame( 'en', $this->current_resolver->get_current_language() );
	}

	/**
	 * Tests inactive language prefix returns null.
	 *
	 * @return void
	 */
	public function test_inactive_language_returns_null(): void {
		$_SERVER['REQUEST_URI'] = '/fr/tours/inca-trail/';
		$this->current_resolver->reset();

		$this->assertNull( $this->current_resolver->get_current_language() );
	}

	/**
	 * Tests manual override and reset.
	 *
	 * @return void
	 */
	public function test_manual_override_and_reset(): void {
		$_SERVER['REQUEST_URI'] = '/';
		$this->current_resolver->reset();

		$this->assertSame( 'es', $this->current_resolver->get_current_language() );

		$this->current_resolver->set_current_language( 'en' );
		$this->assertSame( 'en', $this->current_resolver->get_current_language() );

		$this->current_resolver->reset();
		$this->assertSame( 'es', $this->current_resolver->get_current_language() );
	}
}
