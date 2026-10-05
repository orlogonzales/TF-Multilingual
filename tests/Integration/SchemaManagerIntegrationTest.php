<?php
/**
 * SchemaManager Integration Test.
 *
 * @package TF\Multilingual\Tests\Integration
 */

declare( strict_types=1 );

namespace TF\Multilingual\Tests\Integration;

use PHPUnit\Framework\TestCase;
use TF\Multilingual\Infrastructure\Persistence\SchemaManager;

/**
 * Class SchemaManagerIntegrationTest
 */
class SchemaManagerIntegrationTest extends TestCase {

	/**
	 * Sets up test precondition.
	 */
	protected function setUp(): void {
		parent::setUp();

		if ( ! defined( 'ABSPATH' ) || ! function_exists( 'get_option' ) ) {
			$this->markTestSkipped(
				'PENDIENTE DE ENTORNO: WordPress Core Test Suite requiere bootstrap de base de datos aislado.'
			);
		}
	}

	/**
	 * Tests database schema installation against live database when WordPress Core is loaded.
	 */
	public function test_schema_installation_and_idempotency(): void {
		$manager = new SchemaManager();

		// 1. Execute installation.
		$installed = $manager->install();
		$this->assertTrue( $installed );

		// 2. Verify all 5 tables physically exist.
		$this->assertTrue( $manager->verify_tables() );
		$this->assertEmpty( $manager->get_missing_tables() );

		// 3. Verify schema version option.
		$version = $manager->get_installed_schema_version();
		$this->assertSame( SchemaManager::SCHEMA_VERSION, $version );
		$this->assertSame( '1.0.0', $version );

		// 4. Test idempotency: re-executing install() must succeed without error.
		$reinstalled = $manager->install();
		$this->assertTrue( $reinstalled );
		$this->assertTrue( $manager->verify_tables() );
		$this->assertSame( SchemaManager::SCHEMA_VERSION, $manager->get_installed_schema_version() );

		// 5. Verify table columns in tfml_group_elements.
		$columns = $manager->get_table_columns( 'group_elements' );
		$this->assertArrayHasKey( 'translatable_fingerprint', $columns );
		$this->assertArrayHasKey( 'current_content_version', $columns );
		$this->assertArrayHasKey( 'source_version_at_translation', $columns );
		$this->assertArrayHasKey( 'language_code', $columns );

		// 6. Verify table columns in tfml_media_translations.
		$media_columns = $manager->get_table_columns( 'media_translations' );
		$this->assertArrayHasKey( 'attachment_id', $media_columns );
		$this->assertArrayHasKey( 'language_code', $media_columns );
		$this->assertArrayHasKey( 'alt_text', $media_columns );
	}
}
