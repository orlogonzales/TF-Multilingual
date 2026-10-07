<?php
/**
 * String Helper Functions Test.
 *
 * @package TF\Multilingual\Tests\Unit
 */

declare( strict_types=1 );

namespace TF\Multilingual\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TF\Multilingual\Core\Plugin;
use TF\Multilingual\Domain\Language\Language;
use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Domain\Language\SettingsRepository;
use TF\Multilingual\Domain\Strings\StringRepository;
use TF\Multilingual\Domain\Strings\StringStatus;
use TF\Multilingual\Domain\Strings\StringTranslation;
use TF\Multilingual\Domain\Strings\StringTranslationService;
use TF\Multilingual\Routing\CurrentLanguageResolver;
use TF\Multilingual\Routing\UrlLanguageResolver;

/**
 * Class StringFunctionsTest
 */
class StringFunctionsTest extends TestCase {

	/**
	 * Setup mock plugin and services.
	 */
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['wp_test_options'] = array();
		$registry                   = new LanguageRegistry( new SettingsRepository() );
		$registry->add_language( new Language( 'es', 'es_ES', 'Español', 'Spanish', true, 1 ), true );
		$registry->add_language( new Language( 'en', 'en_US', 'English', 'English', true, 2 ) );

		$url_resolver = new UrlLanguageResolver( $registry );
		$current_lang = new CurrentLanguageResolver( $url_resolver );
		$current_lang->set_current_language( 'en' );

		$repo = $this->createMock( StringRepository::class );
		$repo->method( 'get_preloaded_translation' )
			->willReturnCallback(
				function ( $domain, $key, $lang ) {
					if ( 'theme' === $domain && 'header.cta' === $key && 'en' === $lang ) {
						return new StringTranslation( 1, 10, 'en', 'Book now', 1, StringStatus::DB_UP_TO_DATE );
					}
					if ( 'theme' === $domain && 'items.single' === $key && 'en' === $lang ) {
						return new StringTranslation( 2, 11, 'en', '1 item', 1, StringStatus::DB_UP_TO_DATE );
					}
					if ( 'theme' === $domain && 'items.plural' === $key && 'en' === $lang ) {
						return new StringTranslation( 3, 12, 'en', '%d items', 1, StringStatus::DB_UP_TO_DATE );
					}
					return null;
				}
			);

		$service = new StringTranslationService( $repo, $registry, $current_lang );

		// Inject mock service into Plugin singleton reflection.
		$plugin   = Plugin::get_instance();
		$property = new \ReflectionProperty( Plugin::class, 'string_translation_service' );
		$property->setAccessible( true );
		$property->setValue( $plugin, $service );
	}

	/**
	 * Test tfml__() helper returns translated text.
	 */
	public function test_tfml_helper(): void {
		$this->assertSame( 'Book now', tfml__( 'theme', 'header.cta', 'Reservar ahora' ) );
		$this->assertSame( 'Fallback', tfml__( 'theme', 'unknown.key', 'Fallback' ) );
	}

	/**
	 * Test tfml_e() helper outputs escaped translation.
	 */
	public function test_tfml_e_helper(): void {
		ob_start();
		tfml_e( 'theme', 'header.cta', 'Reservar ahora' );
		$output = ob_get_clean();

		$this->assertSame( 'Book now', $output );
	}

	/**
	 * Test tfml_x() helper with context.
	 */
	public function test_tfml_x_helper(): void {
		$this->assertSame( 'Book now', tfml_x( 'theme', 'header.cta', 'button', 'Reservar ahora' ) );
	}

	/**
	 * Test tfml_esc_html__() helper.
	 */
	public function test_tfml_esc_html_helper(): void {
		$escaped = tfml_esc_html__( 'theme', 'header.cta', 'Reservar ahora' );
		$this->assertSame( 'Book now', $escaped );
	}

	/**
	 * Test tfml_esc_attr__() helper.
	 */
	public function test_tfml_esc_attr_helper(): void {
		$escaped = tfml_esc_attr__( 'theme', 'header.cta', 'Reservar ahora' );
		$this->assertSame( 'Book now', $escaped );
	}

	/**
	 * Test tfml_n() plural selection helper.
	 */
	public function test_tfml_n_helper(): void {
		$single = tfml_n( 'theme', 'items', '1 elemento', '%d elementos', 1 );
		$this->assertSame( '1 item', $single );

		$plural = tfml_n( 'theme', 'items', '1 elemento', '%d elementos', 5 );
		$this->assertSame( '%d items', $plural );
	}
}
