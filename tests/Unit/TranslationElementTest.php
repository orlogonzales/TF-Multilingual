<?php
/**
 * TranslationElement Unit Tests.
 *
 * @package TF\Multilingual\Tests\Unit
 */

declare( strict_types=1 );

namespace TF\Multilingual\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TF\Multilingual\Domain\Translation\TranslationElement;
use TF\Multilingual\Domain\Translation\Exceptions\InvalidTranslationElementException;

/**
 * Class TranslationElementTest
 */
class TranslationElementTest extends TestCase {

	/**
	 * Tests valid creation via constructor.
	 */
	public function test_can_create_valid_translation_element(): void {
		$element = new TranslationElement(
			'post',
			101,
			'es',
			5,
			1,
			2,
			3,
			'fingerprint_abc',
			'2026-10-05 12:00:00'
		);

		$this->assertSame( 'post', $element->get_element_type() );
		$this->assertSame( 101, $element->get_element_id() );
		$this->assertSame( 'es', $element->get_language_code() );
		$this->assertSame( 5, $element->get_group_id() );
		$this->assertSame( 1, $element->get_id() );
		$this->assertSame( 2, $element->get_source_version_at_translation() );
		$this->assertSame( 3, $element->get_current_content_version() );
		$this->assertSame( 'fingerprint_abc', $element->get_translatable_fingerprint() );
		$this->assertSame( '2026-10-05 12:00:00', $element->get_updated_at() );
	}

	/**
	 * Tests named factory create method.
	 */
	public function test_create_factory(): void {
		$element = TranslationElement::create( 'term', 42, 'EN', 8 );

		$this->assertSame( 'term', $element->get_element_type() );
		$this->assertSame( 42, $element->get_element_id() );
		$this->assertSame( 'en', $element->get_language_code() ); // Normalized lowercase.
		$this->assertSame( 8, $element->get_group_id() );
		$this->assertNull( $element->get_id() );
		$this->assertSame( 1, $element->get_source_version_at_translation() );
		$this->assertSame( 1, $element->get_current_content_version() );
		$this->assertSame( '', $element->get_translatable_fingerprint() );
	}

	/**
	 * Tests unsupported element type throws exception.
	 */
	public function test_throws_for_unsupported_element_type(): void {
		$this->expectException( InvalidTranslationElementException::class );
		new TranslationElement( 'comment', 10, 'es' );
	}

	/**
	 * Tests non-positive element ID throws exception.
	 */
	public function test_throws_for_invalid_element_id(): void {
		$this->expectException( InvalidTranslationElementException::class );
		new TranslationElement( 'post', 0, 'es' );
	}

	/**
	 * Tests empty language code throws exception.
	 */
	public function test_throws_for_empty_language_code(): void {
		$this->expectException( InvalidTranslationElementException::class );
		new TranslationElement( 'post', 10, '  ' );
	}

	/**
	 * Tests hydration from array/row.
	 */
	public function test_from_row_hydration(): void {
		$row = array(
			'id'                            => '15',
			'group_id'                      => '7',
			'element_type'                  => 'post',
			'element_id'                    => '205',
			'language_code'                 => 'pt-br',
			'source_version_at_translation' => '2',
			'current_content_version'       => '3',
			'translatable_fingerprint'      => 'hash123',
			'updated_at'                    => '2026-10-05 15:30:00',
		);

		$element = TranslationElement::from_row( $row );

		$this->assertSame( 15, $element->get_id() );
		$this->assertSame( 7, $element->get_group_id() );
		$this->assertSame( 'post', $element->get_element_type() );
		$this->assertSame( 205, $element->get_element_id() );
		$this->assertSame( 'pt-br', $element->get_language_code() );
		$this->assertSame( 2, $element->get_source_version_at_translation() );
		$this->assertSame( 3, $element->get_current_content_version() );
		$this->assertSame( 'hash123', $element->get_translatable_fingerprint() );
		$this->assertSame( '2026-10-05 15:30:00', $element->get_updated_at() );
	}

	/**
	 * Tests serialization to array.
	 */
	public function test_to_array(): void {
		$element = TranslationElement::create( 'post', 101, 'es', 5 );
		$array   = $element->to_array();

		$this->assertSame( 'post', $array['element_type'] );
		$this->assertSame( 101, $array['element_id'] );
		$this->assertSame( 'es', $array['language_code'] );
		$this->assertSame( 5, $array['group_id'] );
		$this->assertSame( 1, $array['source_version_at_translation'] );
	}

	/**
	 * Tests with_id and with_group_id evolution.
	 */
	public function test_evolution_methods(): void {
		$original = TranslationElement::create( 'post', 101, 'es' );
		$with_id  = $original->with_id( 99 );
		$with_grp = $with_id->with_group_id( 12 );

		$this->assertNull( $original->get_id() );
		$this->assertSame( 99, $with_id->get_id() );
		$this->assertSame( 12, $with_grp->get_group_id() );

		$with_versions = $with_grp->with_versions( 4, 5, 'new_fp', '2026-10-05 16:00:00' );
		$this->assertSame( 4, $with_versions->get_source_version_at_translation() );
		$this->assertSame( 5, $with_versions->get_current_content_version() );
		$this->assertSame( 'new_fp', $with_versions->get_translatable_fingerprint() );
		$this->assertSame( '2026-10-05 16:00:00', $with_versions->get_updated_at() );
	}
}
