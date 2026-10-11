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
		$this->assertNotEmpty( $array['fingerprint'] );
	}

	/**
	 * Test plan fingerprint is deterministic and detects any plan modification.
	 */
	public function test_fingerprint_deterministic_and_verification(): void {
		$data1 = array(
			'source'         => 'wpml',
			'status'         => MigrationPlan::STATUS_READY,
			'planned_groups' => array(
				array(
					'trid'           => 10,
					'classification' => 'IMPORTABLE',
				),
			),
		);
		$plan1 = new MigrationPlan( $data1 );
		$plan2 = new MigrationPlan( $data1 );

		// Deterministic equality.
		$fp1 = $plan1->get_fingerprint();
		$fp2 = $plan2->get_fingerprint();
		$this->assertSame( $fp1, $fp2 );
		$this->assertTrue( $plan1->verify_fingerprint( $fp1 ) );

		// Any modification changes fingerprint.
		$data_modified = array(
			'source'         => 'wpml',
			'status'         => MigrationPlan::STATUS_READY,
			'planned_groups' => array(
				array(
					'trid'           => 10,
					'classification' => 'CONFLICT',
				),
			),
		);
		$plan_modified = new MigrationPlan( $data_modified );
		$this->assertNotSame( $fp1, $plan_modified->get_fingerprint() );
		$this->assertFalse( $plan_modified->verify_fingerprint( $fp1 ) );
	}

	/**
	 * Test derive_partial_plan creates a verified, ready partial plan and preserves parent lineage.
	 */
	public function test_derive_partial_plan(): void {
		$planned_groups = array(
			array(
				'trid'           => 101,
				'element_type'   => 'post',
				'subtype'        => 'post',
				'classification' => MigrationPlan::CLASSIFICATION_IMPORTABLE,
				'translations'   => array(
					'es' => 10,
					'en' => 11,
				),
			),
			array(
				'trid'           => 102,
				'element_type'   => 'post',
				'subtype'        => 'page',
				'classification' => MigrationPlan::CLASSIFICATION_BLOCKED_BY_LANGUAGE,
				'translations'   => array( 'es' => 20 ),
			),
			array(
				'trid'           => 103,
				'element_type'   => 'term',
				'subtype'        => 'category',
				'classification' => MigrationPlan::CLASSIFICATION_IMPORTABLE,
				'translations'   => array(
					'es' => 30,
					'en' => 31,
				),
			),
		);

		$parent_plan = new MigrationPlan(
			array(
				'source'            => 'wpml',
				'status'            => MigrationPlan::STATUS_NEEDS_CONFIGURATION,
				'missing_languages' => array( 'fr' ),
				'summary'           => array( 'total_groups' => 3 ),
				'planned_groups'    => $planned_groups,
			)
		);

		// Derive partial plan with 2 importable groups (101 and 103).
		$partial = $parent_plan->derive_partial_plan( array( 103, 101 ), 'pilot-test' );

		$this->assertTrue( $partial->is_partial() );
		$this->assertSame( 'pilot-test', $partial->get_label() );
		$this->assertSame( MigrationPlan::STATUS_READY, $partial->get_status() );
		$this->assertEmpty( $partial->get_missing_languages() );
		$this->assertSame( $parent_plan->get_fingerprint(), $partial->get_parent_fingerprint() );
		$this->assertNotSame( $parent_plan->get_fingerprint(), $partial->get_fingerprint() );

		$groups = $partial->get_planned_groups();
		$this->assertCount( 2, $groups );
		// Deterministic sort by TRID ascending:
		$this->assertSame( 101, $groups[0]['trid'] );
		$this->assertSame( 103, $groups[1]['trid'] );
		$this->assertSame( 2, $partial->get_summary()['total_groups'] );
		$this->assertSame( 4, $partial->get_summary()['total_elements'] );

		$array = $partial->to_array();
		$this->assertTrue( $array['is_partial'] );
		$this->assertSame( 'pilot-test', $array['label'] );
		$this->assertSame( $parent_plan->get_fingerprint(), $array['parent_fingerprint'] );
	}

	/**
	 * Test derive_partial_plan rejects empty, unknown or unimportable TRIDs.
	 */
	public function test_derive_partial_plan_rejections(): void {
		$planned_groups = array(
			array(
				'trid'           => 1,
				'classification' => MigrationPlan::CLASSIFICATION_IMPORTABLE,
				'translations'   => array( 'es' => 10 ),
			),
			array(
				'trid'           => 2,
				'classification' => MigrationPlan::CLASSIFICATION_BLOCKED_BY_LANGUAGE,
				'translations'   => array( 'es' => 20 ),
			),
		);

		$plan = new MigrationPlan(
			array(
				'source'         => 'wpml',
				'status'         => MigrationPlan::STATUS_NEEDS_CONFIGURATION,
				'planned_groups' => $planned_groups,
			)
		);

		// Reject empty list.
		$this->expectException( \InvalidArgumentException::class );
		$plan->derive_partial_plan( array() );
	}

	/**
	 * Test derive_partial_plan rejects non-importable group.
	 */
	public function test_derive_partial_plan_rejects_non_importable(): void {
		$planned_groups = array(
			array(
				'trid'           => 1,
				'classification' => MigrationPlan::CLASSIFICATION_IMPORTABLE,
				'translations'   => array( 'es' => 10 ),
			),
			array(
				'trid'           => 2,
				'classification' => MigrationPlan::CLASSIFICATION_BLOCKED_BY_LANGUAGE,
				'translations'   => array( 'es' => 20 ),
			),
		);

		$plan = new MigrationPlan(
			array(
				'source'         => 'wpml',
				'status'         => MigrationPlan::STATUS_NEEDS_CONFIGURATION,
				'planned_groups' => $planned_groups,
			)
		);

		$this->expectException( \InvalidArgumentException::class );
		$plan->derive_partial_plan( array( 1, 2 ) );
	}

	/**
	 * Test derive_partial_plan rejects unknown TRID.
	 */
	public function test_derive_partial_plan_rejects_unknown_trid(): void {
		$plan = new MigrationPlan(
			array(
				'source'         => 'wpml',
				'status'         => MigrationPlan::STATUS_NEEDS_CONFIGURATION,
				'planned_groups' => array(
					array(
						'trid'           => 1,
						'classification' => MigrationPlan::CLASSIFICATION_IMPORTABLE,
						'translations'   => array( 'es' => 10 ),
					),
				),
			)
		);

		$this->expectException( \InvalidArgumentException::class );
		$plan->derive_partial_plan( array( 999 ) );
	}
}
