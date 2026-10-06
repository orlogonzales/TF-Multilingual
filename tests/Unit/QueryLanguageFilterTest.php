<?php
/**
 * Query Language Filter Unit Tests.
 *
 * @package TF\Multilingual\Tests\Unit
 */

declare( strict_types=1 );

namespace TF\Multilingual\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TF\Multilingual\Domain\Language\Language;
use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Domain\Language\SettingsRepository;
use TF\Multilingual\Query\QueryLanguageFilter;
use TF\Multilingual\Routing\CurrentLanguageResolver;
use TF\Multilingual\Routing\UrlLanguageResolver;
use TF\Multilingual\Tests\Doubles\TestableWpdb;
use WP_Query;

/**
 * Class QueryLanguageFilterTest
 */
class QueryLanguageFilterTest extends TestCase {

	/**
	 * Language registry.
	 *
	 * @var LanguageRegistry
	 */
	private LanguageRegistry $registry;

	/**
	 * Current language resolver.
	 *
	 * @var CurrentLanguageResolver
	 */
	private CurrentLanguageResolver $current_resolver;

	/**
	 * Testable wpdb.
	 *
	 * @var TestableWpdb
	 */
	private TestableWpdb $db;

	/**
	 * Filter under test.
	 *
	 * @var QueryLanguageFilter
	 */
	private QueryLanguageFilter $filter;

	/**
	 * Sets up test environment.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->registry = new LanguageRegistry( new SettingsRepository() );
		$this->registry->add_language( new Language( 'es', 'es_ES', 'Spanish', 'Español', true, 1 ), true );
		$this->registry->add_language( new Language( 'en', 'en_US', 'English', 'English', true, 2 ) );
		$this->registry->add_language( new Language( 'fr', 'fr_FR', 'French', 'Français', false, 3 ) ); // Inactive.

		$url_resolver           = new UrlLanguageResolver( $this->registry, 'http://example.com' );
		$this->current_resolver = new CurrentLanguageResolver( $url_resolver );
		$this->db               = new TestableWpdb();
		$this->filter           = new QueryLanguageFilter( $this->registry, $this->current_resolver, $this->db );
	}

	/**
	 * Cleans up environment.
	 */
	protected function tearDown(): void {
		$this->current_resolver->reset();
		parent::tearDown();
	}

	/**
	 * Tests filter is ineligible when registry is unconfigured.
	 */
	public function test_filter_not_eligible_when_unconfigured(): void {
		$unconfigured_registry = new LanguageRegistry( new SettingsRepository() );
		$filter                = new QueryLanguageFilter( $unconfigured_registry, $this->current_resolver, $this->db );

		$query = new WP_Query( array( 'post_type' => 'post' ) );
		$this->assertFalse( $filter->is_query_eligible( $query ) );
	}

	/**
	 * Tests explicit TFML opt-out flag disables filtering.
	 */
	public function test_filter_not_eligible_when_tfml_suppress_requested(): void {
		$query = new WP_Query(
			array(
				'post_type'                             => 'post',
				QueryLanguageFilter::QUERY_VAR_SUPPRESS => true,
			)
		);

		$this->assertFalse( $this->filter->is_query_eligible( $query ) );
	}

	/**
	 * Tests native WordPress suppress_filters disables filtering.
	 */
	public function test_filter_not_eligible_when_suppress_filters_requested(): void {
		$query = new WP_Query(
			array(
				'post_type'        => 'post',
				'suppress_filters' => true,
			)
		);

		$this->assertFalse( $this->filter->is_query_eligible( $query ) );
	}

	/**
	 * Tests preview queries are excluded.
	 */
	public function test_filter_not_eligible_in_preview(): void {
		$query             = new WP_Query( array( 'p' => 42 ) );
		$query->is_preview = true;

		$this->assertFalse( $this->filter->is_query_eligible( $query ) );
	}

	/**
	 * Tests attachment post type is excluded.
	 */
	public function test_filter_not_eligible_for_attachment(): void {
		$query = new WP_Query( array( 'post_type' => 'attachment' ) );
		$this->assertFalse( $this->filter->is_query_eligible( $query ) );
	}

