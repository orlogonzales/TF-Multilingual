<?php
/**
 * SchemaManager Unit Tests.
 *
 * @package TF\Multilingual\Tests\Unit
 */

declare( strict_types=1 );

namespace TF\Multilingual\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TF\Multilingual\Infrastructure\Persistence\SchemaManager;
use wpdb;

/**
 * Class SchemaManagerTest
 */
class SchemaManagerTest extends TestCase {

	/**
	 * Tests technical schema version constant.
	 */
	public function test_schema_version_is_independent_constant(): void {
		$this->assertSame( '1.0.0', SchemaManager::SCHEMA_VERSION );
		$this->assertSame( 'tfml_schema_version', SchemaManager::OPTION_SCHEMA_VERSION );
	}

	/**
	 * Tests table name generation using custom table prefix.
	 */
	public function test_get_table_names_uses_custom_prefix(): void {
		$mock_db         = $this->createMock( wpdb::class );
		$mock_db->prefix = 'custom_site_prefix_';

		$manager = new SchemaManager( $mock_db );

		$this->assertSame( 'custom_site_prefix_tfml_groups', $manager->get_table_name( 'groups' ) );
		$this->assertSame( 'custom_site_prefix_tfml_group_elements', $manager->get_table_name( 'group_elements' ) );
		$this->assertSame( 'custom_site_prefix_tfml_media_translations', $manager->get_table_name( 'media_translations' ) );
		$this->assertSame( 'custom_site_prefix_tfml_strings', $manager->get_table_name( 'strings' ) );
		$this->assertSame( 'custom_site_prefix_tfml_string_translations', $manager->get_table_name( 'string_translations' ) );

		$all_tables = $manager->get_all_table_names();
		$this->assertCount( 5, $all_tables );
		$this->assertContains( 'custom_site_prefix_tfml_groups', $all_tables );
	}

	/**
	 * Tests DDL SQL contains all required tables, columns, and flexible fingerprint.
	 */
	public function test_get_schema_sql_contains_all_five_tables_and_flexible_fingerprint(): void {
		$mock_db         = $this->createMock( wpdb::class );
		$mock_db->prefix = 'test_pfx_';
		$mock_db->method( 'get_charset_collate' )->willReturn( 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci' );

		$manager = new SchemaManager( $mock_db );
		$sql     = $manager->get_schema_sql();

		// Verify 5 CREATE TABLE statements.
		$this->assertStringContainsString( 'CREATE TABLE test_pfx_tfml_groups', $sql );
		$this->assertStringContainsString( 'CREATE TABLE test_pfx_tfml_group_elements', $sql );
		$this->assertStringContainsString( 'CREATE TABLE test_pfx_tfml_media_translations', $sql );
		$this->assertStringContainsString( 'CREATE TABLE test_pfx_tfml_strings', $sql );
		$this->assertStringContainsString( 'CREATE TABLE test_pfx_tfml_string_translations', $sql );

		// Verify flexible fingerprint VARCHAR(128).
		$this->assertStringContainsString( 'translatable_fingerprint varchar(128)', $sql );

		// Verify dbDelta primary key double-space syntax.
		$this->assertStringContainsString( 'PRIMARY KEY  (id)', $sql );

		// Verify canonical_element_id in groups.
		$this->assertStringContainsString( 'canonical_element_id bigint(20) unsigned default NULL', $sql );

		// Verify media translations unique key.
		$this->assertStringContainsString( 'UNIQUE KEY uq_attachment_language (attachment_id,language_code)', $sql );

		// Verify term subtype column in groups.
		$this->assertStringContainsString( 'subtype varchar(32) NOT NULL default \'\'', $sql );
	}
}
