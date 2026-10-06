<?php
/**
 * MediaTranslationRepository Unit Tests.
 *
 * @package TF\Multilingual\Tests\Unit
 */

declare( strict_types=1 );

namespace TF\Multilingual\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Domain\Media\MediaTranslation;
use TF\Multilingual\Domain\Media\MediaTranslationRepository;
use TF\Multilingual\Tests\Doubles\TestableWpdb;

/**
 * Class MediaTranslationRepositoryTest
 */
class MediaTranslationRepositoryTest extends TestCase {

	/**
	 * Testable database double.
	 *
	 * @var TestableWpdb
	 */
	private TestableWpdb $db;

	/**
	 * Repository under test.
	 *
	 * @var MediaTranslationRepository
	 */
	private MediaTranslationRepository $repository;

	/**
	 * Setup.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->db         = new TestableWpdb();
		$this->repository = new MediaTranslationRepository( $this->db, new LanguageRegistry() );
	}

	/**
	 * Tests find with invalid arguments returns null.
	 *
	 * @return void
	 */
	public function test_find_invalid_arguments(): void {
		$this->assertNull( $this->repository->find( 0, 'es' ) );
		$this->assertNull( $this->repository->find( -1, 'es' ) );
		$this->assertNull( $this->repository->find( 10, '' ) );
	}

	/**
	 * Tests find returns null when row does not exist.
	 *
	 * @return void
	 */
	public function test_find_not_found(): void {
		$this->assertNull( $this->repository->find( 100, 'es' ) );
	}

	/**
	 * Tests save and find.
	 *
	 * @return void
	 */
	public function test_save_and_find(): void {
		$translation = MediaTranslation::create( 10, 'es', 'Alt ES', 'Titulo ES', 'Caption ES', 'Desc ES' );

		$saved = $this->repository->save( $translation );
		$this->assertNotNull( $saved->get_id() );
		$this->assertSame( 1, $saved->get_id() );

		$found = $this->repository->find( 10, 'es' );
		$this->assertNotNull( $found );
		$this->assertSame( 10, $found->get_attachment_id() );
		$this->assertSame( 'es', $found->get_language_code() );
		$this->assertSame( 'Alt ES', $found->get_alt_text() );
		$this->assertSame( 'Titulo ES', $found->get_title() );
		$this->assertSame( 'Caption ES', $found->get_caption() );
		$this->assertSame( 'Desc ES', $found->get_description() );
	}

	/**
	 * Tests save updates existing record rather than creating duplicate.
	 *
	 * @return void
	 */
	public function test_save_updates_existing_record(): void {
		$original = MediaTranslation::create( 10, 'es', 'Initial Alt', 'Initial Title' );
		$saved    = $this->repository->save( $original );
		$id1      = $saved->get_id();

		$updated = MediaTranslation::create( 10, 'es', 'Updated Alt', 'Updated Title' );
		$saved2  = $this->repository->save( $updated );

		$this->assertSame( $id1, $saved2->get_id() );
		$this->assertCount( 1, $this->db->media_translations );

		$found = $this->repository->find( 10, 'es' );
		$this->assertNotNull( $found );
		$this->assertSame( 'Updated Alt', $found->get_alt_text() );
		$this->assertSame( 'Updated Title', $found->get_title() );
	}

	/**
	 * Tests find_by_attachment.
	 *
	 * @return void
	 */
	public function test_find_by_attachment(): void {
		$this->assertEmpty( $this->repository->find_by_attachment( 0 ) );
		$this->assertEmpty( $this->repository->find_by_attachment( 200 ) );

		$this->repository->save( MediaTranslation::create( 200, 'es', 'Alt ES' ) );
		$this->repository->save( MediaTranslation::create( 200, 'en', 'Alt EN' ) );
		$this->repository->save( MediaTranslation::create( 201, 'es', 'Alt Other' ) );

		$list = $this->repository->find_by_attachment( 200 );
		$this->assertCount( 2, $list );
		$this->assertArrayHasKey( 'es', $list );
		$this->assertArrayHasKey( 'en', $list );
		$this->assertSame( 'Alt ES', $list['es']->get_alt_text() );
		$this->assertSame( 'Alt EN', $list['en']->get_alt_text() );
	}

