<?php
/**
 * ContentTranslationResolver Unit Tests.
 *
 * @package TF\Multilingual\Tests\Unit
 */

declare( strict_types=1 );

namespace TF\Multilingual\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TF\Multilingual\Domain\Language\Language;
use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Domain\Language\SettingsRepository;
use TF\Multilingual\Domain\Language\Exceptions\InvalidLanguageException;
use TF\Multilingual\Domain\Language\Exceptions\LanguageNotFoundException;
use TF\Multilingual\Domain\Translation\ContentTranslationResolver;
use TF\Multilingual\Domain\Translation\TranslationElement;
use TF\Multilingual\Domain\Translation\TranslationGroup;
use TF\Multilingual\Domain\Translation\TranslationGroupRepository;
use TF\Multilingual\Domain\Translation\WordPressElementValidator;
use TF\Multilingual\Domain\Translation\Exceptions\InvalidTranslationElementException;
use TF\Multilingual\Tests\Doubles\TestableWpdb;

/**
 * Class ContentTranslationResolverTest
 */
class ContentTranslationResolverTest extends TestCase {

	/**
	 * In-memory test double for wpdb.
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
	 * Translation group repository.
	 *
	 * @var TranslationGroupRepository
	 */
	private TranslationGroupRepository $repository;

	/**
	 * Element validator mock/spy.
	 *
	 * @var WordPressElementValidator
	 */
	private WordPressElementValidator $validator;

	/**
	 * Resolver under test.
	 *
	 * @var ContentTranslationResolver
	 */
	private ContentTranslationResolver $resolver;

	/**
	 * Set up test environment before each test.
	 */
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['wp_test_options'] = array();

		$this->db = new TestableWpdb();
		$this->db->reset();

		$settings_repo           = new SettingsRepository();
		$this->language_registry = new LanguageRegistry( $settings_repo );

		// Register active languages: es (default), en, pt, fr.
		$es = Language::create( 'es', 'es_ES', 'Spanish', 'Español', true, 10 );
		$en = Language::create( 'en', 'en_US', 'English', 'English', true, 20 );
		$pt = Language::create( 'pt', 'pt_PT', 'Portuguese', 'Português', true, 30 );
		$fr = Language::create( 'fr', 'fr_FR', 'French', 'Français', true, 40 );

		// Register an inactive language: de.
		$de = Language::create( 'de', 'de_DE', 'German', 'Deutsch', false, 50 );

		$this->language_registry->add_language( $es, true );
		$this->language_registry->add_language( $en );
		$this->language_registry->add_language( $pt );
		$this->language_registry->add_language( $fr );
		$this->language_registry->add_language( $de );
		$this->language_registry->persist();

