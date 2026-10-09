<?php
/**
 * Migration Manager Unit Tests.
 *
 * @package TF\Multilingual\Tests\Unit\Migration
 */

declare( strict_types=1 );

namespace TF\Multilingual\Tests\Unit\Migration;

use PHPUnit\Framework\TestCase;
use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Migration\MigrationManager;
use wpdb;

/**
 * Class MigrationManagerTest
 */
class MigrationManagerTest extends TestCase {

	/**
	 * Test manager coordinates analyzers and detector.
	 */
	public function test_manager_coordination(): void {
		$db                = $this->createMock( wpdb::class );
		$db->prefix        = 'wp_';
		$db->term_taxonomy = 'wp_term_taxonomy';

		$db->method( 'get_col' )->willReturn( array() );
		$db->method( 'esc_like' )->willReturnCallback( fn( $str ) => $str );
		$db->method( 'prepare' )->willReturnCallback( fn( $q, ...$args ) => $q );

		$registry = new LanguageRegistry();
		$manager  = new MigrationManager( $db, $registry );

		$this->assertNotNull( $manager->get_detector() );
		$this->assertNotNull( $manager->get_wpml_analyzer() );
		$this->assertNotNull( $manager->get_polylang_analyzer() );

		$sources = $manager->get_available_sources();
		$this->assertArrayHasKey( 'wpml', $sources );
		$this->assertArrayHasKey( 'polylang', $sources );

		// Analyze unknown source.
		$report = $manager->analyze_source( 'unknown_plugin' );
		$this->assertSame( 'unknown_plugin', $report->get_source() );
		$this->assertSame( 'blocked', $report->get_status() );
	}
}
