<?php
/**
 * WPML Migration Rollback Service Unit Tests.
 *
 * @package TF\Multilingual\Tests\Unit\Migration
 */

declare( strict_types=1 );

namespace TF\Multilingual\Tests\Unit\Migration;

use PHPUnit\Framework\TestCase;
use TF\Multilingual\Domain\Translation\TranslationElement;
use TF\Multilingual\Domain\Translation\TranslationGroup;
use TF\Multilingual\Domain\Translation\TranslationGroupRepository;
use TF\Multilingual\Migration\Exceptions\MigrationExecutionException;
use TF\Multilingual\Migration\MigrationCheckpoint;
use TF\Multilingual\Migration\MigrationCheckpointRepository;
use TF\Multilingual\Migration\MigrationExecutionLock;
use TF\Multilingual\Migration\MigrationExecutionReport;
use TF\Multilingual\Migration\WpmlMigrationRollbackService;
use wpdb;

/**
 * Class WpmlMigrationRollbackServiceTest
 */
class WpmlMigrationRollbackServiceTest extends TestCase {

	/**
	 * Setup before each test.
	 */
	protected function setUp(): void {
		parent::setUp();
		delete_option( 'tfml_migration_lock_wpml' );
		delete_option( 'tfml_migration_checkpoint_wpml' );
	}

	/**
	 * Tear down after each test.
	 */
	protected function tearDown(): void {
		delete_option( 'tfml_migration_lock_wpml' );
		delete_option( 'tfml_migration_checkpoint_wpml' );
		parent::tearDown();
	}

	/**
	 * Creates a mock database.
	 *
	 * @return wpdb
	 */
	private function create_mock_db(): wpdb {
		$db         = $this->createMock( wpdb::class );
		$db->prefix = 'wp_';
		$db->posts  = 'wp_posts';
		$db->terms  = 'wp_terms';

		$db->method( 'prepare' )->willReturnCallback( fn( $q, ...$args ) => $q );
		$db->method( 'query' )->willReturn( true );

		return $db;
	}

	/**
	 * Test 1: Rollback groups cleanly reverts specified groups in atomic transaction.
	 */
	public function test_rollback_groups_reverts_cleanly_without_dependencies(): void {
		$db         = $this->create_mock_db();
		$group_repo = $this->createMock( TranslationGroupRepository::class );

		$group = TranslationGroup::create( 'post', 'post', 10, 42 );
		$el1   = TranslationElement::create( 'post', 10, 'es', 42 );
		$el2   = TranslationElement::create( 'post', 11, 'en', 42 );
		$group->add_element( $el1, true );
		$group->add_element( $el2, false );

		$group_repo->method( 'find' )->with( 42 )->willReturn( $group );
		$group_repo->expects( $this->once() )->method( 'begin_transaction' );
		$group_repo->expects( $this->once() )->method( 'commit_transaction' );
		$group_repo->expects( $this->once() )->method( 'delete' )->with( 42 )->willReturn( true );

		$service = new WpmlMigrationRollbackService( $db, $group_repo );
		$res     = $service->rollback_groups( array( 42 ), 'exec_test_1' );

		$this->assertTrue( $res['success'] );
		$this->assertSame( 1, $res['reverted_groups_count'] );
		$this->assertSame( 2, $res['reverted_elements_count'] );
		$this->assertSame( 0, $res['blocked_groups_count'] );
		$this->assertEmpty( $res['blocked_groups'] );
	}

	/**
	 * Test 2: Rollback execution removes checkpoint, releases lock, and deletes all imported groups.
	 */
	public function test_rollback_execution_deletes_checkpoint_and_releases_lock(): void {
		$db              = $this->create_mock_db();
		$group_repo      = $this->createMock( TranslationGroupRepository::class );
		$checkpoint_repo = new MigrationCheckpointRepository();
		$lock            = new MigrationExecutionLock();

		// Save test checkpoint and acquire lock.
		$cp = new MigrationCheckpoint(
			array(
				'source'      => 'wpml',
				'total_items' => 5,
			)
		);
		$checkpoint_repo->save( $cp );
		$lock->acquire( 'wpml', 'token_exec_123', 300 );

		$this->assertNotNull( $checkpoint_repo->get( 'wpml' ) );
		$this->assertTrue( $lock->is_locked( 'wpml' ) );

		$group = TranslationGroup::create( 'post', 'post', 10, 50 );
		$group_repo->method( 'find' )->willReturn( $group );
		$group_repo->method( 'delete' )->willReturn( true );

		$service = new WpmlMigrationRollbackService( $db, $group_repo, $checkpoint_repo, $lock );

		$report = new MigrationExecutionReport(
			array(
				'execution_id'  => 'token_exec_123',
				'source'        => 'wpml',
				'status'        => 'completed',
				'group_results' => array(
					array(
						'trid'     => 1,
						'status'   => 'imported',
						'group_id' => 50,
						'message'  => 'ok',
					),
				),
			)
		);

		$res = $service->rollback_execution( $report );

		$this->assertTrue( $res['success'] );
		$this->assertSame( 1, $res['reverted_groups_count'] );

		// Checkpoint deleted and lock released!
		$this->assertNull( $checkpoint_repo->get( 'wpml' ) );
		$this->assertFalse( $lock->is_locked( 'wpml' ) );
	}

