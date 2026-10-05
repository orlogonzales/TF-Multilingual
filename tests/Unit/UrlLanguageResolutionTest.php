<?php
/**
 * URL Language Resolution Value Object Test.
 *
 * @package TF\Multilingual\Tests\Unit
 */

declare( strict_types=1 );

namespace TF\Multilingual\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TF\Multilingual\Routing\UrlLanguageResolution;

/**
 * Class UrlLanguageResolutionTest
 */
class UrlLanguageResolutionTest extends TestCase {

	/**
	 * Tests active resolution.
	 *
	 * @return void
	 */
	public function test_active_resolution(): void {
		$res = UrlLanguageResolution::active( 'en', 'tours/inca-trail', true );

		$this->assertSame( UrlLanguageResolution::STATUS_ACTIVE, $res->get_status() );
		$this->assertSame( 'en', $res->get_language_code() );
		$this->assertSame( 'tours/inca-trail', $res->get_path() );
		$this->assertTrue( $res->has_explicit_prefix() );
		$this->assertTrue( $res->is_active() );
		$this->assertFalse( $res->is_inactive() );
		$this->assertFalse( $res->is_excluded() );
		$this->assertFalse( $res->is_default_inactive() );
		$this->assertTrue( $res->is_configured() );
		$this->assertTrue( $res->is_valid() );
	}

	/**
	 * Tests inactive resolution.
	 *
	 * @return void
	 */
	public function test_inactive_resolution(): void {
		$res = UrlLanguageResolution::inactive( 'fr', 'fr/tours' );

		$this->assertSame( UrlLanguageResolution::STATUS_INACTIVE, $res->get_status() );
		$this->assertSame( 'fr', $res->get_language_code() );
		$this->assertSame( 'fr/tours', $res->get_path() );
		$this->assertTrue( $res->has_explicit_prefix() );
		$this->assertFalse( $res->is_active() );
		$this->assertTrue( $res->is_inactive() );
		$this->assertFalse( $res->is_valid() );
		$this->assertTrue( $res->is_configured() );
	}

	/**
	 * Tests default inactive resolution.
	 *
	 * @return void
	 */
	public function test_default_inactive_resolution(): void {
		$res = UrlLanguageResolution::default_inactive( 'tours' );

		$this->assertSame( UrlLanguageResolution::STATUS_DEFAULT_INACTIVE, $res->get_status() );
		$this->assertNull( $res->get_language_code() );
		$this->assertFalse( $res->is_active() );
		$this->assertTrue( $res->is_default_inactive() );
		$this->assertFalse( $res->is_valid() );
	}

	/**
	 * Tests not configured resolution.
	 *
	 * @return void
	 */
	public function test_not_configured_resolution(): void {
		$res = UrlLanguageResolution::not_configured( 'tours' );

		$this->assertSame( UrlLanguageResolution::STATUS_NOT_CONFIGURED, $res->get_status() );
		$this->assertNull( $res->get_language_code() );
		$this->assertFalse( $res->is_active() );
		$this->assertTrue( $res->is_not_configured() );
		$this->assertFalse( $res->is_configured() );
		$this->assertFalse( $res->is_valid() );
	}

	/**
	 * Tests excluded resolution.
	 *
	 * @return void
	 */
	public function test_excluded_resolution(): void {
		$res = UrlLanguageResolution::excluded( 'wp-admin' );

		$this->assertSame( UrlLanguageResolution::STATUS_EXCLUDED, $res->get_status() );
		$this->assertNull( $res->get_language_code() );
		$this->assertFalse( $res->is_active() );
		$this->assertTrue( $res->is_excluded() );
		$this->assertFalse( $res->is_valid() );
	}
}
