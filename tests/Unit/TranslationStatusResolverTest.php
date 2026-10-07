<?php
/**
 * TranslationStatusResolver Unit Tests.
 *
 * @package TF\Multilingual\Tests\Unit
 */

declare( strict_types=1 );

namespace TF\Multilingual\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TF\Multilingual\Domain\Language\Language;
use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Domain\Language\SettingsRepository;
use TF\Multilingual\Domain\Translation\TranslationElement;
use TF\Multilingual\Domain\Translation\TranslationGroup;
use TF\Multilingual\Domain\Translation\TranslationGroupRepository;
use TF\Multilingual\Domain\Translation\TranslationStatus;
use TF\Multilingual\Domain\Versioning\TranslationStatusResolver;

/**
 * Class TranslationStatusResolverTest
 */
class TranslationStatusResolverTest extends TestCase {

	/**
	 * Language registry.
	 *
	 * @var LanguageRegistry
	 */
	private LanguageRegistry $language_registry;

	/**
	 * Set up test environment before each test.
	 */
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['wp_test_options'] = array();

		$repo                    = new SettingsRepository();
		$this->language_registry = new LanguageRegistry( $repo );
		$this->language_registry->add_language( Language::create( 'es', 'es_ES', 'Spanish', 'Español', true, 1 ), true );
		$this->language_registry->add_language( Language::create( 'en', 'en_US', 'English', 'English', true, 2 ) );
		$this->language_registry->add_language( Language::create( 'pt', 'pt_PT', 'Portuguese', 'Português', true, 3 ) );
		$this->language_registry->persist();
	}

	/**
	 * Tear down test environment.
	 */
	protected function tearDown(): void {
		parent::tearDown();
		$GLOBALS['wp_test_options'] = array();
	}

	/**
	 * Tests unmanaged element resolves to UNTRANSLATED.
	 */
	public function test_unmanaged_element_resolves_to_untranslated(): void {
		$mock_repo = $this->createMock( TranslationGroupRepository::class );
		$mock_repo->method( 'find_by_element' )->willReturn( null );

		$resolver = new TranslationStatusResolver( $mock_repo, $this->language_registry );
		$status   = $resolver->resolve_status( 'post', 999, 'en' );

		$this->assertSame( TranslationStatus::UNTRANSLATED, $status );
	}

	/**
	 * Tests canonical element is always UPDATED.
	 */
	public function test_canonical_element_is_always_updated(): void {
		$group     = TranslationGroup::create( 'post', 'post', 10 );
		$canonical = TranslationElement::create( 'post', 100, 'es', 10, 1, 3, 'fp_es' );
		$group->add_element( $canonical, true );

		$mock_repo = $this->createMock( TranslationGroupRepository::class );
		$mock_repo->method( 'find_by_element' )->willReturn( $group );

		$resolver = new TranslationStatusResolver( $mock_repo, $this->language_registry );

		$status = $resolver->resolve_element_status( $canonical, $group );
		$this->assertSame( TranslationStatus::UPDATED, $status );

		$direct_status = $resolver->resolve_status( 'post', 100, 'es', $group );
		$this->assertSame( TranslationStatus::UPDATED, $direct_status );
	}

	/**
	 * Tests translation whose source version matches canonical current version is UPDATED.
	 */
	public function test_translation_matching_canonical_version_is_updated(): void {
		$group     = TranslationGroup::create( 'post', 'post', 10 );
		$canonical = TranslationElement::create( 'post', 100, 'es', 10, 1, 2, 'fp_es' );
		$group->add_element( $canonical, true );

		// EN translation was translated at source version 2 (matching canonical version 2).
		$en_element = TranslationElement::create( 'post', 101, 'en', 10, 2, 1, 'fp_en' );
		$group->add_element( $en_element, false );

		$mock_repo = $this->createMock( TranslationGroupRepository::class );
		$resolver  = new TranslationStatusResolver( $mock_repo, $this->language_registry );

		$status = $resolver->resolve_element_status( $en_element, $group );
		$this->assertSame( TranslationStatus::UPDATED, $status );
	}

	/**
	 * Tests translation whose source version is strictly less than canonical version is REVIEW.
	 */
	public function test_translation_behind_canonical_version_is_review(): void {
		$group = TranslationGroup::create( 'post', 'post', 10 );
		// Canonical element has advanced to version 3.
		$canonical = TranslationElement::create( 'post', 100, 'es', 10, 1, 3, 'fp_es' );
		$group->add_element( $canonical, true );

		// EN translation was translated at source version 1 (< 3).
		$en_element = TranslationElement::create( 'post', 101, 'en', 10, 1, 1, 'fp_en' );
		$group->add_element( $en_element, false );

		$mock_repo = $this->createMock( TranslationGroupRepository::class );
		$resolver  = new TranslationStatusResolver( $mock_repo, $this->language_registry );

		$status = $resolver->resolve_element_status( $en_element, $group );
		$this->assertSame( TranslationStatus::REVIEW, $status );

		$direct_status = $resolver->resolve_status( 'post', 100, 'en', $group );
		$this->assertSame( TranslationStatus::REVIEW, $direct_status );
	}

	/**
	 * Tests resolve_all_statuses returns status for all active languages in memory.
	 */
	public function test_resolve_all_statuses_returns_map_for_all_active_languages(): void {
		$group     = TranslationGroup::create( 'post', 'post', 10 );
		$canonical = TranslationElement::create( 'post', 100, 'es', 10, 1, 2, 'fp_es' );
		$group->add_element( $canonical, true );

		$en_element = TranslationElement::create( 'post', 101, 'en', 10, 1, 1, 'fp_en' ); // behind -> REVIEW
		$group->add_element( $en_element, false );

		// Portuguese is missing -> UNTRANSLATED

		$mock_repo = $this->createMock( TranslationGroupRepository::class );
		$resolver  = new TranslationStatusResolver( $mock_repo, $this->language_registry );

		$statuses = $resolver->resolve_all_statuses( $group );

		$this->assertSame(
			array(
				'es' => TranslationStatus::UPDATED,
				'en' => TranslationStatus::REVIEW,
				'pt' => TranslationStatus::UNTRANSLATED,
			),
			$statuses
		);
	}
}
