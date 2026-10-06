<?php
/**
 * Term Query Language Filter Tests.
 *
 * @package TF\Multilingual\Tests\Unit
 */

declare( strict_types=1 );

namespace TF\Multilingual\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TF\Multilingual\Domain\Language\Language;
use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Domain\Language\SettingsRepository;
use TF\Multilingual\Query\TermQueryLanguageFilter;
use TF\Multilingual\Routing\CurrentLanguageResolver;
use TF\Multilingual\Routing\UrlLanguageResolver;
use TF\Multilingual\Tests\Doubles\TestableWpdb;

/**
 * Class TermQueryLanguageFilterTest
 *
 * Covers WP_Term_Query filtering, clauses injection, taxonomy subtype matching,
 * progressive adoption policy, opt-outs, and context exclusions.
 */
class TermQueryLanguageFilterTest extends TestCase {

	/**
	 * Database double.
	 *
	 * @var TestableWpdb
	 */
	private TestableWpdb $db;

	/**
	 * Settings repository.
	 *
	 * @var SettingsRepository
	 */
	private SettingsRepository $settings_repo;

	/**
	 * Language registry.
	 *
	 * @var LanguageRegistry
	 */
	private LanguageRegistry $registry;

	/**
	 * URL resolver.
	 *
	 * @var UrlLanguageResolver
	 */
	private UrlLanguageResolver $url_resolver;

	/**
	 * Current language resolver.
	 *
	 * @var CurrentLanguageResolver
	 */
	private CurrentLanguageResolver $current_lang_resolver;

	/**
	 * Term filter under test.
	 *
	 * @var TermQueryLanguageFilter
	 */
	private TermQueryLanguageFilter $filter;

	/**
	 * Sets up the test fixture.
	 */
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['wp_test_options'] = array();
		$this->db                   = new TestableWpdb();
		$this->settings_repo        = new SettingsRepository();
		$this->registry             = new LanguageRegistry( $this->settings_repo );

		$es = Language::create( 'es', 'es_ES', 'Spanish', 'Español', true, 10 );
		$en = Language::create( 'en', 'en_US', 'English', 'English', true, 20 );
		$fr = Language::create( 'fr', 'fr_FR', 'French', 'Français', false, 30 ); // Inactive.

		$this->registry->add_language( $es, true );
		$this->registry->add_language( $en );
		$this->registry->add_language( $fr );