	/**
	 * Tests internal post types are excluded.
	 */
	public function test_filter_not_eligible_for_internal_post_types(): void {
		$query_nav = new WP_Query( array( 'post_type' => 'nav_menu_item' ) );
		$this->assertFalse( $this->filter->is_query_eligible( $query_nav ) );

		$query_rev = new WP_Query( array( 'post_type' => 'revision' ) );
		$this->assertFalse( $this->filter->is_query_eligible( $query_rev ) );

		$query_array = new WP_Query( array( 'post_type' => array( 'nav_menu_item', 'revision' ) ) );
		$this->assertFalse( $this->filter->is_query_eligible( $query_array ) );
	}

	/**
	 * Tests public post types and CPTs are eligible.
	 */
	public function test_filter_eligible_for_public_post_types(): void {
		$query_post = new WP_Query( array( 'post_type' => 'post' ) );
		$this->assertTrue( $this->filter->is_query_eligible( $query_post ) );

		$query_page = new WP_Query( array( 'post_type' => 'page' ) );
		$this->assertTrue( $this->filter->is_query_eligible( $query_page ) );

		$query_cpt = new WP_Query( array( 'post_type' => 'rooms' ) );
		$this->assertTrue( $this->filter->is_query_eligible( $query_cpt ) );

		$query_empty = new WP_Query();
		$this->assertTrue( $this->filter->is_query_eligible( $query_empty ) );
	}

	/**
	 * Tests on_pre_get_posts sets target language.
	 */
	public function test_on_pre_get_posts_sets_target_language(): void {
		$this->current_resolver->set_current_language( 'en' );

		$query = new WP_Query( array( 'post_type' => 'post' ) );
		$this->filter->on_pre_get_posts( $query );

		$this->assertSame( 'en', $query->get( QueryLanguageFilter::QUERY_VAR_TARGET_LANG ) );
	}

	/**
	 * Tests on_pre_get_posts does not set target language for ineligible queries.
	 */
	public function test_on_pre_get_posts_skips_ineligible(): void {
		$this->current_resolver->set_current_language( 'en' );

		$query = new WP_Query(
			array(
				'post_type'        => 'post',
				'suppress_filters' => true,
			)
		);
		$this->filter->on_pre_get_posts( $query );

		$this->assertSame( '', $query->get( QueryLanguageFilter::QUERY_VAR_TARGET_LANG ) );
	}

	/**
	 * Tests SQL clause filtering for default language (Progressive Adoption: default + unmanaged).
	 */
	public function test_filter_posts_clauses_for_default_language(): void {
		$query = new WP_Query( array( 'post_type' => 'post' ) );
		$query->set( QueryLanguageFilter::QUERY_VAR_TARGET_LANG, 'es' );

		$initial_clauses = array(
			'join'     => 'INNER JOIN wp_postmeta ON wp_posts.ID = wp_postmeta.post_id',
			'where'    => "AND wp_posts.post_type = 'post'",
			'distinct' => '',
		);

		$filtered = $this->filter->filter_posts_clauses( $initial_clauses, $query );

		// 1. Join appended, existing join preserved.
		$this->assertStringContainsString( 'INNER JOIN wp_postmeta', $filtered['join'] );
		$this->assertStringContainsString( "LEFT JOIN wp_tfml_group_elements AS tfml_ge ON (tfml_ge.element_id = wp_posts.ID AND tfml_ge.element_type = 'post')", $filtered['join'] );

		// 2. Where condition for default language includes unmanaged (OR tfml_ge.id IS NULL).
		$this->assertStringContainsString( "AND wp_posts.post_type = 'post'", $filtered['where'] );
		$this->assertStringContainsString( "AND (tfml_ge.language_code = 'es' OR tfml_ge.id IS NULL)", $filtered['where'] );

		// 3. No DISTINCT added.
		$this->assertSame( '', $filtered['distinct'] );

		// 4. Marked applied.
		$this->assertTrue( $query->get( QueryLanguageFilter::QUERY_VAR_APPLIED ) );
	}

