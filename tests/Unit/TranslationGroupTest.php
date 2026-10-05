<?php
/**
 * TranslationGroup Unit Tests.
 *
 * @package TF\Multilingual\Tests\Unit
 */

declare( strict_types=1 );

namespace TF\Multilingual\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TF\Multilingual\Domain\Translation\TranslationElement;
use TF\Multilingual\Domain\Translation\TranslationGroup;
use TF\Multilingual\Domain\Translation\Exceptions\InvalidTranslationElementException;
use TF\Multilingual\Domain\Translation\Exceptions\TranslationConflictException;

/**
 * Class TranslationGroupTest
 */
class TranslationGroupTest extends TestCase {

	/**
	 * Tests valid creation of TranslationGroup.
	 */
	public function test_can_create_valid_translation_group(): void {
		$group = TranslationGroup::create( 'post', 'tours' );

		$this->assertSame( 'post', $group->get_element_type() );
		$this->assertSame( 'tours', $group->get_subtype() );
		$this->assertNull( $group->get_id() );
		$this->assertNull( $group->get_canonical_element_id() );
		$this->assertTrue( $group->is_empty() );
		$this->assertSame( array(), $group->get_elements() );
	}

	/**
	 * Tests invalid element type throws exception.
	 */
	public function test_throws_for_invalid_element_type(): void {
		$this->expectException( InvalidTranslationElementException::class );
		TranslationGroup::create( 'user', 'author' );
	}

	/**
	 * Tests empty subtype throws exception.
	 */
	public function test_throws_for_empty_subtype(): void {
		$this->expectException( InvalidTranslationElementException::class );
		TranslationGroup::create( 'post', '  ' );
	}

	/**
	 * Tests adding elements and automatic initial canonical candidate.
	 */
	public function test_add_elements_and_canonical_assignment(): void {
		$group = TranslationGroup::create( 'post', 'post' );
		$es    = TranslationElement::create( 'post', 101, 'es' );
		$en    = TranslationElement::create( 'post', 205, 'en' );

		$group->add_element( $es );
		$this->assertFalse( $group->is_empty() );
		$this->assertSame( 101, $group->get_canonical_element_id() );
		$this->assertSame( $es, $group->get_canonical_element() );

		$group->add_element( $en );
		$this->assertCount( 2, $group->get_elements() );
		$this->assertSame( 101, $group->get_canonical_element_id() ); // Still 101.
		$this->assertTrue( $group->has_translation( 'es' ) );
		$this->assertTrue( $group->has_translation( 'en' ) );
		$this->assertSame( array( 101, 205 ), $group->get_element_ids() );
	}

	/**
	 * Tests adding element with explicit as_canonical flag.
	 */
	public function test_add_element_with_explicit_as_canonical(): void {
		$group = TranslationGroup::create( 'post', 'post' );
		$es    = TranslationElement::create( 'post', 101, 'es' );
		$en    = TranslationElement::create( 'post', 205, 'en' );

		$group->add_element( $es );
		$group->add_element( $en, true );

		$this->assertSame( 205, $group->get_canonical_element_id() );
		$this->assertSame( $en, $group->get_canonical_element() );
	}

	/**
	 * Tests adding element of mismatched element_type throws exception (homogeneity).
	 */
	public function test_throws_for_mismatched_element_type(): void {
		$group = TranslationGroup::create( 'post', 'page' );
		$term  = TranslationElement::create( 'term', 42, 'es' );

		$this->expectException( InvalidTranslationElementException::class );
		$group->add_element( $term );
	}

	/**
	 * Tests adding duplicate language code in group throws TranslationConflictException.
	 */
	public function test_throws_for_duplicate_language_in_group(): void {
		$group = TranslationGroup::create( 'post', 'post' );
		$es1   = TranslationElement::create( 'post', 101, 'es' );
		$es2   = TranslationElement::create( 'post', 102, 'ES' ); // Normalized code match.

		$group->add_element( $es1 );

		$this->expectException( TranslationConflictException::class );
		$group->add_element( $es2 );
	}

