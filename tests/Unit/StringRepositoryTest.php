<?php
/**
 * String Repository Test.
 *
 * @package TF\Multilingual\Tests\Unit
 */

declare( strict_types=1 );

namespace TF\Multilingual\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TF\Multilingual\Domain\Strings\Exceptions\InvalidStringException;
use TF\Multilingual\Domain\Strings\Exceptions\StringNotFoundException;
use TF\Multilingual\Domain\Strings\StringRepository;
use TF\Multilingual\Domain\Strings\StringStatus;
use wpdb;

/**
 * Class StringRepositoryTest
 */
class StringRepositoryTest extends TestCase {

	/**
	 * Test registering a new string inserts row and returns entity.
	 */
	public function test_register_new_string(): void {
		$wpdb            = $this->createMock( wpdb::class );
		$wpdb->prefix    = 'wp_';
		$wpdb->insert_id = 42;

		// 1. find_by_domain_and_key returns null (doesn't exist).
		$wpdb->expects( $this->once() )
			->method( 'get_row' )
			->willReturn( null );

		// 2. insert is called.
		$wpdb->expects( $this->once() )
			->method( 'insert' )
			->with(
				'wp_tfml_strings',
				$this->callback(
					function ( $data ) {
						return 'theme' === $data['domain']
							&& 'header.cta' === $data['string_key']
							&& 'Book now' === $data['original_value']
							&& 1 === $data['string_version'];
					}
				)
			)
			->willReturn( 1 );

		$repo   = new StringRepository( $wpdb );
		$string = $repo->register( 'theme', 'header.cta', 'Book now', 'button', 'es' );

		$this->assertSame( 42, $string->get_id() );
		$this->assertSame( 'theme', $string->get_domain() );
		$this->assertSame( 'header.cta', $string->get_string_key() );
		$this->assertSame( 'Book now', $string->get_original_value() );
		$this->assertSame( 1, $string->get_string_version() );
		$this->assertFalse( $string->has_conflict() );
	}

	/**
	 * Test registering existing string with same source is idempotent and does not increment version.
	 */
	public function test_register_idempotent_no_version_increment(): void {
		$wpdb         = $this->createMock( wpdb::class );
		$wpdb->prefix = 'wp_';

		$existing_row = (object) array(
			'id'              => 42,
			'domain'          => 'theme',
			'string_key'      => 'header.cta',
			'context'         => 'button',
			'original_value'  => 'Book now',
			'source_language' => 'es',
			'string_version'  => 1,
			'has_conflict'    => 0,
			'last_seen_at'    => '2026-10-07 10:00:00',
			'created_at'      => '2026-10-07 09:00:00',
		);

		$wpdb->expects( $this->once() )
			->method( 'get_row' )
			->willReturn( $existing_row );

		// insert should never be called.
		$wpdb->expects( $this->never() )->method( 'insert' );

		$repo   = new StringRepository( $wpdb );
		$string = $repo->register( 'theme', 'header.cta', 'Book now', 'button', 'es' );

		$this->assertSame( 42, $string->get_id() );
		$this->assertSame( 1, $string->get_string_version() );
		$this->assertSame( 'Book now', $string->get_original_value() );
	}

	/**
	 * Test source text modification increments version and demotes translations to REVIEW.
	 */
	public function test_register_source_change_increments_version_and_demotes_translations(): void {
		$wpdb         = $this->createMock( wpdb::class );
		$wpdb->prefix = 'wp_';

		$existing_row = (object) array(
			'id'              => 42,
			'domain'          => 'theme',
			'string_key'      => 'header.cta',
			'context'         => 'button',
			'original_value'  => 'Book now',
			'source_language' => 'es',
			'string_version'  => 1,
			'has_conflict'    => 0,
			'last_seen_at'    => '2026-10-07 10:00:00',
			'created_at'      => '2026-10-07 09:00:00',
		);

		$wpdb->expects( $this->once() )
			->method( 'get_row' )
			->willReturn( $existing_row );

		// Expect update on wp_tfml_strings with version = 2.
		$wpdb->expects( $this->once() )
			->method( 'update' )
			->with(
				'wp_tfml_strings',
				$this->callback(
					function ( $data ) {
						return 'Book your tour' === $data['original_value'] && 2 === $data['string_version'];
					}
				),
				array( 'id' => 42 )
			)
			->willReturn( 1 );

		// Expect query on wp_tfml_string_translations to demote translations to needs_review.
		$wpdb->expects( $this->once() )
			->method( 'query' )
			->willReturn( 1 );

		$repo   = new StringRepository( $wpdb );
		$string = $repo->register( 'theme', 'header.cta', 'Book your tour', 'button', 'es' );

		$this->assertSame( 42, $string->get_id() );
		$this->assertSame( 2, $string->get_string_version() );
		$this->assertSame( 'Book your tour', $string->get_original_value() );
	}

	/**
	 * Test context conflict detection.
	 */
	public function test_context_conflict_detection(): void {
		$wpdb         = $this->createMock( wpdb::class );
		$wpdb->prefix = 'wp_';

		$existing_row = (object) array(
			'id'              => 42,
			'domain'          => 'theme',
			'string_key'      => 'header.cta',
			'context'         => 'button',
			'original_value'  => 'Book now',
			'source_language' => 'es',
			'string_version'  => 1,
			'has_conflict'    => 0,
			'last_seen_at'    => '2026-10-07 10:00:00',
			'created_at'      => '2026-10-07 09:00:00',
		);

		$wpdb->expects( $this->once() )
			->method( 'get_row' )
			->willReturn( $existing_row );

		// Conflicting context 'link' vs 'button' should trigger update with has_conflict = 1.
		$wpdb->expects( $this->once() )
			->method( 'update' )
			->with(
				'wp_tfml_strings',
				array( 'has_conflict' => 1 ),
				array( 'id' => 42 )
			)
			->willReturn( 1 );

		$repo   = new StringRepository( $wpdb );
		$string = $repo->register( 'theme', 'header.cta', 'Book now', 'link', 'es' );

		$this->assertTrue( $string->has_conflict() );
	}

	/**
	 * Test empty domain throws exception.
	 */
	public function test_empty_domain_throws(): void {
		$wpdb = $this->createMock( wpdb::class );
		$repo = new StringRepository( $wpdb );

		$this->expectException( InvalidStringException::class );
		$repo->register( '', 'key', 'Val' );
	}

	/**
	 * Test empty key throws exception.
	 */
	public function test_empty_key_throws(): void {
		$wpdb = $this->createMock( wpdb::class );
		$repo = new StringRepository( $wpdb );

		$this->expectException( InvalidStringException::class );
		$repo->register( 'domain', '', 'Val' );
	}

	/**
	 * Test save translation throws if string does not exist.
	 */
	public function test_save_translation_missing_string_throws(): void {
		$wpdb         = $this->createMock( wpdb::class );
		$wpdb->prefix = 'wp_';
		$wpdb->method( 'get_row' )->willReturn( null );

		$repo = new StringRepository( $wpdb );

		$this->expectException( StringNotFoundException::class );
		$repo->save_translation( 999, 'en', 'Value' );
	}
}
