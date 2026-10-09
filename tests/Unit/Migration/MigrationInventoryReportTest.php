<?php
/**
 * Migration Inventory Report Unit Tests.
 *
 * @package TF\Multilingual\Tests\Unit\Migration
 */

declare( strict_types=1 );

namespace TF\Multilingual\Tests\Unit\Migration;

use PHPUnit\Framework\TestCase;
use TF\Multilingual\Migration\MigrationInventoryReport;

/**
 * Class MigrationInventoryReportTest
 */
class MigrationInventoryReportTest extends TestCase {

	/**
	 * Test instantiation and getters.
	 */
	public function test_getters_and_to_array(): void {
		$data = array(
			'source'              => 'wpml',
			'source_name'         => 'WPML (WordPress Multilingual)',
			'status'              => 'ready',
			'languages'           => array(
				array(
					'code'               => 'es',
					'active_in_source'   => true,
					'registered_in_tfml' => true,
					'active_in_tfml'     => true,
				),
				array(
					'code'               => 'en',
					'active_in_source'   => true,
					'registered_in_tfml' => true,
					'active_in_tfml'     => true,
				),
			),
			'missing_languages'   => array(),
			'elements_by_type'    => array(
				'post_page'       => 10,
				'post_attachment' => 50,
			),
			'total_elements'      => 60,
			'total_groups'        => 30,
			'content_groups'      => 5,
			'attachment_elements' => 50,
			'conflicts'           => array(),
			'orphans'             => array(),
			'notes'               => array( 'Todo listo para migrar.' ),
		);

		$report = new MigrationInventoryReport( $data );

		$this->assertSame( 'wpml', $report->get_source() );
		$this->assertSame( 'WPML (WordPress Multilingual)', $report->get_source_name() );
		$this->assertSame( 'ready', $report->get_status() );
		$this->assertCount( 2, $report->get_languages() );
		$this->assertEmpty( $report->get_missing_languages() );
		$this->assertSame(
			array(
				'post_page'       => 10,
				'post_attachment' => 50,
			),
			$report->get_elements_by_type()
		);
		$this->assertSame( 60, $report->get_total_elements() );
		$this->assertSame( 30, $report->get_total_groups() );
		$this->assertSame( 5, $report->get_content_groups() );
		$this->assertSame( 50, $report->get_attachment_elements() );
		$this->assertEmpty( $report->get_conflicts() );
		$this->assertEmpty( $report->get_orphans() );
		$this->assertSame( array( 'Todo listo para migrar.' ), $report->get_notes() );

		$array = $report->to_array();
		$this->assertSame( 'wpml', $array['source'] );
		$this->assertSame( 0, $array['conflicts_count'] );
		$this->assertSame( 0, $array['orphans_count'] );
	}

	/**
	 * Test default fallback values.
	 */
	public function test_default_values(): void {
		$report = new MigrationInventoryReport( array() );

		$this->assertSame( 'unknown', $report->get_source() );
		$this->assertSame( 'Unknown Source', $report->get_source_name() );
		$this->assertSame( 'blocked', $report->get_status() );
		$this->assertEmpty( $report->get_languages() );
		$this->assertSame( 0, $report->get_total_elements() );
		$this->assertSame( 0, $report->get_total_groups() );
	}
}
