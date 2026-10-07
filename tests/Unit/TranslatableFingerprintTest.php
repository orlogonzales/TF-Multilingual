<?php
/**
 * TranslatableFingerprint Unit Tests.
 *
 * @package TF\Multilingual\Tests\Unit
 */

declare( strict_types=1 );

namespace TF\Multilingual\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TF\Multilingual\Domain\CustomField\CustomFieldPolicy;
use TF\Multilingual\Domain\CustomField\CustomFieldPolicyRegistry;
use TF\Multilingual\Domain\Language\SettingsRepository;
use TF\Multilingual\Domain\Versioning\TranslatableFingerprint;
use WP_Post;
use WP_Term;

/**
 * Class TranslatableFingerprintTest
 */
class TranslatableFingerprintTest extends TestCase {

	/**
	 * Setup mock WordPress globals.
	 */
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['wp_test_posts']    = array();
		$GLOBALS['wp_test_terms']    = array();
		$GLOBALS['wp_test_postmeta'] = array();
		$GLOBALS['wp_test_options']  = array();
	}

	/**
	 * Tear down mock globals.
	 */
	protected function tearDown(): void {
		parent::tearDown();
		$GLOBALS['wp_test_posts']    = array();
		$GLOBALS['wp_test_terms']    = array();
		$GLOBALS['wp_test_postmeta'] = array();
		$GLOBALS['wp_test_options']  = array();
	}

	/**
	 * Tests invalid post returns empty fingerprint.
	 */
	public function test_invalid_post_returns_empty(): void {
		$this->assertSame( '', TranslatableFingerprint::compute_for_post( 999 ) );
	}

	/**
	 * Tests post hashing is deterministic and 64 hex characters (SHA-256).
	 */
	public function test_post_produces_deterministic_sha256(): void {
		$post               = new WP_Post();
		$post->ID           = 101;
		$post->post_title   = 'Tour to Galapagos';
		$post->post_content = 'Experience wonderful wildlife and nature.';
		$post->post_excerpt = 'Galapagos adventure summary';

		$hash1 = TranslatableFingerprint::compute_for_post( $post );
		$hash2 = TranslatableFingerprint::compute_for_post( $post );

		$this->assertSame( 64, strlen( $hash1 ) );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $hash1 );
		$this->assertSame( $hash1, $hash2 );
	}

	/**
	 * Tests line endings and surrounding whitespace are normalized.
	 */
	public function test_line_endings_and_whitespace_normalization(): void {
		$post_crlf               = new WP_Post();
		$post_crlf->ID           = 101;
		$post_crlf->post_title   = "  Title with spaces \r\n";
		$post_crlf->post_content = "Line 1\r\nLine 2\r\n";
		$post_crlf->post_excerpt = " Excerpt \r\n";

		$post_lf               = new WP_Post();
		$post_lf->ID           = 102;
		$post_lf->post_title   = 'Title with spaces';
		$post_lf->post_content = "Line 1\nLine 2";
		$post_lf->post_excerpt = 'Excerpt';

		$hash_crlf = TranslatableFingerprint::compute_for_post( $post_crlf );
		$hash_lf   = TranslatableFingerprint::compute_for_post( $post_lf );

		$this->assertSame( $hash_crlf, $hash_lf );
	}

	/**
	 * Tests changes in title, content, or excerpt produce different hashes.
	 */
	public function test_core_fields_mutation_changes_fingerprint(): void {
		$post1               = new WP_Post();
		$post1->ID           = 101;
		$post1->post_title   = 'Title A';
		$post1->post_content = 'Content A';
		$post1->post_excerpt = 'Excerpt A';

		$post2               = new WP_Post();
		$post2->ID           = 102;
		$post2->post_title   = 'Title B';
		$post2->post_content = 'Content A';
		$post2->post_excerpt = 'Excerpt A';

		$post3               = new WP_Post();
		$post3->ID           = 103;
		$post3->post_title   = 'Title A';
		$post3->post_content = 'Content Modified';
		$post3->post_excerpt = 'Excerpt A';

		$h1 = TranslatableFingerprint::compute_for_post( $post1 );
		$h2 = TranslatableFingerprint::compute_for_post( $post2 );
		$h3 = TranslatableFingerprint::compute_for_post( $post3 );

		$this->assertNotSame( $h1, $h2 );
		$this->assertNotSame( $h1, $h3 );
		$this->assertNotSame( $h2, $h3 );
	}

	/**
	 * Tests distinct value types produce distinct canonical serializations.
	 */
	public function test_distinct_types_are_differentiated(): void {
		$values = array(
			'empty_string' => '',
			'string_zero'  => '0',
			'int_zero'     => 0,
			'bool_false'   => false,
			'null'         => null,
			'empty_array'  => array(),
		);

		$serialized = array();
		foreach ( $values as $key => $val ) {
			$serialized[ $key ] = TranslatableFingerprint::canonical_serialize( $val );
		}

		$unique_count = count( array_unique( $serialized ) );
		$this->assertSame( count( $values ), $unique_count );
	}

	/**
	 * Tests TRANSLATE custom fields affect the fingerprint.
	 */
	public function test_translate_custom_fields_affect_fingerprint(): void {
		$repo     = new SettingsRepository();
		$registry = new CustomFieldPolicyRegistry( $repo );
		$registry->set_policy( 'tour_subtitle', CustomFieldPolicy::TRANSLATE );
		$registry->set_policy( 'price', CustomFieldPolicy::SHARE );
		$registry->set_policy( 'internal_notes', CustomFieldPolicy::IGNORE );

		$post               = new WP_Post();
		$post->ID           = 201;
		$post->post_title   = 'Amazon Tour';
		$post->post_content = 'Welcome to the rainforest';
		$post->post_excerpt = 'Short excerpt';

		$GLOBALS['wp_test_posts'][201]    = $post;
		$GLOBALS['wp_test_postmeta'][201] = array(
			'tour_subtitle'  => 'An amazing adventure',
			'price'          => 450,
			'internal_notes' => 'Agent private data',
		);

		$hash_base = TranslatableFingerprint::compute_for_post( $post, $registry );

		// Modify SHARE field: fingerprint MUST NOT change.
		$GLOBALS['wp_test_postmeta'][201]['price'] = 550;
		$hash_after_share                          = TranslatableFingerprint::compute_for_post( $post, $registry );
		$this->assertSame( $hash_base, $hash_after_share );

		// Modify IGNORE field: fingerprint MUST NOT change.
		$GLOBALS['wp_test_postmeta'][201]['internal_notes'] = 'Updated agent notes';
		$hash_after_ignore                                  = TranslatableFingerprint::compute_for_post( $post, $registry );
		$this->assertSame( $hash_base, $hash_after_ignore );

		// Modify TRANSLATE field: fingerprint MUST change.
		$GLOBALS['wp_test_postmeta'][201]['tour_subtitle'] = 'A totally revised subtitle';
		$hash_after_translate                              = TranslatableFingerprint::compute_for_post( $post, $registry );
		$this->assertNotSame( $hash_base, $hash_after_translate );
	}

	/**
	 * Tests term fingerprint evaluates name, slug, description deterministically.
	 */
	public function test_term_fingerprint_deterministic_and_sensitive_to_changes(): void {
		$term1              = new WP_Term();
		$term1->term_id     = 301;
		$term1->taxonomy    = 'category';
		$term1->name        = 'Adventure';
		$term1->slug        = 'adventure';
		$term1->description = 'All adventure trips';

		$term2              = new WP_Term();
		$term2->term_id     = 302;
		$term2->taxonomy    = 'category';
		$term2->name        = 'Adventure Modified';
		$term2->slug        = 'adventure';
		$term2->description = 'All adventure trips';

		$h1 = TranslatableFingerprint::compute_for_term( $term1 );
		$h2 = TranslatableFingerprint::compute_for_term( $term2 );

		$this->assertSame( 64, strlen( $h1 ) );
		$this->assertNotSame( $h1, $h2 );
	}

	/**
	 * Tests term fingerprint changes when ONLY slug is modified (name and description identical).
	 */
	public function test_term_fingerprint_sensitive_to_slug_change_only(): void {
		$term1              = new WP_Term();
		$term1->term_id     = 303;
		$term1->taxonomy    = 'category';
		$term1->name        = 'Ecotourism';
		$term1->slug        = 'ecotourism-original';
		$term1->description = 'Ecotourism tours and activities';

		$term2              = new WP_Term();
		$term2->term_id     = 303;
		$term2->taxonomy    = 'category';
		$term2->name        = 'Ecotourism';
		$term2->slug        = 'ecotourism-renamed';
		$term2->description = 'Ecotourism tours and activities';

		$h1 = TranslatableFingerprint::compute_for_term( $term1 );
		$h2 = TranslatableFingerprint::compute_for_term( $term2 );

		$this->assertNotSame( $h1, $h2, 'Modifying term slug must alter term translatable fingerprint' );
	}

	/**
	 * Tests instance methods delegate cleanly to static calculations.
	 */
	public function test_instance_methods(): void {
		$service = new TranslatableFingerprint();

		$post             = new WP_Post();
		$post->ID         = 401;
		$post->post_title = 'Sample';

		$this->assertSame(
			TranslatableFingerprint::compute_for_post( $post ),
			$service->calculate_post_fingerprint( $post )
		);
	}
}
