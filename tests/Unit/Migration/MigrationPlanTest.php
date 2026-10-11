<?php
/**
 * Migration Plan Unit Tests.
 *
 * @package TF\Multilingual\Tests\Unit\Migration
 */

declare( strict_types=1 );

namespace TF\Multilingual\Tests\Unit\Migration;

use PHPUnit\Framework\TestCase;
use TF\Multilingual\Migration\MigrationPlan;

/**
 * Class MigrationPlanTest
 */
class MigrationPlanTest extends TestCase {

	/**
	 * Test getters and filter by classification.
	 */
	public function test_getters_and_classification_filter(): void {
		$planned_groups = array(
			array(
				'trid'                 => 1,
				'element_type'         => 'post',
				'subtype'              => 'page',
				'canonical_id'         => 10,
				'canonical_lang'       => 'es',
				'classification'       => MigrationPlan::CLASSIFICATION_IMPORTABLE,
				'reasons'              => array( 'OK' ),
				'translations'         => array(
					'es' => 10,
					'en' => 20,
				),
				'pending_translations' => array(),
			),
			array(
				'trid'                 => 2,
				'element_type'         => 'post',
				'subtype'              => 'page',
				'canonical_id'         => 30,
				'canonical_lang'       => 'es',
				'classification'       => MigrationPlan::CLASSIFICATION_BLOCKED_BY_LANGUAGE,
				'reasons'              => array( 'Missing fr' ),
				'translations'         => array( 'es' => 30 ),
				'pending_translations' => array( 'fr' => 40 ),
			),
			array(
				'trid'                 => 3,
				'element_type'         => 'other',
				'subtype'              => 'comment',
				'canonical_id'         => 50,
				'canonical_lang'       => 'es',
				'classification'       => MigrationPlan::CLASSIFICATION_SKIPPED,
				'reasons'              => array( 'Comment not supported' ),
				'translations'         => array( 'es' => 50 ),
				'pending_translations' => array(),
			),
		);

		$plan = new MigrationPlan(
			array(
				'source'            => 'wpml',
				'status'            => MigrationPlan::STATUS_NEEDS_CONFIGURATION,
				'missing_languages' => array( 'fr' ),
				'summary'           => array(
					'total_groups'            => 3,
					'importable_groups'       => 1,
					'blocked_language_groups' => 1,
					'skipped_groups'          => 1,
				),
				'media_plan'        => array(
					'total_attachment_rows' => 3033,
					'strategy'              => 'MEDIA_MODEL_B_DEDUPLICATION',
				),
				'planned_groups'    => $planned_groups,
			)
		);

		$this->assertSame( 'wpml', $plan->get_source() );
		$this->assertSame( MigrationPlan::STATUS_NEEDS_CONFIGURATION, $plan->get_status() );
		$this->assertTrue( $plan->has_missing_languages() );
		$this->assertSame( array( 'fr' ), $plan->get_missing_languages() );
		$this->assertSame( 3, $plan->get_summary()['total_groups'] );
		$this->assertSame( 3033, $plan->get_media_plan()['total_attachment_rows'] );

		// Test classification filtering.
		$importable = $plan->get_groups_by_classification( MigrationPlan::CLASSIFICATION_IMPORTABLE );
		$this->assertCount( 1, $importable );
		$this->assertSame( 1, $importable[0]['trid'] );

		$blocked = $plan->get_groups_by_classification( MigrationPlan::CLASSIFICATION_BLOCKED_BY_LANGUAGE );
		$this->assertCount( 1, $blocked );
		$this->assertSame( 2, $blocked[0]['trid'] );
		$this->assertSame( array( 'fr' => 40 ), $blocked[0]['pending_translations'] );

		$skipped = $plan->get_groups_by_classification( MigrationPlan::CLASSIFICATION_SKIPPED );
		$this->assertCount( 1, $skipped );
		$this->assertSame( 3, $skipped[0]['trid'] );

		$array = $plan->to_array();
		$this->assertSame( 'wpml', $array['source'] );
		$this->assertSame( MigrationPlan::STATUS_NEEDS_CONFIGURATION, $array['status'] );
	}
}