	/**
	 * Tests batch find_by_attachments (Zero N+1).
	 *
	 * @return void
	 */
	public function test_find_by_attachments_batch(): void {
		$this->assertEmpty( $this->repository->find_by_attachments( array() ) );
		$this->assertEmpty( $this->repository->find_by_attachments( array( 0, -5 ) ) );

		$this->repository->save( MediaTranslation::create( 10, 'es', 'A10 ES' ) );
		$this->repository->save( MediaTranslation::create( 10, 'en', 'A10 EN' ) );
		$this->repository->save( MediaTranslation::create( 20, 'en', 'A20 EN' ) );

		$batch = $this->repository->find_by_attachments( array( 10, 20, 30 ) );
		$this->assertCount( 3, $batch );
		$this->assertArrayHasKey( 10, $batch );
		$this->assertArrayHasKey( 20, $batch );
		$this->assertArrayHasKey( 30, $batch );

		$this->assertCount( 2, $batch[10] );
		$this->assertSame( 'A10 ES', $batch[10]['es']->get_alt_text() );
		$this->assertSame( 'A10 EN', $batch[10]['en']->get_alt_text() );

		$this->assertCount( 1, $batch[20] );
		$this->assertSame( 'A20 EN', $batch[20]['en']->get_alt_text() );

		$this->assertEmpty( $batch[30] );
	}

	/**
	 * Tests exists.
	 *
	 * @return void
	 */
	public function test_exists(): void {
		$this->assertFalse( $this->repository->exists( 0, 'es' ) );
		$this->assertFalse( $this->repository->exists( 50, '' ) );
		$this->assertFalse( $this->repository->exists( 50, 'es' ) );

		$this->repository->save( MediaTranslation::create( 50, 'es', 'Alt' ) );
		$this->assertTrue( $this->repository->exists( 50, 'es' ) );
		$this->assertFalse( $this->repository->exists( 50, 'en' ) );
	}

	/**
	 * Tests delete single language.
	 *
	 * @return void
	 */
	public function test_delete(): void {
		$this->assertFalse( $this->repository->delete( 0, 'es' ) );
		$this->assertFalse( $this->repository->delete( 60, '' ) );

		$this->repository->save( MediaTranslation::create( 60, 'es', 'ES' ) );
		$this->repository->save( MediaTranslation::create( 60, 'en', 'EN' ) );

		$this->assertTrue( $this->repository->delete( 60, 'es' ) );
		$this->assertFalse( $this->repository->exists( 60, 'es' ) );
		$this->assertTrue( $this->repository->exists( 60, 'en' ) );
	}

	/**
	 * Tests delete_all_for_attachment.
	 *
	 * @return void
	 */
	public function test_delete_all_for_attachment(): void {
		$this->assertSame( 0, $this->repository->delete_all_for_attachment( 0 ) );

		$this->repository->save( MediaTranslation::create( 70, 'es', 'ES' ) );
		$this->repository->save( MediaTranslation::create( 70, 'en', 'EN' ) );
		$this->repository->save( MediaTranslation::create( 70, 'fr', 'FR' ) );
		$this->repository->save( MediaTranslation::create( 71, 'es', 'Other ES' ) );

		$deleted = $this->repository->delete_all_for_attachment( 70 );
		$this->assertSame( 3, $deleted );
		$this->assertFalse( $this->repository->exists( 70, 'es' ) );
		$this->assertFalse( $this->repository->exists( 70, 'en' ) );
		$this->assertFalse( $this->repository->exists( 70, 'fr' ) );
		$this->assertTrue( $this->repository->exists( 71, 'es' ) );
	}
}
