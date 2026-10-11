<?php
/**
 * Migration Execution Lock Unit Tests.
 *
 * @package TF\Multilingual\Tests\Unit\Migration
 */

declare( strict_types=1 );

namespace TF\Multilingual\Tests\Unit\Migration;

use PHPUnit\Framework\TestCase;
use TF\Multilingual\Migration\MigrationExecutionLock;

/**
 * Class MigrationExecutionLockTest
 */
class MigrationExecutionLockTest extends TestCase {

	/**
	 * Setup before each test.
	 */
	protected function setUp(): void {
		parent::setUp();
		delete_option( 'tfml_migration_lock_wpml' );
	}

	/**
	 * Tear down after each test.
	 */
	protected function tearDown(): void {
		delete_option( 'tfml_migration_lock_wpml' );
		parent::tearDown();
	}

	/**
	 * Test acquire and release lock successfully.
	 */
	public function test_acquire_and_release_lock(): void {
		$lock = new MigrationExecutionLock();

		$this->assertFalse( $lock->is_locked( 'wpml' ) );
		$this->assertNull( $lock->get_lock_info( 'wpml' ) );

		$acquired = $lock->acquire( 'wpml', 'token_1', 300 );
		$this->assertTrue( $acquired );
		$this->assertTrue( $lock->is_locked( 'wpml' ) );

		$info = $lock->get_lock_info( 'wpml' );
		$this->assertIsArray( $info );
		$this->assertSame( 'token_1', $info['execution_id'] );

		// Releasing with matching token.
		$released = $lock->release( 'wpml', 'token_1' );
		$this->assertTrue( $released );
		$this->assertFalse( $lock->is_locked( 'wpml' ) );
	}

	/**
	 * Test acquire fails when held by another process.
	 */
	public function test_acquire_fails_when_locked_by_another(): void {
		$lock = new MigrationExecutionLock();

		$lock->acquire( 'wpml', 'token_owner', 300 );

		// Second process tries to acquire.
		$acquired2 = $lock->acquire( 'wpml', 'token_intruder', 300 );
		$this->assertFalse( $acquired2 );

		// Re-acquiring by same owner succeeds.
		$reacquired = $lock->acquire( 'wpml', 'token_owner', 300 );
		$this->assertTrue( $reacquired );
	}

	/**
	 * Test release fails when attempted by non-owner.
	 */
	public function test_release_fails_for_non_owner(): void {
		$lock = new MigrationExecutionLock();

		$lock->acquire( 'wpml', 'token_owner', 300 );

		$released = $lock->release( 'wpml', 'token_wrong' );
		$this->assertFalse( $released );
		$this->assertTrue( $lock->is_locked( 'wpml' ) );

		// Force release bypasses ownership check.
		$force = $lock->force_release( 'wpml' );
		$this->assertTrue( $force );
		$this->assertFalse( $lock->is_locked( 'wpml' ) );
	}
}
