<?php
/**
 * String Translation Test.
 *
 * @package TF\Multilingual\Tests\Unit
 */

declare( strict_types=1 );

namespace TF\Multilingual\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TF\Multilingual\Domain\Strings\Exceptions\InvalidStringException;
use TF\Multilingual\Domain\Strings\StringStatus;
use TF\Multilingual\Domain\Strings\StringTranslation;
use TF\Multilingual\Domain\Translation\TranslationStatus;

/**
 * Class StringTranslationTest
 */
class StringTranslationTest extends TestCase {

	/**
	 * Test valid instantiation.
	 */
	public function test_valid_instantiation(): void {
		$trans = new StringTranslation(
			5,
			10,
			'en',
			'Confirm booking',
			2,
			StringStatus::DB_UP_TO_DATE,
			'2026-10-07 10:00:00'
		);

		$this->assertSame( 5, $trans->get_id() );
		$this->assertSame( 10, $trans->get_string_id() );
		$this->assertSame( 'en', $trans->get_language_code() );
		$this->assertSame( 'Confirm booking', $trans->get_translated_value() );
		$this->assertSame( 2, $trans->get_source_version_translated() );
		$this->assertSame( StringStatus::DB_UP_TO_DATE, $trans->get_status() );
		$this->assertSame( TranslationStatus::UPDATED, $trans->get_domain_status() );
		$this->assertTrue( $trans->is_up_to_date() );
		$this->assertFalse( $trans->needs_review() );
		$this->assertSame( '2026-10-07 10:00:00', $trans->get_updated_at() );
	}

	/**
	 * Test review status.
	 */
	public function test_review_status(): void {
		$trans = new StringTranslation(
			6,
			10,
			'pt',
			'Confirmar reserva',
			1,
			StringStatus::DB_NEEDS_REVIEW
		);

		$this->assertTrue( $trans->needs_review() );
		$this->assertFalse( $trans->is_up_to_date() );
		$this->assertSame( TranslationStatus::REVIEW, $trans->get_domain_status() );
	}

	/**
	 * Test invalid string ID throws.
	 */
	public function test_invalid_string_id_throws(): void {
		$this->expectException( InvalidStringException::class );
		new StringTranslation( 1, 0, 'en', 'Val' );
	}

	/**
	 * Test empty language code throws.
	 */
	public function test_empty_language_code_throws(): void {
		$this->expectException( InvalidStringException::class );
		new StringTranslation( 1, 5, ' ', 'Val' );
	}
}
