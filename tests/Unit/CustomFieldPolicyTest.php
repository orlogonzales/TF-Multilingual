<?php
/**
 * CustomFieldPolicy Unit Tests.
 *
 * @package TF\Multilingual\Tests\Unit
 */

declare( strict_types=1 );

namespace TF\Multilingual\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use TF\Multilingual\Domain\CustomField\CustomFieldPolicy;

/**
 * Class CustomFieldPolicyTest
 */
class CustomFieldPolicyTest extends TestCase {

	/**
	 * Tests all policies array contains expected values.
	 */
	public function test_all_returns_three_policies(): void {
		$all = CustomFieldPolicy::all();

		$this->assertCount( 3, $all );
		$this->assertContains( CustomFieldPolicy::TRANSLATE, $all );
		$this->assertContains( CustomFieldPolicy::SHARE, $all );
		$this->assertContains( CustomFieldPolicy::IGNORE, $all );
	}

	/**
	 * Tests is_valid for valid values.
	 *
	 * @dataProvider valid_policies_provider
	 */
	public function test_is_valid_returns_true( string $policy ): void {
		$this->assertTrue( CustomFieldPolicy::is_valid( $policy ) );
	}

	/**
	 * Data provider for valid policies.
	 *
	 * @return array<string, array<string>>
	 */
	public static function valid_policies_provider(): array {
		return array(
			'translate'           => array( 'translate' ),
			'share'               => array( 'share' ),
			'ignore'              => array( 'ignore' ),
			'uppercase_translate' => array( 'TRANSLATE' ),
			'uppercase_share'     => array( 'SHARE' ),
			'uppercase_ignore'    => array( 'IGNORE' ),
			'with_whitespace'     => array( '  share  ' ),
		);
	}

	/**
	 * Tests is_valid for invalid values.
	 *
	 * @dataProvider invalid_policies_provider
	 */
	public function test_is_valid_returns_false( string $policy ): void {
		$this->assertFalse( CustomFieldPolicy::is_valid( $policy ) );
	}

	/**
	 * Data provider for invalid policies.
	 *
	 * @return array<string, array<string>>
	 */
	public static function invalid_policies_provider(): array {
		return array(
			'empty'      => array( '' ),
			'whitespace' => array( '   ' ),
			'copy'       => array( 'copy' ),
			'sync'       => array( 'sync' ),
			'arbitrary'  => array( 'foobar' ),
		);
	}

	/**
	 * Tests normalize returns lowercase policy string.
	 */
	public function test_normalize_returns_lowercase_trimmed(): void {
		$this->assertSame( 'share', CustomFieldPolicy::normalize( ' SHARE ' ) );
		$this->assertSame( 'translate', CustomFieldPolicy::normalize( 'Translate' ) );
		$this->assertSame( 'ignore', CustomFieldPolicy::normalize( 'IGNORE' ) );
	}

	/**
	 * Tests normalize throws exception on invalid policy.
	 */
	public function test_normalize_throws_exception_on_invalid(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Invalid custom field policy "unsupported"' );

		CustomFieldPolicy::normalize( 'unsupported' );
	}

	/**
	 * Tests default policy is IGNORE.
	 */
	public function test_default_is_ignore(): void {
		$this->assertSame( CustomFieldPolicy::IGNORE, CustomFieldPolicy::default() );
	}
}
