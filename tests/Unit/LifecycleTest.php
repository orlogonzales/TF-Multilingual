<?php
/**
 * Lifecycle Unit Tests.
 *
 * @package TF\Multilingual\Tests\Unit
 */

declare( strict_types=1 );

namespace TF\Multilingual\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TF\Multilingual\Domain\Language\SettingsRepository;
use TF\Multilingual\Infrastructure\Lifecycle;
use TF\Multilingual\Infrastructure\Persistence\SchemaManager;
use TF\Multilingual\Tests\Doubles\TestableWpdb;

/**
 * Class LifecycleTest
 */
class LifecycleTest extends TestCase {

	/**
	 * Database double.
	 *
	 * @var TestableWpdb
	 */
	private TestableWpdb $db;

	/**
	 * Sets up test fixtures.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->db        = new TestableWpdb();
		$GLOBALS['wpdb'] = $this->db;
	}

	/**
	 * Tests deactivate is strictly non-destructive.
	 */
	public function test_deactivate_is_strictly_non_destructive(): void {
		update_option( 'tfml_settings', array( 'default_language' => 'es' ) );
		update_option( SchemaManager::OPTION_SCHEMA_VERSION, '1.0.0' );

		Lifecycle::deactivate();

		$this->assertNotEmpty( get_option( 'tfml_settings' ) );
		$this->assertSame( '1.0.0', get_option( SchemaManager::OPTION_SCHEMA_VERSION ) );
	}

	/**
	 * Tests uninstall retains all data by default when purge option is false or absent.
	 */
	public function test_uninstall_retains_data_by_default(): void {
		update_option( 'tfml_settings', array( 'default_language' => 'es' ) );
		update_option( SchemaManager::OPTION_SCHEMA_VERSION, '1.0.0' );
		delete_option( Lifecycle::OPTION_PURGE_ON_UNINSTALL );

		Lifecycle::uninstall();

		// Data retention rule: tables and settings remain untouched.
		$this->assertNotEmpty( get_option( 'tfml_settings' ) );
		$this->assertSame( '1.0.0', get_option( SchemaManager::OPTION_SCHEMA_VERSION ) );
	}

	/**
	 * Tests uninstall retains data when purge option is explicitly false.
	 */
	public function test_uninstall_retains_data_when_purge_is_false(): void {
		update_option( 'tfml_settings', array( 'default_language' => 'es' ) );
		update_option( SchemaManager::OPTION_SCHEMA_VERSION, '1.0.0' );
		update_option( Lifecycle::OPTION_PURGE_ON_UNINSTALL, false );

		Lifecycle::uninstall();

		$this->assertNotEmpty( get_option( 'tfml_settings' ) );
		$this->assertSame( '1.0.0', get_option( SchemaManager::OPTION_SCHEMA_VERSION ) );
		$this->assertFalse( get_option( Lifecycle::OPTION_PURGE_ON_UNINSTALL ) );
	}

	/**
	 * Tests uninstall drops tables and purges options when purge option is explicitly true.
	 */
	public function test_uninstall_purges_data_when_explicitly_configured(): void {
		update_option( 'tfml_settings', array( 'default_language' => 'es' ) );
		update_option( SchemaManager::OPTION_SCHEMA_VERSION, '1.0.0' );
		update_option( Lifecycle::OPTION_PURGE_ON_UNINSTALL, true );

		Lifecycle::uninstall();

		$this->assertNull( get_option( 'tfml_settings', null ) );
		$this->assertNull( get_option( SchemaManager::OPTION_SCHEMA_VERSION, null ) );
		$this->assertNull( get_option( Lifecycle::OPTION_PURGE_ON_UNINSTALL, null ) );
	}
}
