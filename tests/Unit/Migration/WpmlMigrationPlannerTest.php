<?php
/**
 * WPML Migration Planner Unit Tests.
 *
 * @package TF\Multilingual\Tests\Unit\Migration
 */

declare( strict_types=1 );

namespace TF\Multilingual\Tests\Unit\Migration;

use PHPUnit\Framework\TestCase;
use TF\Multilingual\Domain\Language\Language;
use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Migration\MigrationPlan;
use TF\Multilingual\Migration\WpmlMigrationPlanner;
use wpdb;

/**
 * Class WpmlMigrationPlannerTest
 */
class WpmlMigrationPlannerTest extends TestCase {

	/**
	 * Setup language registry with active es and en.
	 *
	 * @return LanguageRegistry
	 */
	private function create_test_registry(): LanguageRegistry {
		$registry = new LanguageRegistry();
		$registry->add_language( new Language( 'es', 'es_ES', 'Español', 'Español', true ), true );
		$registry->add_language( new Language( 'en', 'en_US', 'English', 'English', true ) );

		return $registry;
	}

	/**
	 * Test generate_plan when table does not exist.
	 */
	public function test_generate_plan_table_absent(): void {
		$db         = $this->createMock( wpdb::class );
		$db->prefix = 'wp_';
		$db->method( 'prepare' )->willReturnCallback( fn( $q, $arg ) => $arg );
		$db->method( 'get_var' )->willReturn( null );

		$planner = new WpmlMigrationPlanner( $db, $this->create_test_registry() );
		$plan    = $planner->generate_plan();

		$this->assertSame( MigrationPlan::STATUS_BLOCKED, $plan->get_status() );
		$this->assertEmpty( $plan->get_planned_groups() );
	}