	/**
	 * Tests setting canonical element explicitly.
	 */
	public function test_set_canonical_element_id(): void {
		$group = TranslationGroup::create( 'post', 'post' );
		$es    = TranslationElement::create( 'post', 101, 'es' );
		$en    = TranslationElement::create( 'post', 205, 'en' );

		$group->add_element( $es );
		$group->add_element( $en );

		$group->set_canonical_element_id( 205 );
		$this->assertSame( 205, $group->get_canonical_element_id() );
	}

	/**
	 * Tests setting non-member element as canonical throws exception.
	 */
	public function test_set_canonical_for_non_member_throws_exception(): void {
		$group = TranslationGroup::create( 'post', 'post' );
		$es    = TranslationElement::create( 'post', 101, 'es' );
		$group->add_element( $es );

		$this->expectException( InvalidTranslationElementException::class );
		$group->set_canonical_element_id( 999 );
	}

	/**
	 * Tests absence of translation returns strictly null (UNTRANSLATED).
	 */
	public function test_get_translation_returns_null_when_untranslated(): void {
		$group = TranslationGroup::create( 'post', 'post' );
		$es    = TranslationElement::create( 'post', 101, 'es' );
		$group->add_element( $es );

		$this->assertNull( $group->get_translation( 'en' ) );
		$this->assertNull( $group->get_translation( 'pt' ) );
		$this->assertFalse( $group->has_translation( 'fr' ) );
	}

	/**
	 * Tests removing a non-canonical element.
	 */
	public function test_remove_non_canonical_element(): void {
		$group = TranslationGroup::create( 'post', 'post' );
		$es    = TranslationElement::create( 'post', 101, 'es' );
		$en    = TranslationElement::create( 'post', 205, 'en' );

		$group->add_element( $es, true );
		$group->add_element( $en );

		$removed = $group->remove_element( 'en' );
		$this->assertSame( $en, $removed );
		$this->assertFalse( $group->has_translation( 'en' ) );
		$this->assertSame( 101, $group->get_canonical_element_id() );
	}

	/**
	 * Tests removing canonical element without designating new canonical throws exception.
	 */
	public function test_remove_canonical_element_without_replacement_throws_exception(): void {
		$group = TranslationGroup::create( 'post', 'post' );
		$es    = TranslationElement::create( 'post', 101, 'es' );
		$en    = TranslationElement::create( 'post', 205, 'en' );

		$group->add_element( $es, true );
		$group->add_element( $en );

		$this->expectException( InvalidTranslationElementException::class );
		$group->remove_element( 'es' ); // Fails because 205 remains but no replacement specified.
	}

	/**
	 * Tests removing canonical element with valid replacement designates new canonical.
	 */
	public function test_remove_canonical_element_with_replacement(): void {
		$group = TranslationGroup::create( 'post', 'post' );
		$es    = TranslationElement::create( 'post', 101, 'es' );
		$en    = TranslationElement::create( 'post', 205, 'en' );

		$group->add_element( $es, true );
		$group->add_element( $en );

		$removed = $group->remove_element( 'es', 205 );
		$this->assertSame( $es, $removed );
		$this->assertFalse( $group->has_translation( 'es' ) );
		$this->assertSame( 205, $group->get_canonical_element_id() );
	}

	/**
	 * Tests removing only element (group becomes empty) resets canonical to null.
	 */
	public function test_remove_only_element_resets_canonical_to_null(): void {
		$group = TranslationGroup::create( 'post', 'post' );
		$es    = TranslationElement::create( 'post', 101, 'es' );
		$group->add_element( $es );

		$group->remove_element( 'es' );
		$this->assertTrue( $group->is_empty() );
		$this->assertNull( $group->get_canonical_element_id() );
	}

	/**
	 * Tests hydration from row.
	 */
	public function test_from_row_hydration(): void {
		$row = array(
			'id'                   => '10',
			'element_type'         => 'term',
			'subtype'              => 'category',
			'canonical_element_id' => '42',
			'created_at'           => '2026-10-05 10:00:00',
		);

		$group = TranslationGroup::from_row( $row );

		$this->assertSame( 10, $group->get_id() );
		$this->assertSame( 'term', $group->get_element_type() );
		$this->assertSame( 'category', $group->get_subtype() );
		$this->assertSame( 42, $group->get_canonical_element_id() );
		$this->assertSame( '2026-10-05 10:00:00', $group->get_created_at() );
	}
}
