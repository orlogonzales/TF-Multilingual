<?php
/**
 * Retained Translation Registry Unit Tests.
 *
 * @package TF\Multilingual\Tests\Unit\Migration
 */

declare( strict_types=1 );

namespace TF\Multilingual\Tests\Unit\Migration;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use TF\Multilingual\Domain\Language\Language;
use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Domain\Translation\TranslationElement;
use TF\Multilingual\Domain\Translation\TranslationGroup;
use TF\Multilingual\Domain\Translation\TranslationGroupRepository;
use TF\Multilingual\Domain\Translation\WordPressElementValidator;
use TF\Multilingual\Migration\RetainedTranslationRegistry;

/**
 * Class RetainedTranslationRegistryTest
 */
class RetainedTranslationRegistryTest extends TestCase {

	/**
	 * Registry instance.
	 *
	 * @var RetainedTranslationRegistry
	 */
	private RetainedTranslationRegistry $registry;

	/**
	 * Set up test environment.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->registry = new RetainedTranslationRegistry();
		$this->registry->purge_all();
	}

	/**
	 * Tear down test environment.
	 */
	protected function tearDown(): void {
		$this->registry->purge_all();
		parent::tearDown();
	}

	/**
	 * Test registering and querying retained translations.
	 */
	public function test_register_and_query_retained_translations(): void {
		$record = $this->registry->register(
			'wpml',
			197,
			10,
			'post',
			'page',
			array( 'fr' => 8839 ),
			'parent_fingerprint_abc'
		);

		$this->assertSame( 'wpml', $record['source'] );
		$this->assertSame( 197, $record['trid'] );
		$this->assertSame( 10, $record['group_id'] );
		$this->assertSame( 'post', $record['element_type'] );
		$this->assertSame( 'page', $record['subtype'] );
		$this->assertNotEmpty( $record['fingerprint'] );
		$this->assertArrayHasKey( 'fr', $record['retained_elements'] );
		$this->assertSame( 8839, $record['retained_elements']['fr']['element_id'] );
		$this->assertSame( RetainedTranslationRegistry::STATUS_RETAINED_INACTIVE_LANGUAGE, $record['retained_elements']['fr']['status'] );

		// Query by TRID:
		$retrieved = $this->registry->get( 'wpml', 197 );
		$this->assertNotNull( $retrieved );
		$this->assertSame( 10, $retrieved['group_id'] );

		// Query by group_id:
		$by_group = $this->registry->get_by_group_id( 10 );
		$this->assertNotNull( $by_group );
		$this->assertSame( 197, $by_group['trid'] );

		// Query by language:
		$pending_fr = $this->registry->get_retained_for_language( 'fr' );
		$this->assertCount( 1, $pending_fr );
		$this->assertSame( 8839, $pending_fr[0]['element_id'] );
		$this->assertSame( 197, $pending_fr[0]['trid'] );

		// Check has_retained_element:
		$this->assertTrue( $this->registry->has_retained_element( 'post', 8839 ) );
		$this->assertFalse( $this->registry->has_retained_element( 'post', 9999 ) );
	}

	/**
	 * Test registration validation errors.
	 */
	public function test_registration_validation_errors(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->registry->register( '', 197, 10, 'post', 'page', array( 'fr' => 8839 ) );
	}

	/**
	 * Test uniqueness validation: conflict if element is already retained elsewhere.
	 */
	public function test_retained_element_uniqueness_conflict(): void {
		$this->registry->register(
			'wpml',
			197,
			10,
			'post',
			'page',
			array( 'fr' => 8839 )
		);

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessageMatches( '/Conflicto de unicidad/' );

		// Try registering the same post ID 8839 in another group:
		$this->registry->register(
			'wpml',
			999,
			20,
			'post',
			'page',
			array( 'fr' => 8839 )
		);
	}

	/**
	 * Test promotion fails when language is still inactive.
	 */
	public function test_promote_fails_if_language_is_inactive(): void {
		$this->registry->register(
			'wpml',
			197,
			10,
			'post',
			'page',
			array( 'fr' => 8839 )
		);

		$group_repo = $this->createMock( TranslationGroupRepository::class );
		$validator  = $this->createMock( WordPressElementValidator::class );

		// LanguageRegistry with 'fr' inactive:
		$registry = $this->createMock( LanguageRegistry::class );
		$registry->method( 'is_active' )->with( 'fr' )->willReturn( false );

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessageMatches( '/continúa inactivo/' );

		$this->registry->promote_retained_translation( 10, 'fr', $group_repo, $registry, $validator );
	}

	/**
	 * Test successful promotion when language is active.
	 */
	public function test_promote_succeeds_when_language_activated(): void {
		$this->registry->register(
			'wpml',
			197,
			10,
			'post',
			'page',
			array( 'fr' => 8839 )
		);

		// Group real instance:
		$group = new TranslationGroup( 'post', 'page', 197, 10 );

		// Group repo mock:
		$group_repo = $this->createMock( TranslationGroupRepository::class );
		$group_repo->expects( $this->once() )
			->method( 'find' )
			->with( 10 )
			->willReturn( $group );

		$promoted_element = TranslationElement::create( 'post', 8839, 'fr' );
		$group_repo->expects( $this->once() )
			->method( 'add_translation' )
			->with( 10, 8839, 'fr', false )
			->willReturn( $promoted_element );

		// Validator mock:
		$validator = $this->createMock( WordPressElementValidator::class );
		$validator->expects( $this->once() )
			->method( 'validate' )
			->with( 'post', 'page', 8839 );

		// Active registry with 'fr':
		$lang_registry = $this->createMock( LanguageRegistry::class );
		$lang_registry->method( 'is_active' )->with( 'fr' )->willReturn( true );

		$result = $this->registry->promote_retained_translation( 10, 'fr', $group_repo, $lang_registry, $validator );
		$this->assertSame( 8839, $result->get_element_id() );
		$this->assertSame( 'fr', $result->get_language_code() );

		// After promotion of all retained elements, the record should be cleaned up:
		$this->assertNull( $this->registry->get_by_group_id( 10 ) );
		$this->assertEmpty( $this->registry->get_retained_for_language( 'fr' ) );
	}

	/**
	 * Test removal of retained translation on rollback.
	 */
	public function test_removal_by_group_id(): void {
		$this->registry->register( 'wpml', 197, 10, 'post', 'page', array( 'fr' => 8839 ) );
		$this->assertNotNull( $this->registry->get_by_group_id( 10 ) );

		$removed = $this->registry->remove_by_group_id( 10 );
		$this->assertTrue( $removed );
		$this->assertNull( $this->registry->get_by_group_id( 10 ) );
		$this->assertFalse( $this->registry->has_retained_element( 'post', 8839 ) );
	}
}