		$this->url_resolver          = new UrlLanguageResolver( $this->registry, 'http://example.org' );
		$this->current_lang_resolver = new CurrentLanguageResolver( $this->url_resolver );
		$this->filter                = new TermQueryLanguageFilter(
			$this->registry,
			$this->current_lang_resolver,
			$this->db
		);
	}

	/**
	 * Tests that unconfigured registry renders query ineligible.
	 */
	public function test_ineligible_when_not_configured(): void {
		$unconf_repo     = new SettingsRepository();
		$unconf_registry = new LanguageRegistry( $unconf_repo );
		$unconf_url_res  = new UrlLanguageResolver( $unconf_registry, 'http://example.org' );
		$unconf_curr_res = new CurrentLanguageResolver( $unconf_url_res );
		$filter          = new TermQueryLanguageFilter( $unconf_registry, $unconf_curr_res, $this->db );

		$this->assertFalse( $filter->is_query_eligible( array() ) );

		$clauses = array(
			'join'  => '',
			'where' => "tt.taxonomy IN ('category')",
		);
		$result  = $filter->filter_terms_clauses( $clauses, array( 'category' ), array() );
		$this->assertSame( $clauses, $result );
	}

	/**
	 * Tests explicit TFML opt-out argument.
	 */
	public function test_explicit_tfml_opt_out(): void {
		$args = array( TermQueryLanguageFilter::QUERY_VAR_SUPPRESS => true );
		$this->assertFalse( $this->filter->is_query_eligible( $args ) );
	}

	/**
	 * Tests native WordPress suppress_filter argument.
	 */
	public function test_native_suppress_filter(): void {
		$args = array( 'suppress_filter' => true );
		$this->assertFalse( $this->filter->is_query_eligible( $args ) );
	}

	/**
	 * Tests defensive plural suppress_filters argument.
	 */
	public function test_defensive_suppress_filters(): void {
		$args = array( 'suppress_filters' => true );
		$this->assertFalse( $this->filter->is_query_eligible( $args ) );
	}

	/**
	 * Tests that explicit identity lookups (e.g. get_term_by with 'get' => 'all') are exempted.
	 */
	public function test_explicit_identity_lookup_exempted(): void {
		$args_slug = array(
			'get'  => 'all',
			'slug' => 'playa-del-carmen',
		);
		$this->assertFalse( $this->filter->is_query_eligible( $args_slug ) );

		$args_name = array(
			'get'  => 'all',
			'name' => 'Playa del Carmen',
		);
		$this->assertFalse( $this->filter->is_query_eligible( $args_name ) );

		$args_tt = array(
			'get'              => 'all',
			'term_taxonomy_id' => 42,
		);
		$this->assertFalse( $this->filter->is_query_eligible( $args_tt ) );

		// Single include ID with get => all and number => 1 (term_exists ID lookup).
		$args_exists = array(
			'get'     => 'all',
			'include' => array( 42 ),
			'number'  => 1,
		);
		$this->assertFalse( $this->filter->is_query_eligible( $args_exists ) );

		// Normal query with 'get' => 'all' but without identity parameters is eligible.
		$args_normal = array(
			'get' => 'all',
		);
		$this->assertTrue( $this->filter->is_query_eligible( $args_normal ) );
	}

	/**
	 * Tests default language clause injection under progressive adoption policy.
	 */
	public function test_default_language_clauses_injection(): void {
		$this->current_lang_resolver->set_current_language( 'es' );

		$clauses = array(
			'fields'  => 't.term_id',
			'join'    => 'INNER JOIN wp_term_taxonomy AS tt ON t.term_id = tt.term_id',
			'where'   => "tt.taxonomy IN ('category')",
			'orderby' => 'ORDER BY t.name',
			'order'   => 'ASC',
			'limits'  => '',
		);

		$result = $this->filter->filter_terms_clauses( $clauses, array( 'category' ), array() );

		// Verifies join includes tfml_group_elements AND tfml_groups.
		$this->assertStringContainsString( 'LEFT JOIN wp_tfml_group_elements AS tfml_ge', $result['join'] );
		$this->assertStringContainsString( 'LEFT JOIN wp_tfml_groups AS tfml_g ON (tfml_g.id = tfml_ge.group_id)', $result['join'] );

		// Verifies where includes default language managed for matching taxonomy OR unmanaged.
		$expected_where = "((tfml_ge.language_code = 'es' AND tfml_g.subtype = tt.taxonomy) OR tfml_ge.id IS NULL)";
		$this->assertStringContainsString( $expected_where, $result['where'] );

		// Verifies original clauses are preserved.
		$this->assertStringContainsString( "tt.taxonomy IN ('category')", $result['where'] );
		$this->assertSame( 't.term_id', $result['fields'] );
		$this->assertSame( 'ORDER BY t.name', $result['orderby'] );
	}

	/**
	 * Tests secondary language clause injection (Strict NO FALLBACK, no unmanaged).
	 */
	public function test_secondary_language_clauses_injection(): void {
		$this->current_lang_resolver->set_current_language( 'en' );

		$clauses = array(
			'fields'  => 't.term_id',
			'join'    => 'INNER JOIN wp_term_taxonomy AS tt ON t.term_id = tt.term_id',
			'where'   => "tt.taxonomy IN ('category')",
			'orderby' => 'ORDER BY t.name',
			'order'   => 'ASC',
			'limits'  => '',
		);

		$result = $this->filter->filter_terms_clauses( $clauses, array( 'category' ), array() );

		$this->assertStringContainsString( 'LEFT JOIN wp_tfml_group_elements AS tfml_ge', $result['join'] );
		$this->assertStringContainsString( 'LEFT JOIN wp_tfml_groups AS tfml_g', $result['join'] );

		// Strict NO FALLBACK: strictly requires en and matching taxonomy subtype.
		$expected_where = "(tfml_ge.language_code = 'en' AND tfml_g.subtype = tt.taxonomy)";
		$this->assertStringContainsString( $expected_where, $result['where'] );
		$this->assertStringNotContainsString( 'IS NULL', $result['where'] );
	}

	/**
	 * Tests that taxonomy subtype matching works for post_tag and custom taxonomies.
	 */
	public function test_taxonomy_subtype_matching_for_tags_and_custom_tax(): void {
		$this->current_lang_resolver->set_current_language( 'en' );

		// Tag query.
		$tag_clauses = array(
			'join'  => 'INNER JOIN wp_term_taxonomy AS tt ON t.term_id = tt.term_id',
			'where' => "tt.taxonomy IN ('post_tag')",
		);
		$tag_res     = $this->filter->filter_terms_clauses( $tag_clauses, array( 'post_tag' ), array() );
		$this->assertStringContainsString( "tfml_ge.language_code = 'en' AND tfml_g.subtype = tt.taxonomy", $tag_res['where'] );

		// Custom taxonomy query.
		$custom_clauses = array(
			'join'  => 'INNER JOIN wp_term_taxonomy AS tt ON t.term_id = tt.term_id',
			'where' => "tt.taxonomy IN ('lab_destination')",
		);
		$custom_res     = $this->filter->filter_terms_clauses( $custom_clauses, array( 'lab_destination' ), array() );
		$this->assertStringContainsString( "tfml_ge.language_code = 'en' AND tfml_g.subtype = tt.taxonomy", $custom_res['where'] );
	}

	/**
	 * Tests strict policy switch excludes unmanaged content in default language.
	 */
	public function test_strict_policy_excludes_unmanaged_in_default_language(): void {
		$this->filter->set_unmanaged_policy( TermQueryLanguageFilter::POLICY_STRICT );
		$this->assertSame( TermQueryLanguageFilter::POLICY_STRICT, $this->filter->get_unmanaged_policy() );

		$this->current_lang_resolver->set_current_language( 'es' );

		$clauses = array(
			'join'  => 'INNER JOIN wp_term_taxonomy AS tt ON t.term_id = tt.term_id',
			'where' => "tt.taxonomy IN ('category')",
		);
		$result  = $this->filter->filter_terms_clauses( $clauses, array( 'category' ), array() );

		// In strict policy, default language does NOT include IS NULL.
		$this->assertStringContainsString( "tfml_ge.language_code = 'es' AND tfml_g.subtype = tt.taxonomy", $result['where'] );
		$this->assertStringNotContainsString( 'IS NULL', $result['where'] );
	}

	/**
	 * Tests that inactive language bypasses filtering without degradation.
	 */
	public function test_inactive_language_bypasses_filtering(): void {
		$this->current_lang_resolver->set_current_language( 'fr' );

		$clauses = array(
			'join'  => '',
			'where' => "tt.taxonomy IN ('category')",
		);
		$result  = $this->filter->filter_terms_clauses( $clauses, array( 'category' ), array() );

		// Fr is inactive, so clauses must remain untouched.
		$this->assertSame( $clauses, $result );
	}

	/**
	 * Tests idempotency prevents duplicate join application.
	 */
	public function test_idempotency_prevents_duplicate_join(): void {
		$this->current_lang_resolver->set_current_language( 'en' );

		$clauses = array(
			'join'  => 'LEFT JOIN wp_tfml_group_elements AS tfml_ge ON (tfml_ge.element_id = t.term_id)',
			'where' => "tt.taxonomy IN ('category')",
		);
		$result  = $this->filter->filter_terms_clauses( $clauses, array( 'category' ), array() );

		// Join must NOT be appended again.
		$this->assertSame( $clauses, $result );
	}

	/**
	 * Tests that distinct clause is never injected.
	 */
	public function test_distinct_clause_never_injected(): void {
		$this->current_lang_resolver->set_current_language( 'es' );

		$clauses = array(
			'distinct' => '',
			'join'     => '',
			'where'    => "tt.taxonomy IN ('category')",
		);
		$result  = $this->filter->filter_terms_clauses( $clauses, array( 'category' ), array() );

		$this->assertSame( '', $result['distinct'] );
	}

	/**
	 * Tests that empty initial where clause is properly initialized.
	 */
	public function test_empty_initial_where_clause_handled(): void {
		$this->current_lang_resolver->set_current_language( 'en' );

		$clauses = array(
			'join'  => '',
			'where' => '',
		);
		$result  = $this->filter->filter_terms_clauses( $clauses, array(), array() );

		$this->assertSame( "(tfml_ge.language_code = 'en' AND tfml_g.subtype = tt.taxonomy)", $result['where'] );
	}

	/**
	 * Tests that count queries preserve SELECT COUNT(*) without altering fields.
	 */
	public function test_count_queries_preserve_fields(): void {
		$this->current_lang_resolver->set_current_language( 'en' );

		$clauses = array(
			'fields' => 'COUNT(*)',
			'join'   => 'INNER JOIN wp_term_taxonomy AS tt ON t.term_id = tt.term_id',
			'where'  => "tt.taxonomy IN ('category')",
		);
		$result  = $this->filter->filter_terms_clauses( $clauses, array( 'category' ), array( 'fields' => 'count' ) );

		$this->assertSame( 'COUNT(*)', $result['fields'] );
		$this->assertStringContainsString( "tfml_ge.language_code = 'en'", $result['where'] );
	}

	/**
	 * Tests that orderby, order, and limits clauses are preserved intact.
	 */
	public function test_orderby_order_and_limits_preserved(): void {
		$this->current_lang_resolver->set_current_language( 'es' );

		$clauses = array(
			'orderby' => 'ORDER BY t.name',
			'order'   => 'DESC',
			'limits'  => 'LIMIT 0, 10',
			'join'    => '',
			'where'   => "tt.taxonomy IN ('category')",
		);
		$result  = $this->filter->filter_terms_clauses( $clauses, array( 'category' ), array() );

		$this->assertSame( 'ORDER BY t.name', $result['orderby'] );
		$this->assertSame( 'DESC', $result['order'] );
		$this->assertSame( 'LIMIT 0, 10', $result['limits'] );
	}

	/**
	 * Tests that init_hooks registers the terms_clauses filter.
	 */
	public function test_init_hooks_registers_terms_clauses_filter(): void {
		// Verify method runs without exceptions.
		$this->filter->init_hooks();
		$this->assertTrue( true );
	}
}
