<?php
/**
 * TranslationGroupRepository Unit Tests.
 *
 * @package TF\Multilingual\Tests\Unit
 */

declare( strict_types=1 );

namespace TF\Multilingual\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TF\Multilingual\Domain\Language\Language;
use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Domain\Language\SettingsRepository;
use TF\Multilingual\Domain\Translation\TranslationGroupRepository;
use TF\Multilingual\Domain\Translation\WordPressElementValidator;
use TF\Multilingual\Domain\Translation\Exceptions\InvalidTranslationElementException;
use TF\Multilingual\Domain\Translation\Exceptions\TranslationConflictException;
use TF\Multilingual\Tests\Doubles\TestableWpdb;

/**
 * Class TranslationGroupRepositoryTest
 */
class TranslationGroupRepositoryTest extends TestCase {

	/**
	 * In-memory wpdb test double.
	 *
	 * @var TestableWpdb
	 */
	private TestableWpdb $db;

	/**
	 * Language registry.
	 *
	 * @var LanguageRegistry
	 */
	private LanguageRegistry $language_registry;

	/**
	 * Repository under test.
	 *
	 * @var TranslationGroupRepository
	 */
	private TranslationGroupRepository $repository;

	/**
	 * Setup before each test.
	 */
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['wp_test_options'] = array();

		$this->db = new TestableWpdb();
		$this->db->reset();

		$settings_repo           = new SettingsRepository();
		$this->language_registry = new LanguageRegistry( $settings_repo );

		// Register active languages for testing.
		$es = Language::create( 'es', 'es_ES', 'Spanish', 'Español', true, 10 );
		$en = Language::create( 'en', 'en_US', 'English', 'English', true, 20 );
		$pt = Language::create( 'pt', 'pt_PT', 'Portuguese', 'Português', true, 30 );
		$fr = Language::create( 'fr', 'fr_FR', 'French', 'Français', false, 40 ); // Inactive!

		$this->language_registry->add_language( $es, true );
		$this->language_registry->add_language( $en );
		$this->language_registry->add_language( $pt );
		$this->language_registry->add_language( $fr );
		$this->language_registry->persist();

