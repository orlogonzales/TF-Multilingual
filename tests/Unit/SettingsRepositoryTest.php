<?php
/**
 * SettingsRepository Unit Tests.
 *
 * @package TF\Multilingual\Tests\Unit
 */

declare( strict_types=1 );

namespace TF\Multilingual\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TF\Multilingual\Domain\Language\SettingsRepository;

/**
 * Class SettingsRepositoryTest
 */
class SettingsRepositoryTest extends TestCase {

	/**
	 * Instance under test.
	 *
	 * @var SettingsRepository
	 */
	private SettingsRepository $repository;

	/**
	 * Setup before each test.
	 */
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['wp_test_options'] = array();
		$this->repository           = new SettingsRepository();
	}

	/**
	 * Tear down after each test.
	 */
	protected function tearDown(): void {
		parent::tearDown();
		$GLOBALS['wp_test_options'] = array();
	}

	/**
	 * Tests load on empty database returns canonical default structure.
	 */
	public function test_load_empty_returns_canonical_structure(): void {
		$settings = $this->repository->load();

		$this->assertSame(
			array(
				'default_language' => null,
				'languages'        => array(),
				'custom_fields'    => array(
					'policies' => array(),
				),
			),
			$settings
		);
	}

	/**
	 * Tests save and load roundtrip.
	 */
	public function test_save_and_load(): void {
		$payload = array(
			'default_language' => 'es',
			'languages'        => array(
				'es' => array(
					'code'        => 'es',
					'locale'      => 'es_ES',
					'name'        => 'Spanish',
					'native_name' => 'Español',
					'active'      => true,
					'order'       => 10,
				),
			),
		);

		$saved = $this->repository->save( $payload );
		$this->assertTrue( $saved );

		$loaded = $this->repository->load();
		$this->assertSame( 'es', $loaded['default_language'] );
		$this->assertArrayHasKey( 'es', $loaded['languages'] );
		$this->assertSame( 'es_ES', $loaded['languages']['es']['locale'] );
	}

	/**
	 * Tests corruption recovery: non-array value returns empty structure.
	 *
	 * @dataProvider corrupted_values_provider
	 */
	public function test_load_corrupted_payload_recovers_gracefully( mixed $corrupted ): void {
		$GLOBALS['wp_test_options'][ SettingsRepository::OPTION_NAME ] = $corrupted;

		$settings = $this->repository->load();

		$this->assertNull( $settings['default_language'] );
		$this->assertSame( array(), $settings['languages'] );
		$this->assertSame( array( 'policies' => array() ), $settings['custom_fields'] );
	}

	/**
	 * Data provider of corrupted options values.
	 *
	 * @return array<string, array<mixed>>
	 */
	public static function corrupted_values_provider(): array {
		return array(
			'string'  => array( 'invalid serialized junk' ),
			'integer' => array( 12345 ),
			'boolean' => array( false ),
			'null'    => array( null ),
		);
	}

	/**
	 * Tests payload sanitization filters out malformed language entries.
	 */
	public function test_sanitize_payload_filters_invalid_entries(): void {
		$malformed = array(
			'default_language' => 'ES',
			'languages'        => array(
				'es'      => array( 'name' => 'Spanish' ),
				'invalid' => 'not an array',
				''        => array( 'empty key' ),
				'pt_br'   => array( 'name' => 'Portuguese' ),
			),
		);

		$sanitized = $this->repository->sanitize_payload( $malformed );

		$this->assertSame( 'es', $sanitized['default_language'] );
		$this->assertArrayHasKey( 'es', $sanitized['languages'] );
		$this->assertArrayHasKey( 'pt-br', $sanitized['languages'] ); // Key normalized.
		$this->assertArrayNotHasKey( 'invalid', $sanitized['languages'] );
		$this->assertArrayNotHasKey( '', $sanitized['languages'] );
	}

	/**
	 * Tests payload sanitization filters out invalid custom field policies.
	 */
	public function test_sanitize_payload_custom_fields(): void {
		$payload = array(
			'custom_fields' => array(
				'policies' => array(
					'_price'       => 'share',
					'description'  => 'TRANSLATE',
					'tracking_id'  => 'ignore',
					'invalid_prop' => 'unknown_policy',
					''             => 'share',
				),
			),
		);

		$sanitized = $this->repository->sanitize_payload( $payload );

		$this->assertArrayHasKey( '_price', $sanitized['custom_fields']['policies'] );
		$this->assertSame( 'share', $sanitized['custom_fields']['policies']['_price'] );
		$this->assertSame( 'translate', $sanitized['custom_fields']['policies']['description'] );
		$this->assertSame( 'ignore', $sanitized['custom_fields']['policies']['tracking_id'] );
		$this->assertArrayNotHasKey( 'invalid_prop', $sanitized['custom_fields']['policies'] );
		$this->assertArrayNotHasKey( '', $sanitized['custom_fields']['policies'] );
	}

	/**
	 * Tests delete removes option.
	 */
	public function test_delete(): void {
		$this->repository->save(
			array(
				'default_language' => 'es',
				'languages'        => array(),
			)
		);

		$this->assertTrue( $this->repository->delete() );
		$this->assertNull( $this->repository->get_raw() );
	}
}
