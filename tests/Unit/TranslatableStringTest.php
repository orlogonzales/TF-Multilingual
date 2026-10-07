<?php
/**
 * Translatable String Test.
 *
 * @package TF\Multilingual\Tests\Unit
 */

declare( strict_types=1 );

namespace TF\Multilingual\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TF\Multilingual\Domain\Strings\Exceptions\InvalidStringException;
use TF\Multilingual\Domain\Strings\TranslatableString;

/**
 * Class TranslatableStringTest
 */
class TranslatableStringTest extends TestCase {

	/**
	 * Test entity creation with valid values.
	 */
	public function test_valid_instantiation(): void {
		$string = new TranslatableString(
			10,
			'travel-flow',
			'booking.button.confirm',
			'button',
			'Confirm booking',
			'es',
			2,
			false,
			'2026-10-07 10:00:00',
			'2026-10-07 09:00:00'
		);

		$this->assertSame( 10, $string->get_id() );
		$this->assertSame( 'travel-flow', $string->get_domain() );
		$this->assertSame( 'booking.button.confirm', $string->get_string_key() );
		$this->assertSame( 'button', $string->get_context() );
		$this->assertSame( 'Confirm booking', $string->get_original_value() );
		$this->assertSame( 'es', $string->get_source_language() );
		$this->assertSame( 2, $string->get_string_version() );
		$this->assertFalse( $string->has_conflict() );
		$this->assertSame( '2026-10-07 10:00:00', $string->get_last_seen_at() );
		$this->assertSame( '2026-10-07 09:00:00', $string->get_created_at() );
	}

	/**
	 * Test invalid empty domain throws exception.
	 */
	public function test_empty_domain_throws(): void {
		$this->expectException( InvalidStringException::class );
		new TranslatableString( 1, '', 'key', '', 'val' );
	}

	/**
	 * Test invalid empty key throws exception.
	 */
	public function test_empty_key_throws(): void {
		$this->expectException( InvalidStringException::class );
		new TranslatableString( 1, 'domain', ' ', '', 'val' );
	}

	/**
	 * Test invalid version throws exception.
	 */
	public function test_invalid_version_throws(): void {
		$this->expectException( InvalidStringException::class );
		new TranslatableString( 1, 'domain', 'key', '', 'val', 'es', 0 );
	}
}
