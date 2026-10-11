<?php
/**
 * Migration Execution Report Unit Tests.
 *
 * @package TF\Multilingual\Tests\Unit\Migration
 */

declare( strict_types=1 );

namespace TF\Multilingual\Tests\Unit\Migration;

use PHPUnit\Framework\TestCase;
use TF\Multilingual\Migration\MigrationExecutionReport;

/**
 * Class MigrationExecutionReportTest
 */
class MigrationExecutionReportTest extends TestCase {

	/**
	 * Test instantiation and getters.
	 */
	public function test_report_getters_and_serialization(): void {
		$data = array(
			'execution_id'         => 'exec_123',
			'source'               => 'wpml',
			'status'               => MigrationExecutionReport::STATUS_COMPLETED,
			'batch_count'          => 3,
			'total_planned_groups' => 120,
			'processed_groups'     => 120,
			'imported_groups'      => 100,
			'skipped_groups'       => 20,
			'failed_groups'        => 0,
			'imported_elements'    => 200,
			'errors'               => array(),
			'group_results'        => array(
				array(
					'trid'     => 2,
					'status'   => 'imported',
					'group_id' => 1,
					'message'  => 'OK',
				),
			),
			'reconciliation'       => array(
				'source_total_groups'  => 120,
				'tfml_imported_groups' => 100,
				'integrity_verified'   => true,
			),
			'started_at'           => '2026-10-11 10:00:00',
			'finished_at'          => '2026-10-11 10:00:05',
		);

		$report = new MigrationExecutionReport( $data );

		$this->assertSame( 'exec_123', $report->get_execution_id() );
		$this->assertSame( 'wpml', $report->get_source() );
		$this->assertSame( MigrationExecutionReport::STATUS_COMPLETED, $report->get_status() );
		$this->assertTrue( $report->is_completed() );
		$this->assertSame( 3, $report->get_batch_count() );
		$this->assertSame( 120, $report->get_total_planned_groups() );
		$this->assertSame( 120, $report->get_processed_groups() );
		$this->assertSame( 100, $report->get_imported_groups() );
		$this->assertSame( 20, $report->get_skipped_groups() );
		$this->assertSame( 0, $report->get_failed_groups() );
		$this->assertSame( 200, $report->get_imported_elements() );
		$this->assertCount( 1, $report->get_group_results() );
		$this->assertTrue( $report->get_reconciliation()['integrity_verified'] );

		$array = $report->to_array();
		$this->assertIsArray( $array );
		$this->assertSame( 'exec_123', $array['execution_id'] );
		$this->assertSame( 100, $array['imported_groups'] );
	}
}