	/**
	 * Test 3: Rollback blocks deletion if group has editorial modifications post-migration, unless forced.
	 */
	public function test_rollback_detects_editorial_dependencies_and_blocks_unless_forced(): void {
		$db         = $this->create_mock_db();
		$group_repo = $this->createMock( TranslationGroupRepository::class );

		$group = TranslationGroup::create( 'post', 'post', 10, 77 );
		// Element with current_content_version = 2 > source_version_at_translation = 1 (editorial modification!).
		$edited_el = TranslationElement::create( 'post', 10, 'es', 77, 1, 2 );
		$group->add_element( $edited_el, true );

		$group_repo->method( 'find' )->with( 77 )->willReturn( $group );

		$service = new WpmlMigrationRollbackService( $db, $group_repo );

		// Unforced rollback MUST be blocked.
		$res_blocked = $service->rollback_groups( array( 77 ), 'exec_blocked', false );
		$this->assertFalse( $res_blocked['success'] );
		$this->assertSame( 1, $res_blocked['blocked_groups_count'] );
		$this->assertSame( 0, $res_blocked['reverted_groups_count'] );
		$this->assertStringContainsString( 'ediciones editoriales posteriores', $res_blocked['blocked_groups'][0]['reason'] );

		// Forced rollback bypasses dependency block.
		$group_repo->expects( $this->once() )->method( 'delete' )->with( 77 )->willReturn( true );
		$res_forced = $service->rollback_groups( array( 77 ), 'exec_forced', true );
		$this->assertTrue( $res_forced['success'] );
		$this->assertSame( 1, $res_forced['reverted_groups_count'] );
	}

	/**
	 * Test 4: Rollback failure triggers transaction rollback.
	 */
	public function test_rollback_failure_triggers_transaction_rollback(): void {
		$db         = $this->create_mock_db();
		$group_repo = $this->createMock( TranslationGroupRepository::class );

		$group = TranslationGroup::create( 'post', 'post', 10, 99 );
		$group_repo->method( 'find' )->willReturn( $group );

		$group_repo->expects( $this->once() )->method( 'begin_transaction' );
		$group_repo->expects( $this->once() )->method( 'rollback_transaction' );
		$group_repo->expects( $this->never() )->method( 'commit_transaction' );

		$group_repo->method( 'delete' )->willThrowException( new \RuntimeException( 'Simulated delete deadlock' ) );

		$service = new WpmlMigrationRollbackService( $db, $group_repo );

		$this->expectException( MigrationExecutionException::class );
		$this->expectExceptionMessageMatches( '/Simulated delete deadlock/' );

		$service->rollback_groups( array( 99 ), 'exec_fail' );
	}

	/**
	 * Test 5: Rollback blocks unowned/foreign groups even if force = true.
	 */
	public function test_rollback_blocks_unowned_or_foreign_groups_even_if_force_true(): void {
		$db         = $this->create_mock_db();
		$group_repo = $this->createMock( TranslationGroupRepository::class );

		$service      = new WpmlMigrationRollbackService( $db, $group_repo );
		$execution_id = 'exec_ownership_alpha';

		// Register manifest for execution Alpha stating it only created group 42.
		$service->save_execution_manifest(
			$execution_id,
			array(
				'execution_id'       => $execution_id,
				'imported_group_ids' => array( 42 ),
			)
		);

		// An unauthorized attempt tries to rollback group 999 (foreign or pre-existing) using force = true.
		$group_repo->expects( $this->never() )->method( 'delete' );

		$res = $service->rollback_groups( array( 999 ), $execution_id, true );

		$this->assertFalse( $res['success'] );
		$this->assertSame( 1, $res['blocked_groups_count'] );
		$this->assertSame( 0, $res['reverted_groups_count'] );
		$this->assertSame( 'not_owned', $res['blocked_groups'][0]['code'] );
		$this->assertStringContainsString( 'propiedad no demostrada', $res['blocked_groups'][0]['reason'] );

		// Clean up.
		$service->delete_execution_manifest( $execution_id );
	}

	/**
	 * Test 6: Rollback only reverts owned groups and strictly preserves pre-existing groups.
	 */
	public function test_rollback_preserves_preexisting_groups_during_partial_rollback(): void {
		$db         = $this->create_mock_db();
		$group_repo = $this->createMock( TranslationGroupRepository::class );

		$group_owned = TranslationGroup::create( 'post', 'post', 10, 42 );
		$el1         = TranslationElement::create( 'post', 10, 'es', 42 );
		$group_owned->add_element( $el1, true );

		$group_repo->method( 'find' )->willReturnCallback(
			function ( int $id ) use ( $group_owned ) {
				if ( 42 === $id ) {
					return $group_owned;
				}
				return null;
			}
		);

		// Group 42 MUST be deleted, but group 999 MUST NEVER be deleted.
		$group_repo->expects( $this->once() )->method( 'delete' )->with( 42 )->willReturn( true );

		$service      = new WpmlMigrationRollbackService( $db, $group_repo );
		$execution_id = 'exec_ownership_beta';

		$service->save_execution_manifest(
			$execution_id,
			array(
				'execution_id'       => $execution_id,
				'imported_group_ids' => array( 42 ),
			)
		);

		// Request rollback of both group 42 (owned) and group 999 (pre-existing / unowned).
		$res = $service->rollback_groups( array( 42, 999 ), $execution_id, false );

		$this->assertTrue( $res['success'] );
		$this->assertSame( 1, $res['reverted_groups_count'] );
		$this->assertSame( 1, $res['blocked_groups_count'] );
		$this->assertSame( 999, $res['blocked_groups'][0]['group_id'] );
		$this->assertSame( 'not_owned', $res['blocked_groups'][0]['code'] );

		// Clean up.
		$service->delete_execution_manifest( $execution_id );
	}
}
