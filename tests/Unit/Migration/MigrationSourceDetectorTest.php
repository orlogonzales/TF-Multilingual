<?php
/**
 * Migration Source Detector Unit Tests.
 *
 * @package TF\Multilingual\Tests\Unit\Migration
 */

declare( strict_types=1 );

namespace TF\Multilingual\Tests\Unit\Migration;

use PHPUnit\Framework\TestCase;
use TF\Multilingual\Migration\MigrationSourceDetector;
use wpdb;

/**
 * Class MigrationSourceDetectorTest
 */
class MigrationSourceDetectorTest extends TestCase {

	/**
	 * Test detect_wpml when tables do not exist.
	 */
	public function test_detect_wpml_not_present(): void {
		$db         = $this->createMock( wpdb::class );
		$db->prefix = 'wp_';

		$db->method( 'get_col' )
			->willReturn( array() );

		$db->method( 'esc_like' )
			->willReturnCallback( fn( $str ) => $str );

		$db->method( 'prepare' )
			->willReturnCallback( fn( $query, ...$args ) => vsprintf( str_replace( '%s', "'%s'", $query ), $args ) );

		$detector = new MigrationSourceDetector( $db );
		$result   = $detector->detect_wpml();

		$this->assertFalse( $result['available'] );
		$this->assertSame( 'WPML (WordPress Multilingual)', $result['name'] );
		$this->assertSame( 0, $result['details']['total_translations'] );
	}

	/**
	 * Test detect_wpml when tables exist and have rows.
	 */
	public function test_detect_wpml_present_and_available(): void {
		$db         = $this->createMock( wpdb::class );
		$db->prefix = 'wp_';

		$db->method( 'get_col' )
			->willReturn( array( 'wp_icl_translations', 'wp_icl_languages' ) );

		$db->method( 'esc_like' )
			->willReturnCallback( fn( $str ) => $str );

		$db->method( 'prepare' )
			->willReturnCallback( fn( $query, ...$args ) => vsprintf( str_replace( '%s', "'%s'", $query ), $args ) );

		$db->method( 'get_var' )
			->willReturnMap(
				array(
					array( 'SELECT COUNT(*) FROM wp_icl_translations', '3403' ),
					array( 'SELECT COUNT(DISTINCT trid) FROM wp_icl_translations', '1541' ),
				)
			);

		$detector = new MigrationSourceDetector( $db );
		$result   = $detector->detect_wpml();

		$this->assertTrue( $result['available'] );
		$this->assertTrue( $result['details']['has_translations'] );
		$this->assertTrue( $result['details']['has_languages'] );
		$this->assertSame( 3403, $result['details']['total_translations'] );
		$this->assertSame( 1541, $result['details']['total_groups'] );
	}

	/**
	 * Test detect_polylang when absent and when present.
	 */
	public function test_detect_polylang(): void {
		$db                = $this->createMock( wpdb::class );
		$db->term_taxonomy = 'wp_term_taxonomy';

		$db->method( 'prepare' )
			->willReturnCallback( fn( $query, ...$args ) => vsprintf( str_replace( '%s', "'%s'", $query ), $args ) );

		// Case 1: absent
		$db->expects( $this->atLeastOnce() )
			->method( 'get_col' )
			->willReturn( array( 'category', 'post_tag' ) );

		$detector = new MigrationSourceDetector( $db );
		$result   = $detector->detect_polylang();

		$this->assertFalse( $result['available'] );
		$this->assertSame( 'Polylang', $result['name'] );
	}

	/**
	 * Test detect_sources returns both wpml and polylang keys.
	 */
	public function test_detect_sources(): void {
		$db                = $this->createMock( wpdb::class );
		$db->prefix        = 'wp_';
		$db->term_taxonomy = 'wp_term_taxonomy';

		$db->method( 'get_col' )->willReturn( array() );
		$db->method( 'esc_like' )->willReturnCallback( fn( $str ) => $str );
		$db->method( 'prepare' )->willReturnCallback( fn( $query, ...$args ) => $query );

		$detector = new MigrationSourceDetector( $db );
		$sources  = $detector->detect_sources();

		$this->assertArrayHasKey( 'wpml', $sources );
		$this->assertArrayHasKey( 'polylang', $sources );
	}
}