		$this->validator  = new WordPressElementValidator();
		$this->repository = new TranslationGroupRepository( $this->db, $this->language_registry, $this->validator );
		$this->resolver   = new ContentTranslationResolver( $this->repository, $this->language_registry, $this->validator );
	}

	/**
	 * Clean up test environment after each test.
	 */
	protected function tearDown(): void {
		$GLOBALS['wp_test_options'] = array();
		parent::tearDown();
	}

	/**
	 * Tests resolving posts bidirectionally between ES and EN.
	 */
	public function test_resolves_posts_bidirectionally(): void {
		// Create group with ES (101) and EN (205).
		$group = $this->repository->create_group( 'post', 'post', 101, 'es', true );
		$this->repository->add_translation( (int) $group->get_id(), 205, 'en' );

		// ES -> EN.
		$resolved_en = $this->resolver->resolve( 'post', 101, 'en' );
		$this->assertInstanceOf( TranslationElement::class, $resolved_en );
		$this->assertSame( 205, $resolved_en->get_element_id() );
		$this->assertSame( 'en', $resolved_en->get_language_code() );

		// EN -> ES.
		$resolved_es = $this->resolver->resolve( 'post', 205, 'es' );
		$this->assertInstanceOf( TranslationElement::class, $resolved_es );
		$this->assertSame( 101, $resolved_es->get_element_id() );
		$this->assertSame( 'es', $resolved_es->get_language_code() );

		// Test resolve_element_id convenience method.
		$this->assertSame( 205, $this->resolver->resolve_element_id( 'post', 101, 'en' ) );
		$this->assertSame( 101, $this->resolver->resolve_element_id( 'post', 205, 'es' ) );
	}

	/**
	 * Tests resolving across multiple languages (ES -> PT, PT -> EN).
	 */
	public function test_resolves_multilingual_group(): void {
		$group    = $this->repository->create_group( 'post', 'post', 101, 'es', true );
		$group_id = (int) $group->get_id();
		$this->repository->add_translation( $group_id, 205, 'en' );
		$this->repository->add_translation( $group_id, 308, 'pt' );

		$res_pt = $this->resolver->resolve( 'post', 101, 'pt' );
		$this->assertInstanceOf( TranslationElement::class, $res_pt );
		$this->assertSame( 308, $res_pt->get_element_id() );

		$res_en_from_pt = $this->resolver->resolve( 'post', 308, 'en' );
		$this->assertInstanceOf( TranslationElement::class, $res_en_from_pt );
		$this->assertSame( 205, $res_en_from_pt->get_element_id() );
	}

	/**
	 * Tests that requesting the same language returns the same element without modification.
	 */
	public function test_resolves_same_language_to_itself(): void {
		$group = $this->repository->create_group( 'post', 'post', 101, 'es', true );
		$this->repository->add_translation( (int) $group->get_id(), 205, 'en' );

		$resolved_self_es = $this->resolver->resolve( 'post', 101, 'es' );
		$this->assertInstanceOf( TranslationElement::class, $resolved_self_es );
		$this->assertSame( 101, $resolved_self_es->get_element_id() );
		$this->assertSame( 'es', $resolved_self_es->get_language_code() );

		$resolved_self_en = $this->resolver->resolve( 'post', 205, 'en' );
		$this->assertInstanceOf( TranslationElement::class, $resolved_self_en );
		$this->assertSame( 205, $resolved_self_en->get_element_id() );
		$this->assertSame( 'en', $resolved_self_en->get_language_code() );
	}

	/**
	 * Tests that an active language with no translation in the group returns null.
	 */
	public function test_returns_null_when_valid_language_has_no_translation(): void {
		// Group only has ES (101) and EN (205). 'fr' is registered and active, but untranslated.
		$group = $this->repository->create_group( 'post', 'post', 101, 'es', true );
		$this->repository->add_translation( (int) $group->get_id(), 205, 'en' );

		$this->assertNull( $this->resolver->resolve( 'post', 101, 'fr' ) );
		$this->assertNull( $this->resolver->resolve_element_id( 'post', 101, 'fr' ) );
	}

	/**
	 * Tests that canonical or default languages are NOT used as fallback when translation is absent.
	 */
	public function test_canonical_and_default_not_used_as_fallback(): void {
		// Default language is 'es' (101, canonical). Requesting 'fr' must return null, NEVER 101.
		$group = $this->repository->create_group( 'post', 'post', 101, 'es', true );
		$this->repository->add_translation( (int) $group->get_id(), 205, 'en' );

		$result = $this->resolver->resolve( 'post', 205, 'fr' );
		$this->assertNull( $result, 'Must return null for untranslated language, not fallback to canonical/default.' );

		$result_id = $this->resolver->resolve_element_id( 'post', 205, 'fr' );
		$this->assertNull( $result_id );
	}

	/**
	 * Tests that an unregistered language throws LanguageNotFoundException.
	 */
	public function test_throws_exception_for_unregistered_language(): void {
		$group = $this->repository->create_group( 'post', 'post', 101, 'es', true );

		$this->expectException( LanguageNotFoundException::class );
		$this->resolver->resolve( 'post', 101, 'zz' );
	}

	/**
	 * Tests that an inactive language throws InvalidLanguageException.
	 */
	public function test_throws_exception_for_inactive_language(): void {
		// 'de' is registered but active = false.
		$group = $this->repository->create_group( 'post', 'post', 101, 'es', true );

		$this->expectException( InvalidLanguageException::class );
		$this->resolver->resolve( 'post', 101, 'de' );
	}

	/**
	 * Tests that an unassigned object (no group) returns null without errors or auto-creation.
	 */
	public function test_returns_null_for_object_without_group(): void {
		$this->assertNull( $this->resolver->resolve( 'post', 9999, 'en' ) );
		$this->assertNull( $this->resolver->resolve_element_id( 'post', 9999, 'en' ) );
		$this->assertNull( $this->resolver->language_of( 'post', 9999 ) );
		$this->assertSame( array(), $this->resolver->get_translations( 'post', 9999 ) );
	}

	/**
	 * Tests language_of derivation strictly from tfml_group_elements.
	 */
	public function test_language_of_derives_sovereign_language(): void {
		$group    = $this->repository->create_group( 'post', 'post', 101, 'es', true );
		$group_id = (int) $group->get_id();
		$this->repository->add_translation( $group_id, 205, 'en' );
		$this->repository->add_translation( $group_id, 308, 'pt' );

		$this->assertSame( 'es', $this->resolver->language_of( 'post', 101 ) );
		$this->assertSame( 'en', $this->resolver->language_of( 'post', 205 ) );
		$this->assertSame( 'pt', $this->resolver->language_of( 'post', 308 ) );
		$this->assertNull( $this->resolver->language_of( 'post', 9999 ) );
	}

	/**
	 * Tests resolution for CPT and Pages (generic post types).
	 */
	public function test_resolves_pages_and_cpts(): void {
		// Page
		$page_grp = $this->repository->create_group( 'post', 'page', 50, 'es', true );
		$this->repository->add_translation( (int) $page_grp->get_id(), 51, 'en' );

		$this->assertSame( 51, $this->resolver->resolve_element_id( 'post', 50, 'en' ) );
		$this->assertSame( 'page', $page_grp->get_subtype() );

		// Custom Post Type (e.g. 'tour')
		$tour_grp = $this->repository->create_group( 'post', 'tour', 700, 'es', true );
		$this->repository->add_translation( (int) $tour_grp->get_id(), 701, 'en' );

		$this->assertSame( 701, $this->resolver->resolve_element_id( 'post', 700, 'en' ) );
		$this->assertSame( 'tour', $tour_grp->get_subtype() );
	}

	/**
	 * Tests resolution for terms and dynamic taxonomies.
	 */
	public function test_resolves_terms_and_dynamic_taxonomies(): void {
		// Category
		$cat_grp = $this->repository->create_group( 'term', 'category', 10, 'es', true );
		$this->repository->add_translation( (int) $cat_grp->get_id(), 20, 'en' );

		$res_cat = $this->resolver->resolve( 'term', 10, 'en' );
		$this->assertInstanceOf( TranslationElement::class, $res_cat );
		$this->assertSame( 20, $res_cat->get_element_id() );
		$this->assertSame( 'es', $this->resolver->language_of( 'term', 10 ) );
		$this->assertSame( 'en', $this->resolver->language_of( 'term', 20 ) );

		// Custom dynamic taxonomy (e.g. 'tour_tag')
		$tag_grp = $this->repository->create_group( 'term', 'tour_tag', 501, 'es', true );
		$this->repository->add_translation( (int) $tag_grp->get_id(), 502, 'en' );

		$this->assertSame( 502, $this->resolver->resolve_element_id( 'term', 501, 'en' ) );
		$this->assertSame( 501, $this->resolver->resolve_element_id( 'term', 502, 'es' ) );
	}

	/**
	 * Tests get_translations returns all valid member elements.
	 */
	public function test_get_translations_returns_all_members(): void {
		$group    = $this->repository->create_group( 'post', 'post', 101, 'es', true );
		$group_id = (int) $group->get_id();
		$this->repository->add_translation( $group_id, 205, 'en' );
		$this->repository->add_translation( $group_id, 308, 'pt' );

		$translations = $this->resolver->get_translations( 'post', 101 );
		$this->assertCount( 3, $translations );
		$this->assertArrayHasKey( 'es', $translations );
		$this->assertArrayHasKey( 'en', $translations );
		$this->assertArrayHasKey( 'pt', $translations );
		$this->assertSame( 101, $translations['es']->get_element_id() );
		$this->assertSame( 205, $translations['en']->get_element_id() );
		$this->assertSame( 308, $translations['pt']->get_element_id() );
	}

	/**
	 * Tests handling when a target entity was deleted in WordPress Core.
	 */
	public function test_returns_null_when_target_entity_deleted_in_wordpress(): void {
		$mock_validator = $this->createMock( WordPressElementValidator::class );
		// Source element 101 exists, but target element 205 was deleted in WordPress.
		$mock_validator->method( 'exists' )
			->willReturnCallback(
				function ( string $type, string $subtype, int $id ): bool {
					if ( 205 === $id ) {
						return false; // Deleted in Core!
					}
					return true;
				}
			);

		$resolver = new ContentTranslationResolver( $this->repository, $this->language_registry, $mock_validator );

		$group = $this->repository->create_group( 'post', 'post', 101, 'es', true );
		$this->repository->add_translation( (int) $group->get_id(), 205, 'en' );

		// Resolving towards 205 must return null because 205 does not exist in WP Core.
		$this->assertNull( $resolver->resolve( 'post', 101, 'en' ) );
		$this->assertNull( $resolver->resolve_element_id( 'post', 101, 'en' ) );
	}

	/**
	 * Tests handling when a source entity was deleted in WordPress Core.
	 */
	public function test_returns_null_when_source_entity_deleted_in_wordpress(): void {
		$mock_validator = $this->createMock( WordPressElementValidator::class );
		$mock_validator->method( 'exists' )
			->willReturnCallback(
				function ( string $type, string $subtype, int $id ): bool {
					if ( 101 === $id ) {
						return false; // Source deleted in Core!
					}
					return true;
				}
			);

		$resolver = new ContentTranslationResolver( $this->repository, $this->language_registry, $mock_validator );

		$group = $this->repository->create_group( 'post', 'post', 101, 'es', true );
		$this->repository->add_translation( (int) $group->get_id(), 205, 'en' );

		// Resolving from deleted source 101 must return null.
		$this->assertNull( $resolver->resolve( 'post', 101, 'en' ) );
		$this->assertNull( $resolver->language_of( 'post', 101 ) );
	}

	/**
	 * Tests unsupported element types throw InvalidTranslationElementException.
	 */
	public function test_throws_for_unsupported_element_type(): void {
		$this->expectException( InvalidTranslationElementException::class );
		$this->resolver->resolve( 'invalid_type', 101, 'en' );
	}

	/**
	 * Tests in-request caching and cache flush.
	 */
	public function test_in_request_caching_and_flush(): void {
		$group = $this->repository->create_group( 'post', 'post', 101, 'es', true );
		$this->repository->add_translation( (int) $group->get_id(), 205, 'en' );

		// Initial load populates cache for both 101 and 205.
		$res1 = $this->resolver->resolve( 'post', 101, 'en' );
		$this->assertSame( 205, $res1->get_element_id() );

		// Sibling query for 205 hits cache without re-querying repository.
		$res2 = $this->resolver->resolve( 'post', 205, 'es' );
		$this->assertSame( 101, $res2->get_element_id() );

		// Flush cache.
		$this->resolver->flush_cache();
		$res3 = $this->resolver->resolve( 'post', 101, 'en' );
		$this->assertSame( 205, $res3->get_element_id() );
	}
}
