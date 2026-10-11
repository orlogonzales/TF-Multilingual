<?php
/**
 * Migration Checkpoint Unit Tests.
 *
 * @package TF\Multilingual\Tests\Unit\Migration
 */

declare( strict_types=1 );

namespace TF\Multilingual\Tests\Unit\Migration;

use PHPUnit\Framework\TestCase;
use TF\Multilingual\Migration\MigrationCheckpoint;

/**
 * Class MigrationCheckpointTest
 */
class MigrationCheckpointTest extends TestCase {

	/**
	 * Test default instantiation.
	 */
	public function test_default_instantiation(): void {
		$checkpoint = new MigrationCheckpoint();

		$this->assertSame( 'wpml', $checkpoint->get_source() );
		$this->assertSame( 0, $checkpoint->get_total_items() );
		$this->assertSame( 0, $checkpoint->get_processed_items() );
		$this->assertSame( 0, $checkpoint->get_last_processed_trid() );
		$this->assertSame( 50, $checkpoint->get_batch_size() );
		$this->assertSame( MigrationCheckpoint::STATUS_PENDING, $checkpoint->get_status() );
		$this->assertFalse( $checkpoint->is_completed() );
		$this->assertEmpty( $checkpoint->get_errors() );
	}

	/**
	 * Test advance progress and completion.
	 */
	public function test_advance_and_completion(): void {
		$checkpoint = new MigrationCheckpoint(
			array(
				'source'      => 'wpml',
				'total_items' => 100,
				'batch_size'  => 50,
			)
		);

		// Step 1: advance 50 items.
		$step1 = $checkpoint->advance( 50, 150 );
		$this->assertSame( 50, $step1->get_processed_items() );
		$this->assertSame( 150, $step1->get_last_processed_trid() );
		$this->assertSame( MigrationCheckpoint::STATUS_IN_PROGRESS, $step1->get_status() );
		$this->assertFalse( $step1->is_completed() );

		// Step 2: advance another 50 items -> complete.
		$step2 = $step1->advance( 50, 200 );
		$this->assertSame( 100, $step2->get_processed_items() );
		$this->assertSame( 200, $step2->get_last_processed_trid() );
		$this->assertSame( MigrationCheckpoint::STATUS_COMPLETED, $step2->get_status() );
		$this->assertTrue( $step2->is_completed() );
	}

	/**
	 * Test recording errors.
	 */
	public function test_record_error(): void {
		$checkpoint = new MigrationCheckpoint( array( 'total_items' => 10 ) );
		$failed     = $checkpoint->record_error( 42, 'Post with ID 42 was corrupted.' );

		$this->assertSame( MigrationCheckpoint::STATUS_FAILED, $failed->get_status() );
		$this->assertCount( 1, $failed->get_errors() );
		$this->assertSame( 42, $failed->get_errors()[0]['item_id'] );
		$this->assertSame( 'Post with ID 42 was corrupted.', $failed->get_errors()[0]['message'] );
	}

	/**
	 * Test serialization and hydration.
	 */
	public function test_serialization_and_hydration(): void {
		$original = new MigrationCheckpoint(
			array(
				'source'              => 'wpml',
				'total_items'         => 249,
				'processed_items'     => 120,
				'last_processed_trid' => 850,
				'batch_size'          => 25,
				'status'              => MigrationCheckpoint::STATUS_IN_PROGRESS,
			)
		);

		$array    = $original->to_array();
		$hydrated = MigrationCheckpoint::from_array( $array );

		$this->assertSame( $original->get_source(), $hydrated->get_source() );
		$this->assertSame( $original->get_total_items(), $hydrated->get_total_items() );
		$this->assertSame( $original->get_processed_items(), $hydrated->get_processed_items() );
		$this->assertSame( $original->get_last_processed_trid(), $hydrated->get_last_processed_trid() );
		$this->assertSame( $original->get_batch_size(), $hydrated->get_batch_size() );
		$this->assertSame( $original->get_status(), $hydrated->get_status() );
	}
}