	/**
	 * Test comprehensive planning and classification of groups.
	 */
	public function test_generate_plan_classifications(): void {
		$db         = $this->createMock( wpdb::class );
		$db->prefix = 'wp_';
		$db->posts  = 'wp_posts';
		$db->terms  = 'wp_terms';

		$db->method( 'prepare' )
			->willReturnCallback(
				function ( $query, ...$args ) {
					foreach ( $args as $arg ) {
						$query = preg_replace( '/%[dsFf]/', (string) $arg, $query, 1 );
					}
					return $query;
				}
			);

		// 1. Table existence & counts.
		$db->method( 'get_var' )
			->willReturnCallback(
				function ( $query ) {
					if ( str_contains( $query, 'wp_tfml_group_elements' ) ) {
						return 'wp_tfml_group_elements';
					}
					if ( str_contains( $query, 'SHOW TABLES LIKE' ) ) {
						return 'wp_icl_translations';
					}
					if ( str_contains( $query, 'post_attachment' ) ) {
						return '3033';
					}
					// For check_element_exists_in_core:
					// Let ID 999 be an orphan (not found). All other IDs found.
					if ( str_contains( $query, 'WHERE ID = 999' ) ) {
						return null;
					}
					if ( preg_match( '/WHERE ID = (\d+)/', $query, $m ) ) {
						return (string) $m[1];
					}
					return 'wp_icl_translations';
				}
			);

		// 2. Languages query: es, en, and fr (fr is missing in registry).
		$db->method( 'get_col' )
			->willReturnCallback(
				function ( $query ) {
					if ( str_contains( $query, 'SELECT DISTINCT language_code' ) ) {
						return array( 'es', 'en', 'fr' );
					}
					if ( str_contains( $query, 'SELECT DISTINCT trid' ) ) {
						return array( 10, 20, 30, 40, 50 );
					}
					if ( str_contains( $query, 'SELECT element_id FROM `wp_tfml_group_elements`' ) ) {
						// Let element 500 be pre-existing in TFML (conflict).
						return array( 500 );
					}
					return array();
				}
			);

		// 3. Rows for the 5 TRIDs:
		// TRID 10: Importable (es + en)
		// TRID 20: Blocked by language (es + en + fr)
		// TRID 30: Skipped (comment)
		// TRID 40: Orphan (ID 999 not in posts)
		// TRID 50: Conflict (ID 500 already in TFML)
		$db->method( 'get_results' )
			->willReturn(
				array(
					// TRID 10: Importable
					array(
						'translation_id'       => 1,
						'trid'                 => 10,
						'element_type'         => 'post_page',
						'element_id'           => 101,
						'language_code'        => 'es',
						'source_language_code' => null,
					),
					array(
						'translation_id'       => 2,
						'trid'                 => 10,
						'element_type'         => 'post_page',
						'element_id'           => 102,
						'language_code'        => 'en',
						'source_language_code' => 'es',
					),
					// TRID 20: Blocked by language fr
					array(
						'translation_id'       => 3,
						'trid'                 => 20,
						'element_type'         => 'post_page',
						'element_id'           => 201,
						'language_code'        => 'es',
						'source_language_code' => null,
					),
					array(
						'translation_id'       => 4,
						'trid'                 => 20,
						'element_type'         => 'post_page',
						'element_id'           => 202,
						'language_code'        => 'fr',
						'source_language_code' => 'es',
					),
					// TRID 30: Skipped (comment)
					array(
						'translation_id'       => 5,
						'trid'                 => 30,
						'element_type'         => 'comment',
						'element_id'           => 301,
						'language_code'        => 'es',
						'source_language_code' => null,
					),
					// TRID 40: Orphan (element 999 does not exist)
					array(
						'translation_id'       => 6,
						'trid'                 => 40,
						'element_type'         => 'post_page',
						'element_id'           => 999,
						'language_code'        => 'es',
						'source_language_code' => null,
					),
					// TRID 50: Conflict (element 500 already in TFML)
					array(
						'translation_id'       => 7,
						'trid'                 => 50,
						'element_type'         => 'post_page',
						'element_id'           => 500,
						'language_code'        => 'es',
						'source_language_code' => null,
					),
				)
			);

		$planner = new WpmlMigrationPlanner( $db, $this->create_test_registry() );
		$plan    = $planner->generate_plan();

		// Overall status must be NEEDS_CONFIGURATION because fr is missing.
		$this->assertSame( MigrationPlan::STATUS_NEEDS_CONFIGURATION, $plan->get_status() );
		$this->assertSame( array( 'fr' ), $plan->get_missing_languages() );

		// Media plan verification.
		$this->assertSame( 3033, $plan->get_media_plan()['total_attachment_rows'] );
		$this->assertSame( 'MEDIA_MODEL_B_DEDUPLICATION', $plan->get_media_plan()['strategy'] );

		// Summary metrics.
		$summary = $plan->get_summary();
		$this->assertSame( 5, $summary['total_groups'] );
		$this->assertSame( 1, $summary['importable_groups'] );
		$this->assertSame( 1, $summary['blocked_language_groups'] );
		$this->assertSame( 1, $summary['skipped_groups'] );
		$this->assertSame( 1, $summary['orphan_groups'] );
		$this->assertSame( 1, $summary['conflict_groups'] );
		$this->assertSame( 1, $summary['pending_elements'] ); // element 202 in fr

		// TRID 10: Importable
		$g10 = $plan->get_groups_by_classification( MigrationPlan::CLASSIFICATION_IMPORTABLE )[0];
		$this->assertSame( 10, $g10['trid'] );
		$this->assertSame( 101, $g10['canonical_id'] );
		$this->assertSame(
			array(
				'es' => 101,
				'en' => 102,
			),
			$g10['translations']
		);
		$this->assertEmpty( $g10['pending_translations'] );

		// TRID 20: Blocked by language (French preserved without silent drop!)
		$g20 = $plan->get_groups_by_classification( MigrationPlan::CLASSIFICATION_BLOCKED_BY_LANGUAGE )[0];
		$this->assertSame( 20, $g20['trid'] );
		$this->assertSame( array( 'es' => 201 ), $g20['translations'] );
		$this->assertSame( array( 'fr' => 202 ), $g20['pending_translations'] );
		$this->assertStringContainsString( 'fr', $g20['reasons'][0] );

		// TRID 30: Skipped
		$g30 = $plan->get_groups_by_classification( MigrationPlan::CLASSIFICATION_SKIPPED )[0];
		$this->assertSame( 30, $g30['trid'] );

		// TRID 40: Orphan
		$g40 = $plan->get_groups_by_classification( MigrationPlan::CLASSIFICATION_ORPHAN )[0];
		$this->assertSame( 40, $g40['trid'] );

		// TRID 50: Conflict
		$g50 = $plan->get_groups_by_classification( MigrationPlan::CLASSIFICATION_CONFLICT )[0];
		$this->assertSame( 50, $g50['trid'] );
	}
}
