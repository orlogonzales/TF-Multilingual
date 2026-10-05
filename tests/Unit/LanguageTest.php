<?php
/**
 * Language Unit Tests.
 *
 * @package TF\Multilingual\Tests\Unit
 */

declare( strict_types=1 );

namespace TF\Multilingual\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TF\Multilingual\Domain\Language\Language;
use TF\Multilingual\Domain\Language\Exceptions\InvalidLanguageException;

/**
 * Class LanguageTest
 */
class LanguageTest extends TestCase {

	/**
	 * Tests successful creation of a valid Language instance.
	 */
	public function test_can_create_valid_language(): void {
		$lang = new Language( 'es', 'es_ES', 'Spanish', 'Español', true, 10 );

		$this->assertSame( 'es', $lang->get_code() );
		$this->assertSame( 'es_ES', $lang->get_locale() );
		$this->assertSame( 'Spanish', $lang->get_name() );
		$this->assertSame( 'Español', $lang->get_native_name() );
		$this->assertTrue( $lang->is_active() );
		$this->assertSame( 10, $lang->get_order() );
	}

	/**
	 * Tests named constructor factory.
	 */
	public function test_create_named_factory(): void {
		$lang = Language::create( 'en', 'en_US', 'English', 'English', false, 20 );

		$this->assertSame( 'en', $lang->get_code() );
		$this->assertSame( 'en_US', $lang->get_locale() );
		$this->assertSame( 'English', $lang->get_name() );
		$this->assertSame( 'English', $lang->get_native_name() );
		$this->assertFalse( $lang->is_active() );
		$this->assertSame( 20, $lang->get_order() );
	}

	/**
	 * Tests code normalization (lowercasing, underscore to hyphen).
	 */
	public function test_normalizes_language_code(): void {
		$lang1 = new Language( 'PT_BR', 'pt_BR', 'Portuguese', 'Português' );
		$this->assertSame( 'pt-br', $lang1->get_code() );

		$lang2 = new Language( '  EN  ', 'en_US', 'English', 'English' );
		$this->assertSame( 'en', $lang2->get_code() );

		$lang3 = new Language( 'zh_cn', 'zh_CN', 'Chinese', '中文' );
		$this->assertSame( 'zh-cn', $lang3->get_code() );
	}

	/**
	 * Tests locale normalization.
	 */
	public function test_normalizes_locale(): void {
		$lang = new Language( 'pt-br', 'pt-br', 'Portuguese', 'Português' );
		$this->assertSame( 'pt_BR', $lang->get_locale() );
	}

	/**
	 * Tests validation exceptions for invalid codes.
	 *
	 * @dataProvider invalid_codes_provider
	 */
	public function test_throws_for_invalid_code( string $invalid_code ): void {
		$this->expectException( InvalidLanguageException::class );
		new Language( $invalid_code, 'es_ES', 'Spanish', 'Español' );
	}

	/**
	 * Data provider of invalid codes.
	 *
	 * @return array<string, array<string>>
	 */
	public static function invalid_codes_provider(): array {
		return array(
			'empty'             => array( '' ),
			'only whitespace'   => array( '   ' ),
			'single character'  => array( 'a' ),
			'numeric'           => array( '12' ),
			'too long'          => array( 'extremelylongcode' ),
			'invalid chars'     => array( 'es@es' ),
			'invalid separator' => array( 'es.es' ),
		);
	}

	/**
	 * Tests validation exceptions for invalid locales.
	 *
	 * @dataProvider invalid_locales_provider
	 */
	public function test_throws_for_invalid_locale( string $invalid_locale ): void {
		$this->expectException( InvalidLanguageException::class );
		new Language( 'es', $invalid_locale, 'Spanish', 'Español' );
	}

	/**
	 * Data provider of invalid locales.
	 *
	 * @return array<string, array<string>>
	 */
	public static function invalid_locales_provider(): array {
		return array(
			'empty'           => array( '' ),
			'only whitespace' => array( '   ' ),
			'invalid format'  => array( 'invalid/locale' ),
			'too long'        => array( 'a_very_long_invalid_locale_string_exceeding_max' ),
		);
	}

	/**
	 * Tests validation for empty name.
	 */
	public function test_throws_for_empty_name(): void {
		$this->expectException( InvalidLanguageException::class );
		new Language( 'es', 'es_ES', '', 'Español' );
	}

