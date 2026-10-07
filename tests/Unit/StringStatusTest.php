<?php
/**
 * String Status Test.
 *
 * @package TF\Multilingual\Tests\Unit
 */

declare( strict_types=1 );

namespace TF\Multilingual\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TF\Multilingual\Domain\Strings\StringStatus;
use TF\Multilingual\Domain\Translation\TranslationStatus;

/**
 * Class StringStatusTest
 */
class StringStatusTest extends TestCase {

	/**
	 * Test schema to domain status mapping parity.
	 */
	public function test_to_domain_status_mapping(): void {
		$this->assertSame( TranslationStatus::UPDATED, StringStatus::to_domain_status( StringStatus::DB_UP_TO_DATE ) );
		$this->assertSame( TranslationStatus::REVIEW, StringStatus::to_domain_status( StringStatus::DB_NEEDS_REVIEW ) );
		$this->assertSame( TranslationStatus::UNTRANSLATED, StringStatus::to_domain_status( StringStatus::DB_UNTRANSLATED ) );
		$this->assertSame( TranslationStatus::UNTRANSLATED, StringStatus::to_domain_status( 'unknown' ) );
	}

	/**
	 * Test domain to schema status mapping parity.
	 */
	public function test_to_db_status_mapping(): void {
		$this->assertSame( StringStatus::DB_UP_TO_DATE, StringStatus::to_db_status( TranslationStatus::UPDATED ) );
		$this->assertSame( StringStatus::DB_NEEDS_REVIEW, StringStatus::to_db_status( TranslationStatus::REVIEW ) );
		$this->assertSame( StringStatus::DB_UNTRANSLATED, StringStatus::to_db_status( TranslationStatus::UNTRANSLATED ) );
		$this->assertSame( StringStatus::DB_UP_TO_DATE, StringStatus::to_db_status( 'unknown' ) );
	}

	/**
	 * Test valid db status detection.
	 */
	public function test_is_valid_db_status(): void {
		$this->assertTrue( StringStatus::is_valid_db_status( 'up_to_date' ) );
		$this->assertTrue( StringStatus::is_valid_db_status( 'needs_review' ) );
		$this->assertTrue( StringStatus::is_valid_db_status( 'untranslated' ) );
		$this->assertFalse( StringStatus::is_valid_db_status( 'invalid' ) );
	}

	/**
	 * Test status labels.
	 */
	public function test_get_label(): void {
		$label_updated = StringStatus::get_label( StringStatus::DB_UP_TO_DATE );
		$this->assertNotEmpty( $label_updated );

		$label_review = StringStatus::get_label( StringStatus::DB_NEEDS_REVIEW );
		$this->assertNotEmpty( $label_review );
	}
}