		$validator        = new WordPressElementValidator();
		$this->repository = new TranslationGroupRepository(
			$this->db,
			$this->language_registry,
			$validator
		);
	}

	/**
	 * Tear down after each test.
	 */
	protected function tearDown(): void {
		parent::tearDown();
		$this->db->reset();
		$GLOBALS['wp_test_options'] = array();
	}

	/**
	 * Tests atomic create_group with initial element.
	 */
	public function test_create_group_atomic(): void {
		$group = $this->repository->create_group( 'post', 'tours', 101, 'es', true );

		$this->assertNotNull( $group->get_id() );
		$this->assertSame( 'post', $group->get_element_type() );
		$this->assertSame( 'tours', $group->get_subtype() );
		$this->assertSame( 101, $group->get_canonical_element_id() );
		$this->assertTrue( $group->has_translation( 'es' ) );
		$this->assertCount( 1, $group->get_elements() );
	}

	/**
	 * Tests create_group without initial element.
	 */
	public function test_create_group_empty(): void {
		$group = $this->repository->create_group( 'term', 'category' );

		$this->assertNotNull( $group->get_id() );
		$this->assertSame( 'term', $group->get_element_type() );
		$this->assertSame( 'category', $group->get_subtype() );
		$this->assertNull( $group->get_canonical_element_id() );
		$this->assertTrue( $group->is_empty() );
	}

	/**
	 * Tests add_translation to existing group.
	 */
	public function test_add_translation(): void {
		$group = $this->repository->create_group( 'post', 'page', 10, 'es' );
		$elem  = $this->repository->add_translation( $group->get_id(), 20, 'en' );

		$this->assertNotNull( $elem->get_id() );
		$this->assertSame( 'en', $elem->get_language_code() );
		$this->assertSame( 20, $elem->get_element_id() );

		// Reload group.
		$reloaded = $this->repository->find( $group->get_id() );
		$this->assertNotNull( $reloaded );
		$this->assertCount( 2, $reloaded->get_elements() );
		$this->assertTrue( $reloaded->has_translation( 'es' ) );
		$this->assertTrue( $reloaded->has_translation( 'en' ) );
	}

	/**
	 * Tests add_translation with inactive language throws exception.
	 */
	public function test_add_translation_with_inactive_language_throws(): void {
		$group = $this->repository->create_group( 'post', 'post', 101, 'es' );

		$this->expectException( InvalidTranslationElementException::class );
		$this->repository->add_translation( $group->get_id(), 102, 'fr' ); // 'fr' is inactive.
	}

	/**
	 * Tests add_translation with non-existent language throws exception.
	 */
	public function test_add_translation_with_unregistered_language_throws(): void {
		$group = $this->repository->create_group( 'post', 'post', 101, 'es' );

		$this->expectException( InvalidTranslationElementException::class );
		$this->repository->add_translation( $group->get_id(), 102, 'de' ); // 'de' not in registry.
	}

	/**
	 * Tests add_translation with duplicate language in group throws TranslationConflictException.
	 */
	public function test_add_translation_duplicate_language_throws(): void {
		$group = $this->repository->create_group( 'post', 'post', 101, 'es' );

		$this->expectException( TranslationConflictException::class );
		$this->repository->add_translation( $group->get_id(), 102, 'es' );
	}

	/**
	 * Tests add_translation with element already assigned to another group throws TranslationConflictException.
	 */
	public function test_add_translation_element_already_in_group_throws(): void {
		$group1 = $this->repository->create_group( 'post', 'post', 101, 'es' );
		$group2 = $this->repository->create_group( 'post', 'post', 102, 'es' );

		$this->expectException( TranslationConflictException::class );
		// Attempting to add 101 (already in group1) into group2.
		$this->repository->add_translation( $group2->get_id(), 101, 'en' );
	}

	/**
	 * Tests find_by_element returns correct group.
	 */
	public function test_find_by_element(): void {
		$group = $this->repository->create_group( 'post', 'post', 101, 'es' );
		$this->repository->add_translation( $group->get_id(), 205, 'en' );

		$found_from_es = $this->repository->find_by_element( 'post', 101 );
		$found_from_en = $this->repository->find_by_element( 'post', 205 );
		$not_found     = $this->repository->find_by_element( 'post', 999 );

		$this->assertNotNull( $found_from_es );
		$this->assertSame( $group->get_id(), $found_from_es->get_id() );
		$this->assertNotNull( $found_from_en );
		$this->assertSame( $group->get_id(), $found_from_en->get_id() );
		$this->assertNull( $not_found );
	}

	/**
	 * Tests get_translation returns null when untranslated (SIN TRADUCIR).
	 */
	public function test_get_translation_returns_null_when_untranslated(): void {
		$group = $this->repository->create_group( 'post', 'post', 101, 'es' );

		$trans_es = $this->repository->get_translation( $group->get_id(), 'es' );
		$trans_pt = $this->repository->get_translation( $group->get_id(), 'pt' );

		$this->assertNotNull( $trans_es );
		$this->assertSame( 101, $trans_es->get_element_id() );
		$this->assertNull( $trans_pt ); // Strictly null, no placeholder or fallback.
	}

	/**
	 * Tests remove_translation on non-canonical element.
	 */
	public function test_remove_non_canonical_translation(): void {
		$group = $this->repository->create_group( 'post', 'post', 101, 'es', true );
		$this->repository->add_translation( $group->get_id(), 205, 'en' );

		$removed = $this->repository->remove_translation( $group->get_id(), 'en' );
		$this->assertTrue( $removed );

		$reloaded = $this->repository->find( $group->get_id() );
		$this->assertNotNull( $reloaded );
		$this->assertCount( 1, $reloaded->get_elements() );
		$this->assertFalse( $reloaded->has_translation( 'en' ) );
		$this->assertSame( 101, $reloaded->get_canonical_element_id() );
	}

	/**
	 * Tests remove_translation on canonical element requires replacement.
	 */
	public function test_remove_canonical_without_replacement_throws(): void {
		$group = $this->repository->create_group( 'post', 'post', 101, 'es', true );
		$this->repository->add_translation( $group->get_id(), 205, 'en' );

		$this->expectException( InvalidTranslationElementException::class );
		$this->repository->remove_translation( $group->get_id(), 'es' );
	}

	/**
	 * Tests remove_translation on canonical element with valid replacement.
	 */
	public function test_remove_canonical_with_valid_replacement(): void {
		$group = $this->repository->create_group( 'post', 'post', 101, 'es', true );
		$this->repository->add_translation( $group->get_id(), 205, 'en' );

		$removed = $this->repository->remove_translation( $group->get_id(), 'es', 205 );
		$this->assertTrue( $removed );

		$reloaded = $this->repository->find( $group->get_id() );
		$this->assertNotNull( $reloaded );
		$this->assertCount( 1, $reloaded->get_elements() );
		$this->assertSame( 205, $reloaded->get_canonical_element_id() );
	}

	/**
	 * Tests removing last element purges empty group automatically (Requirement 22).
	 */
	public function test_removing_last_element_purges_empty_group(): void {
		$group = $this->repository->create_group( 'post', 'post', 101, 'es', true );
		$this->assertNotNull( $this->repository->find( $group->get_id() ) );

		$removed = $this->repository->remove_translation( $group->get_id(), 'es' );
		$this->assertTrue( $removed );

		// Group was automatically purged since no elements remain.
		$this->assertNull( $this->repository->find( $group->get_id() ) );
	}

	/**
	 * Tests set_canonical updates canonical element ID.
	 */
	public function test_set_canonical(): void {
		$group = $this->repository->create_group( 'post', 'post', 101, 'es', true );
		$this->repository->add_translation( $group->get_id(), 205, 'en' );

		$this->assertTrue( $this->repository->set_canonical( $group->get_id(), 205 ) );

		$reloaded = $this->repository->find( $group->get_id() );
		$this->assertSame( 205, $reloaded->get_canonical_element_id() );
	}

	/**
	 * Tests delete removes group and elements.
	 */
	public function test_delete(): void {
		$group = $this->repository->create_group( 'post', 'post', 101, 'es', true );
		$this->repository->add_translation( $group->get_id(), 205, 'en' );

		$deleted = $this->repository->delete( $group->get_id() );
		$this->assertTrue( $deleted );

		$this->assertNull( $this->repository->find( $group->get_id() ) );
		$this->assertNull( $this->repository->find_by_element( 'post', 101 ) );
		$this->assertNull( $this->repository->find_by_element( 'post', 205 ) );
	}

	/**
	 * Tests concurrency collision throws TranslationConflictException.
	 */
	public function test_concurrency_collision_throws_translation_conflict_exception(): void {
		$group = $this->repository->create_group( 'post', 'post', 101, 'es' );

		$this->db->force_next_error = "Duplicate entry '{$group->get_id()}-en' for key 'uq_group_language'";

		$this->expectException( TranslationConflictException::class );
		$this->repository->add_translation( $group->get_id(), 205, 'en' );
	}
}