	/**
	 * Tests validation for empty native name.
	 */
	public function test_throws_for_empty_native_name(): void {
		$this->expectException( InvalidLanguageException::class );
		new Language( 'es', 'es_ES', 'Spanish', '   ' );
	}

	/**
	 * Tests validation for negative order.
	 */
	public function test_throws_for_negative_order(): void {
		$this->expectException( InvalidLanguageException::class );
		new Language( 'es', 'es_ES', 'Spanish', 'Español', true, -1 );
	}

	/**
	 * Tests immutable evolution via with_active.
	 */
	public function test_with_active_is_immutable(): void {
		$original = Language::create( 'es', 'es_ES', 'Spanish', 'Español', true, 10 );
		$inactive = $original->with_active( false );

		$this->assertNotSame( $original, $inactive );
		$this->assertTrue( $original->is_active() );
		$this->assertFalse( $inactive->is_active() );
		$this->assertSame( $original->get_code(), $inactive->get_code() );

		// Same state returns same instance.
		$same = $original->with_active( true );
		$this->assertSame( $original, $same );
	}

	/**
	 * Tests immutable evolution via with_order.
	 */
	public function test_with_order_is_immutable(): void {
		$original  = Language::create( 'es', 'es_ES', 'Spanish', 'Español', true, 10 );
		$reordered = $original->with_order( 50 );

		$this->assertNotSame( $original, $reordered );
		$this->assertSame( 10, $original->get_order() );
		$this->assertSame( 50, $reordered->get_order() );

		// Same order returns same instance.
		$same = $original->with_order( 10 );
		$this->assertSame( $original, $same );
	}

	/**
	 * Tests immutable evolution via with_details.
	 */
	public function test_with_details_is_immutable(): void {
		$original = Language::create( 'es', 'es_ES', 'Spanish', 'Español', true, 10 );
		$updated  = $original->with_details( 'Castilian', 'Castellano' );

		$this->assertNotSame( $original, $updated );
		$this->assertSame( 'Spanish', $original->get_name() );
		$this->assertSame( 'Castilian', $updated->get_name() );
		$this->assertSame( 'Castellano', $updated->get_native_name() );
		$this->assertSame( 'es_ES', $updated->get_locale() );
	}

	/**
	 * Tests serialization to array.
	 */
	public function test_to_array(): void {
		$lang     = Language::create( 'es', 'es_ES', 'Spanish', 'Español', true, 10 );
		$expected = array(
			'code'        => 'es',
			'locale'      => 'es_ES',
			'name'        => 'Spanish',
			'native_name' => 'Español',
			'active'      => true,
			'order'       => 10,
		);

		$this->assertSame( $expected, $lang->to_array() );
	}

	/**
	 * Tests hydration from array.
	 */
	public function test_from_array(): void {
		$data = array(
			'code'        => 'fr',
			'locale'      => 'fr_FR',
			'name'        => 'French',
			'native_name' => 'Français',
			'active'      => false,
			'order'       => 30,
		);

		$lang = Language::from_array( $data );

		$this->assertSame( 'fr', $lang->get_code() );
		$this->assertSame( 'fr_FR', $lang->get_locale() );
		$this->assertSame( 'French', $lang->get_name() );
		$this->assertSame( 'Français', $lang->get_native_name() );
		$this->assertFalse( $lang->is_active() );
		$this->assertSame( 30, $lang->get_order() );
	}

	/**
	 * Tests hydration from array with code as array key fallback.
	 */
	public function test_from_array_with_key_fallback(): void {
		$data = array(
			'locale'      => 'de_DE',
			'name'        => 'German',
			'native_name' => 'Deutsch',
		);

		$lang = Language::from_array( $data, 'de' );

		$this->assertSame( 'de', $lang->get_code() );
		$this->assertSame( 'de_DE', $lang->get_locale() );
		$this->assertTrue( $lang->is_active() ); // Default active true.
		$this->assertSame( 10, $lang->get_order() ); // Default order 10.
	}

	/**
	 * Tests from_array throws when mandatory fields are missing.
	 */
	public function test_from_array_throws_on_missing_code(): void {
		$this->expectException( InvalidLanguageException::class );
		Language::from_array( array( 'locale' => 'es_ES' ) );
	}
}
