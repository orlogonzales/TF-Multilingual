<?php
/**
 * Migration Checkpoint Repository Unit Tests.
 *
 * @package TF\Multilingual\Tests\Unit\Migration
 */

declare( strict_types=1 );

namespace TF\Multilingual\Tests\Unit\Migration;

use PHPUnit\Framework\TestCase;
use TF\Multilingual\Migration\MigrationCheckpoint;
use TF\Multilingual\Migration\MigrationCheckpointRepository;

/**
 * Class MigrationCheckpointRepositoryTest
 */
class MigrationCheckpointRepositoryTest extends TestCase {

	/**
	 * Setup before each test.
	 */
	protected function setUp(): void {
		parent::setUp();
		delete_option( 'tfml_migration_checkpoint_wpml' );
	}

	/**
	 * Tear down after each test.
	 */
	protected function tearDown(): void {
		delete_option( 'tfml_migration_checkpoint_wpml' );
		parent::tearDown();
	}

	/**
	 * Test saving, retrieving, and deleting checkpoint.
	 */
	public function test_save_get_and_delete_checkpoint(): void {
		$repo = new MigrationCheckpointRepository();

		$this->assertFalse( $repo->has_checkpoint( 'wpml' ) );
		$this->assertNull( $repo->get( 'wpml' ) );

		$checkpoint = new MigrationCheckpoint(
			array(
				'source'              => 'wpml',
				'total_items'         => 249,
				'processed_items'     => 50,
				'last_processed_trid' => 105,
				'batch_size'          => 50,
				'status'              => MigrationCheckpoint::STATUS_IN_PROGRESS,
			)
		);

		$saved = $repo->save( $checkpoint );
		$this->assertTrue( $saved );
		$this->assertTrue( $repo->has_checkpoint( 'wpml' ) );

		$retrieved = $repo->get( 'wpml' );
		$this->assertInstanceOf( MigrationCheckpoint::class, $retrieved );
		$this->assertSame( 249, $retrieved->get_total_items() );
		$this->assertSame( 50, $retrieved->get_processed_items() );
		$this->assertSame( 105, $retrieved->get_last_processed_trid() );
		$this->assertSame( MigrationCheckpoint::STATUS_IN_PROGRESS, $retrieved->get_status() );

		// Delete checkpoint.
		$deleted = $repo->delete( 'wpml' );
		$this->assertTrue( $deleted );
		$this->assertFalse( $repo->has_checkpoint( 'wpml' ) );
		$this->assertNull( $repo->get( 'wpml' ) );
	}
}
