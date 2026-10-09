<?php
/**
 * WPML Source Analyzer Unit Tests.
 *
 * @package TF\Multilingual\Tests\Unit\Migration
 */

declare( strict_types=1 );

namespace TF\Multilingual\Tests\Unit\Migration;

use PHPUnit\Framework\TestCase;
use TF\Multilingual\Domain\Language\Language;
use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Migration\WpmlSourceAnalyzer;
use wpdb;

/**
 * Class WpmlSourceAnalyzerTest
 */
class WpmlSourceAnalyzerTest extends TestCase {

	/**
	 * Test parse_wpml_element_type helper.
	 */
	public function test_parse_wpml_element_type(): void {
		$db       = $this->createMock( wpdb::class );
		$registry = new LanguageRegistry();
		$analyzer = new WpmlSourceAnalyzer( $db, $registry );

		$post_page = $analyzer->parse_wpml_element_type( 'post_page' );
		$this->assertSame( 'post', $post_page['element_type'] );
		$this->assertSame( 'page', $post_page['subtype'] );

		$post_tours = $analyzer->parse_wpml_element_type( 'post_tours' );
		$this->assertSame( 'post', $post_tours['element_type'] );
		$this->assertSame( 'tours', $post_tours['subtype'] );

		$tax_cat = $analyzer->parse_wpml_element_type( 'tax_category' );
		$this->assertSame( 'term', $tax_cat['element_type'] );
		$this->assertSame( 'category', $tax_cat['subtype'] );

		$comment = $analyzer->parse_wpml_element_type( 'comment' );
		$this->assertSame( 'other', $comment['element_type'] );
		$this->assertSame( 'comment', $comment['subtype'] );
	}

	/**
	 * Test is_available returns false when table missing.
	 */
	public function test_is_available_returns_false_when_missing(): void {
		$db         = $this->createMock( wpdb::class );
		$db->prefix = 'wp_';
		$db->method( 'prepare' )->willReturnCallback( fn( $q, $arg ) => $arg );
		$db->method( 'get_var' )->willReturn( null );

		$registry = new LanguageRegistry();
		$analyzer = new WpmlSourceAnalyzer( $db, $registry );

		$this->assertFalse( $analyzer->is_available() );
	}

	/**
	 * Test analyze when WPML is present.
	 */
	public function test_analyze_generates_inventory_report(): void {
		$db         = $this->createMock( wpdb::class );
		$db->prefix = 'wp_';
		$db->posts  = 'wp_posts';
		$db->terms  = 'wp_terms';

		$db->method( 'prepare' )->willReturnCallback( fn( $q, ...$args ) => $args[0] ?? '' );

		// Table checks.
		$db->method( 'get_var' )
			->willReturnCallback(
				function ( $query ) {
					if ( str_contains( $query, 'COUNT(*)' ) ) {
						return '100';
					}
					if ( str_contains( $query, 'COUNT(DISTINCT trid)' ) ) {
						return '40';
					}
					return 'wp_icl_translations';
				}
			);

		// Results for element types.
		$db->method( 'get_results' )
			->willReturnCallback(
				function ( $query ) {
					if ( str_contains( $query, 'GROUP BY element_type' ) ) {
						return array(
							array(
								'element_type' => 'post_page',
								'c'            => '20',
							),
							array(
								'element_type' => 'post_attachment',
								'c'            => '80',
							),
						);
					}
					if ( str_contains( $query, 'SELECT code, active FROM' ) ) {
						return array(
							array(
								'code'   => 'es',
								'active' => 1,
							),
							array(
								'code'   => 'en',
								'active' => 1,
							),
						);
					}
					return array();
				}
			);

		$db->method( 'get_col' )
			->willReturnCallback(
				function ( $query ) {
					if ( str_contains( $query, 'SELECT DISTINCT language_code' ) ) {
						return array( 'es', 'en' );
					}
					return array();
				}
			);

		$registry = new LanguageRegistry();
		$registry->add_language(
			new Language( 'es', 'es_ES', 'Español', 'Español', true ),
			true
		);
		$registry->add_language(
			new Language( 'en', 'en_US', 'English', 'English', true )
		);

		$analyzer = new WpmlSourceAnalyzer( $db, $registry );
		$report   = $analyzer->analyze();

		$this->assertSame( 'wpml', $report->get_source() );
		$this->assertSame( 'ready', $report->get_status() );
		$this->assertSame( 100, $report->get_total_elements() );
		$this->assertSame( 40, $report->get_total_groups() );
		$this->assertSame( 80, $report->get_attachment_elements() );
		$this->assertEmpty( $report->get_missing_languages() );
	}

	/**
	 * Test simulate_migration returns structured simulation groups.
	 */
	public function test_simulate_migration(): void {
		$db         = $this->createMock( wpdb::class );
		$db->prefix = 'wp_';

		$db->method( 'prepare' )->willReturnCallback( fn( $q, ...$args ) => $q );

		// Simulate is_available = true.
		$db->method( 'get_var' )
			->willReturnCallback(
				function ( $query ) {
					if ( str_contains( $query, 'COUNT(*)' ) ) {
						return '10';
					}
					return 'wp_icl_translations';
				}
			);

		// Distinct trids.
		$db->method( 'get_col' )->willReturn( array( 10, 11 ) );

		// Rows for trid 10 and 11.
		$db->method( 'get_results' )
			->willReturn(
				array(
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
				)
			);

		$registry = new LanguageRegistry();
		$analyzer = new WpmlSourceAnalyzer( $db, $registry );

		$sim = $analyzer->simulate_migration( 5 );

		$this->assertSame( 1, $sim['simulated_groups'] );
		$this->assertSame( 2, $sim['simulated_elements'] );
		$this->assertCount( 1, $sim['groups'] );

		$g10 = $sim['groups'][0];
		$this->assertSame( 10, $g10['trid'] );
		$this->assertSame( 'post', $g10['element_type'] );
		$this->assertSame( 'page', $g10['subtype'] );
		$this->assertSame( 101, $g10['canonical_id'] );
		$this->assertSame( 'es', $g10['canonical_lang'] );
		$this->assertSame( 102, $g10['translations']['en'] );
	}
}
