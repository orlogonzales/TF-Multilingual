<?php
/**
 * MediaTranslation Unit Tests.
 *
 * @package TF\Multilingual\Tests\Unit
 */

declare( strict_types=1 );

namespace TF\Multilingual\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use TF\Multilingual\Domain\Media\MediaTranslation;

/**
 * Class MediaTranslationTest
 */
class MediaTranslationTest extends TestCase {

	/**
	 * Tests successful creation via factory method.
	 *
	 * @return void
	 */
	public function test_create_valid_instance(): void {
		$translation = MediaTranslation::create(
			123,
			'es',
			'Texto alternativo',
			'Título de la imagen',
			'Leyenda explicativa',
			'Descripción larga',
			45,
			'2026-10-06 12:00:00'
		);

		$this->assertSame( 45, $translation->get_id() );
		$this->assertSame( 123, $translation->get_attachment_id() );
		$this->assertSame( 'es', $translation->get_language_code() );
		$this->assertSame( 'Texto alternativo', $translation->get_alt_text() );
		$this->assertSame( 'Título de la imagen', $translation->get_title() );
		$this->assertSame( 'Leyenda explicativa', $translation->get_caption() );
		$this->assertSame( 'Descripción larga', $translation->get_description() );
		$this->assertSame( '2026-10-06 12:00:00', $translation->get_updated_at() );
		$this->assertFalse( $translation->is_fallback() );
	}

	/**
	 * Tests invalid attachment ID throws exception.
	 *
	 * @return void
	 */
	public function test_create_invalid_attachment_id(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Attachment ID must be a positive integer.' );

		MediaTranslation::create( 0, 'es' );
	}

	/**
	 * Tests invalid language code throws exception.
	 *
	 * @return void
	 */
	public function test_create_invalid_language_code(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Language code cannot be empty.' );

		MediaTranslation::create( 123, '' );
	}

	/**
	 * Tests fallback factory.
	 *
	 * @return void
	 */
	public function test_create_fallback(): void {
		$fallback = MediaTranslation::create_fallback(
			100,
			'en',
			'Core Alt',
			'Core Title',
			'Core Caption',
			'Core Description'
		);

		$this->assertNull( $fallback->get_id() );
		$this->assertSame( 100, $fallback->get_attachment_id() );
		$this->assertSame( 'en', $fallback->get_language_code() );
		$this->assertSame( 'Core Alt', $fallback->get_alt_text() );
		$this->assertSame( 'Core Title', $fallback->get_title() );
		$this->assertSame( 'Core Caption', $fallback->get_caption() );
		$this->assertSame( 'Core Description', $fallback->get_description() );
		$this->assertNull( $fallback->get_updated_at() );
		$this->assertTrue( $fallback->is_fallback() );
	}

	/**
	 * Tests creation from database row array.
	 *
	 * @return void
	 */
	public function test_from_row_array(): void {
		$row = array(
			'id'            => '5',
			'attachment_id' => '10',
			'language_code' => 'FR',
			'alt_text'      => 'Alt FR',
			'title'         => 'Titre FR',
			'caption'       => 'Légende',
			'description'   => 'Desc FR',
			'updated_at'    => '2026-10-06 14:00:00',
		);

		$entity = MediaTranslation::from_row( $row );

		$this->assertSame( 5, $entity->get_id() );
		$this->assertSame( 10, $entity->get_attachment_id() );
		$this->assertSame( 'fr', $entity->get_language_code() );
		$this->assertSame( 'Alt FR', $entity->get_alt_text() );
		$this->assertSame( 'Titre FR', $entity->get_title() );
		$this->assertSame( 'Légende', $entity->get_caption() );
		$this->assertSame( 'Desc FR', $entity->get_description() );
		$this->assertSame( '2026-10-06 14:00:00', $entity->get_updated_at() );
	}

	/**
	 * Tests to_array output.
	 *
	 * @return void
	 */
	public function test_to_array(): void {
		$entity = MediaTranslation::create( 55, 'es', 'A', 'T', 'C', 'D', 12, '2026-10-06' );
		$array  = $entity->to_array();

		$this->assertSame(
			array(
				'id'            => 12,
				'attachment_id' => 55,
				'language_code' => 'es',
				'alt_text'      => 'A',
				'title'         => 'T',
				'caption'       => 'C',
				'description'   => 'D',
				'updated_at'    => '2026-10-06',
			),
			$array
		);
	}
}
