<?php
/**
 * Plugin Orchestrator Unit Test.
 *
 * @package TF\Multilingual\Tests\Unit
 */

declare( strict_types=1 );

namespace TF\Multilingual\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TF\Multilingual\Core\Plugin;

/**
 * Class PluginTest
 */
class PluginTest extends TestCase {

	/**
	 * Tear down after each test.
	 */
	protected function tearDown(): void {
		parent::tearDown();
		Plugin::reset_instance();
	}

	/**
	 * Tests that Plugin::get_instance returns a valid singleton.
	 */
	public function test_singleton_instance_returns_same_object(): void {
		$instance1 = Plugin::get_instance();
		$instance2 = Plugin::get_instance();

		$this->assertSame( $instance1, $instance2 );
		$this->assertInstanceOf( Plugin::class, $instance1 );
	}

	/**
	 * Tests plugin version matches expected constant.
	 */
	public function test_get_version_matches_constant(): void {
		$plugin = Plugin::get_instance();

		$this->assertSame( '0.1.0', $plugin->get_version() );
		$this->assertSame( Plugin::VERSION, $plugin->get_version() );
	}

	/**
	 * Tests requirement verification logic.
	 */
	public function test_check_requirements(): void {
		$plugin = Plugin::get_instance();

		// Satisfied versions.
		$this->assertTrue( $plugin->check_requirements( '8.1.0', '6.8.0' ) );
		$this->assertTrue( $plugin->check_requirements( '8.3.30', '7.1.2' ) );

		// Unsatisfied PHP version.
		$this->assertFalse( $plugin->check_requirements( '8.0.28', '6.8.0' ) );

		// Unsatisfied WP version.
		$this->assertFalse( $plugin->check_requirements( '8.2.0', '6.7.2' ) );
	}

	/**
	 * Tests that init changes initialization state.
	 */
	public function test_init_sets_initialized_flag(): void {
		$plugin = Plugin::get_instance();

		$this->assertFalse( $plugin->is_initialized() );
		$plugin->init();
		$this->assertTrue( $plugin->is_initialized() );
		$this->assertInstanceOf( \TF\Multilingual\Admin\AdminListColumnsUi::class, $plugin->get_admin_list_columns_ui() );
	}
}
