<?php
/**
 * Language Flag Renderer Unit Tests.
 *
 * @package TF\Multilingual\Tests\Unit
 */

declare( strict_types=1 );

namespace TF\Multilingual\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TF\Multilingual\Domain\Language\Language;
use TF\Multilingual\Domain\Language\LanguageFlagRenderer;

/**
 * Class LanguageFlagRendererTest
 */
class LanguageFlagRendererTest extends TestCase {

	/**
	 * Tests resolve_flag_code with default mapping rules.
	 */
	public function test_resolve_flag_code_defaults(): void {
		$this->assertSame( 'es', LanguageFlagRenderer::resolve_flag_code( 'es' ) );
		$this->assertSame( 'us', LanguageFlagRenderer::resolve_flag_code( 'en' ) );
		$this->assertSame( 'us', LanguageFlagRenderer::resolve_flag_code( 'en-us' ) );
		$this->assertSame( 'gb', LanguageFlagRenderer::resolve_flag_code( 'en-gb' ) );
		$this->assertSame( 'pt', LanguageFlagRenderer::resolve_flag_code( 'pt' ) );
		$this->assertSame( 'pt', LanguageFlagRenderer::resolve_flag_code( 'pt-pt' ) );
		$this->assertSame( 'br', LanguageFlagRenderer::resolve_flag_code( 'pt-br' ) );
		$this->assertSame( 'fr', LanguageFlagRenderer::resolve_flag_code( 'fr' ) );
		$this->assertSame( 'de', LanguageFlagRenderer::resolve_flag_code( 'de' ) );
	}

	/**
	 * Tests resolve_flag_code respects explicit custom override.
	 */
	public function test_resolve_flag_code_with_custom_override(): void {
		$this->assertSame( 'gb', LanguageFlagRenderer::resolve_flag_code( 'en', 'gb' ) );
		$this->assertSame( 'br', LanguageFlagRenderer::resolve_flag_code( 'pt', 'br' ) );
		$this->assertSame( 'es', LanguageFlagRenderer::resolve_flag_code( 'es', 'es' ) );
		// Empty override falls back to default mapping.
		$this->assertSame( 'us', LanguageFlagRenderer::resolve_flag_code( 'en', '' ) );
		$this->assertSame( 'us', LanguageFlagRenderer::resolve_flag_code( 'en', null ) );
	}

	/**
	 * Tests get_available_flags provides expected national choices.
	 */
	public function test_get_available_flags(): void {
		$flags = LanguageFlagRenderer::get_available_flags();

		$this->assertArrayHasKey( 'es', $flags );
		$this->assertArrayHasKey( 'us', $flags );
		$this->assertArrayHasKey( 'gb', $flags );
		$this->assertArrayHasKey( 'pt', $flags );
		$this->assertArrayHasKey( 'br', $flags );
		$this->assertArrayHasKey( 'fr', $flags );
	}

	/**
	 * Tests get_flag_url returns valid web URLs for existing assets.
	 */
	public function test_get_flag_url_existing_assets(): void {
		$es_url = LanguageFlagRenderer::get_flag_url( 'es' );
		$this->assertNotEmpty( $es_url );
		$this->assertStringEndsWith( 'es.svg', $es_url );

		$us_url = LanguageFlagRenderer::get_flag_url( 'us' );
		$this->assertNotEmpty( $us_url );
		$this->assertStringEndsWith( 'us.svg', $us_url );

		$pt_url = LanguageFlagRenderer::get_flag_url( 'pt' );
		$this->assertNotEmpty( $pt_url );
		$this->assertStringEndsWith( 'pt.svg', $pt_url );

		$fr_url = LanguageFlagRenderer::get_flag_url( 'fr' );
		$this->assertNotEmpty( $fr_url );
		$this->assertStringEndsWith( 'fr.svg', $fr_url );

		$gb_url = LanguageFlagRenderer::get_flag_url( 'gb' );
		$this->assertNotEmpty( $gb_url );
		$this->assertStringEndsWith( 'gb.svg', $gb_url );

		$br_url = LanguageFlagRenderer::get_flag_url( 'br' );
		$this->assertNotEmpty( $br_url );
		$this->assertStringEndsWith( 'br.svg', $br_url );
	}

	/**
	 * Tests get_flag_url returns empty string for nonexistent flags.
	 */
	public function test_get_flag_url_missing_asset(): void {
		$this->assertSame( '', LanguageFlagRenderer::get_flag_url( 'nonexistent_flag_xyz' ) );
	}

	/**
	 * Tests render returns an accessible <img> tag for existing flags.
	 */
	public function test_render_existing_flag_html(): void {
		$html = LanguageFlagRenderer::render( 'es', 'Español', 28, 20 );

		$this->assertStringContainsString( '<img', $html );
		$this->assertStringContainsString( 'es.svg', $html );
		$this->assertStringContainsString( 'class="tfml-flag tfml-flag-es"', $html );
		$this->assertStringContainsString( 'width="28"', $html );
		$this->assertStringContainsString( 'height="20"', $html );
		$this->assertStringContainsString( 'Bandera de España', $html );
	}

	/**
	 * Tests render returns graceful fallback pill for missing flags.
	 */
	public function test_render_fallback_badge(): void {
		$html = LanguageFlagRenderer::render( 'xx', 'Unknown' );

		$this->assertStringContainsString( '<span class="tfml-flag-fallback tfml-flag-fallback-xx"', $html );
		$this->assertStringContainsString( 'XX</span>', $html );
	}

	/**
	 * Tests Language entity integration with custom flag_code.
	 */
	public function test_language_entity_flag_code_handling(): void {
		$lang = Language::create( 'en', 'en_US', 'English', 'English', true, 20, 'gb' );

		$this->assertSame( 'gb', $lang->get_flag_code() );

		$array = $lang->to_array();
		$this->assertArrayHasKey( 'flag_code', $array );
		$this->assertSame( 'gb', $array['flag_code'] );

		// Hydration roundtrip.
		$hydrated = Language::from_array( $array );
		$this->assertSame( 'gb', $hydrated->get_flag_code() );

		// Immutable evolution.
		$mutated = $lang->with_flag_code( 'us' );
		$this->assertSame( 'us', $mutated->get_flag_code() );
		$this->assertSame( 'gb', $lang->get_flag_code() );

		// Reset flag code.
		$reset = $mutated->with_flag_code( null );
		$this->assertNull( $reset->get_flag_code() );
		$this->assertArrayNotHasKey( 'flag_code', $reset->to_array() );
	}
}
