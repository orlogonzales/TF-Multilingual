<?php
/**
 * Polylang Source Analyzer Unit Tests.
 *
 * @package TF\Multilingual\Tests\Unit\Migration
 */

declare( strict_types=1 );

namespace TF\Multilingual\Tests\Unit\Migration;

use PHPUnit\Framework\TestCase;
use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Migration\PolylangSourceAnalyzer;
use wpdb;

/**
 * Class PolylangSourceAnalyzerTest
 */
class PolylangSourceAnalyzerTest extends TestCase {

	/**
	 * Test is_available returns false when taxonomies absent.
	 */
	public function test_is_available_false_when_absent(): void {
		$db                = $this->createMock( wpdb::class );
		$db->term_taxonomy = 'wp_term_taxonomy';
		$db->method( 'get_col' )->willReturn( array() );

		$registry = new LanguageRegistry();
		$analyzer = new PolylangSourceAnalyzer( $db, $registry );

		$this->assertFalse( $analyzer->is_available() );

		$report = $analyzer->analyze();
		$this->assertSame( 'polylang', $report->get_source() );
		$this->assertSame( 'blocked', $report->get_status() );
	}

	/**
	 * Test analyze when Polylang taxonomies and terms exist.
	 */
	public function test_analyze_when_polylang_present(): void {
		$db                = $this->createMock( wpdb::class );
		$db->terms         = 'wp_terms';
		$db->term_taxonomy = 'wp_term_taxonomy';

		$db->method( 'get_col' )->willReturn( array( 'language' ) );

		$db->method( 'get_results' )
			->willReturnCallback(
				function ( $query ) {
					if ( str_contains( $query, "WHERE tt.taxonomy IN ('language', 'term_language')" ) ) {
						return array(
							array(
								'code'        => 'es',
								'description' => 'Español',
							),
							array(
								'code'        => 'en',
								'description' => 'English',
							),
						);
					}
					if ( str_contains( $query, "WHERE tt.taxonomy IN ('post_translations', 'term_translations')" ) ) {
						return array(
							array(
								'taxonomy'    => 'post_translations',
								'description' => serialize(
									array(
										'es' => 10,
										'en' => 20,
									)
								),
							),
						);
					}
					return array();
				}
			);

		$registry = new LanguageRegistry();
		$analyzer = new PolylangSourceAnalyzer( $db, $registry );

		$this->assertTrue( $analyzer->is_available() );
		$report = $analyzer->analyze();

		$this->assertSame( 'polylang', $report->get_source() );
		$this->assertSame( 'warning', $report->get_status() ); // Warning because languages es, en are not registered in this mock registry.
		$this->assertCount( 2, $report->get_languages() );
		$this->assertSame( 1, $report->get_total_groups() );
		$this->assertSame( 2, $report->get_total_elements() );
	}
}