	/**
	 * Tests SQL clause filtering for secondary language (Strict: managed only, NO FALLBACK).
	 */
	public function test_filter_posts_clauses_for_secondary_language(): void {
		$query = new WP_Query( array( 'post_type' => 'post' ) );
		$query->set( QueryLanguageFilter::QUERY_VAR_TARGET_LANG, 'en' );

		$initial_clauses = array(
			'join'     => '',
			'where'    => "AND wp_posts.post_type = 'post'",
			'distinct' => '',
		);

		$filtered = $this->filter->filter_posts_clauses( $initial_clauses, $query );

		$this->assertStringContainsString( 'LEFT JOIN wp_tfml_group_elements AS tfml_ge', $filtered['join'] );
		$this->assertStringContainsString( "AND tfml_ge.language_code = 'en'", $filtered['where'] );
		$this->assertStringNotContainsString( 'OR tfml_ge.id IS NULL', $filtered['where'] );
	}

	/**
	 * Tests idempotency: clauses are not applied multiple times to the same query.
	 */
	public function test_filter_posts_clauses_idempotency(): void {
		$query = new WP_Query( array( 'post_type' => 'post' ) );
		$query->set( QueryLanguageFilter::QUERY_VAR_TARGET_LANG, 'en' );

		$clauses = array(
			'join'  => '',
			'where' => "AND wp_posts.post_type = 'post'",
		);

		$first_pass  = $this->filter->filter_posts_clauses( $clauses, $query );
		$second_pass = $this->filter->filter_posts_clauses( $first_pass, $query );

		$this->assertSame( $first_pass['join'], $second_pass['join'] );
		$this->assertSame( $first_pass['where'], $second_pass['where'] );
	}

	/**
	 * Tests filter skips if target language was not qualified.
	 */
	public function test_filter_posts_clauses_skips_if_unqualified(): void {
		$query   = new WP_Query( array( 'post_type' => 'post' ) );
		$clauses = array(
			'join'  => '',
			'where' => "AND wp_posts.post_type = 'post'",
		);

		$filtered = $this->filter->filter_posts_clauses( $clauses, $query );
		$this->assertSame( $clauses, $filtered );
	}

	/**
	 * Tests filter skips if target language is inactive.
	 */
	public function test_filter_posts_clauses_skips_if_language_inactive(): void {
		$query = new WP_Query( array( 'post_type' => 'post' ) );
		$query->set( QueryLanguageFilter::QUERY_VAR_TARGET_LANG, 'fr' ); // 'fr' is inactive.

		$clauses = array(
			'join'  => '',
			'where' => "AND wp_posts.post_type = 'post'",
		);

		$filtered = $this->filter->filter_posts_clauses( $clauses, $query );
		$this->assertSame( $clauses, $filtered );
	}

	/**
	 * Tests strict policy behavior (future phase readiness).
	 */
	public function test_strict_policy_behavior(): void {
		$this->filter->set_unmanaged_policy( QueryLanguageFilter::POLICY_STRICT );
		$this->assertSame( QueryLanguageFilter::POLICY_STRICT, $this->filter->get_unmanaged_policy() );

		$query = new WP_Query( array( 'post_type' => 'post' ) );
		$query->set( QueryLanguageFilter::QUERY_VAR_TARGET_LANG, 'es' );

		$clauses = array(
			'join'  => '',
			'where' => '',
		);

		$filtered = $this->filter->filter_posts_clauses( $clauses, $query );

		// In strict policy, default language also requires managed relation (no unmanaged fallback).
		$this->assertStringContainsString( "AND tfml_ge.language_code = 'es'", $filtered['where'] );
		$this->assertStringNotContainsString( 'OR tfml_ge.id IS NULL', $filtered['where'] );
	}

	/**
	 * Tests hook initialization.
	 */
	public function test_init_hooks(): void {
		$this->filter->init_hooks();
		$this->assertTrue( true );
	}
}
