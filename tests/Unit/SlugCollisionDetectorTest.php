<?php
/**
 * SlugCollisionDetector Unit Tests.
 *
 * @package TF\Multilingual\Tests\Unit
 */

declare( strict_types=1 );

namespace TF\Multilingual\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TF\Multilingual\Routing\SlugCollisionDetector;
use TF\Multilingual\Tests\Doubles\TestableWpdb;

/**
 * Class SlugCollisionDetectorTest
 */
class SlugCollisionDetectorTest extends TestCase {

	/**
	 * Database double.
	 *
	 * @var TestableWpdb
	 */
	private TestableWpdb $db;

	/**
	 * Detector under test.
	 *
	 * @var SlugCollisionDetector
	 */
	private SlugCollisionDetector $detector;

	/**
	 * Setup.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->db       = new TestableWpdb();
		$this->detector = new SlugCollisionDetector( $this->db );
	}

	/**
	 * Tests clean detection when no collision exists.
	 */
	public function test_no_collision(): void {
		$this->assertFalse( $this->detector->has_collision( 'en' ) );
		$this->assertNull( $this->detector->detect_collision_for_code( 'en' ) );
		$this->assertEmpty( $this->detector->detect_collisions( array( 'es', 'en', 'pt' ) ) );
	}

	/**
	 * Tests collision with an existing WordPress post/page.
	 */
	public function test_post_collision_detected(): void {
		$this->db->mock_posts[] = array(
			'ID'          => 42,
			'post_title'  => 'English Landing Page',
			'post_type'   => 'page',
			'post_name'   => 'en',
			'post_status' => 'publish',
		);

		$this->assertTrue( $this->detector->has_collision( 'en' ) );

		$collision = $this->detector->detect_collision_for_code( 'en' );
		$this->assertNotNull( $collision );
		$this->assertSame( 'post', $collision['type'] );
		$this->assertSame( 42, $collision['entity_id'] );
		$this->assertSame( 'English Landing Page', $collision['title'] );
		$this->assertSame( 'page', $collision['subtype'] );
	}

	/**
	 * Tests collision with an existing taxonomy term.
	 */
	public function test_term_collision_detected(): void {
		$this->db->mock_terms[] = array(
			'term_id'  => 88,
			'name'     => 'Category EN',
			'slug'     => 'en',
			'taxonomy' => 'category',
		);

		$this->assertTrue( $this->detector->has_collision( 'en' ) );

		$collision = $this->detector->detect_collision_for_code( 'en' );
		$this->assertNotNull( $collision );
		$this->assertSame( 'term', $collision['type'] );
		$this->assertSame( 88, $collision['entity_id'] );
		$this->assertSame( 'Category EN', $collision['title'] );
		$this->assertSame( 'category', $collision['subtype'] );
	}

	/**
	 * Tests multiple codes collision check.
	 */
	public function test_multiple_collisions(): void {
		$this->db->mock_posts[] = array(
			'ID'          => 10,
			'post_title'  => 'ES Page',
			'post_type'   => 'page',
			'post_name'   => 'es',
			'post_status' => 'publish',
		);

		$collisions = $this->detector->detect_collisions( array( 'es', 'en', 'pt' ) );

		$this->assertCount( 1, $collisions );
		$this->assertArrayHasKey( 'es', $collisions );
		$this->assertArrayNotHasKey( 'en', $collisions );
	}
}
