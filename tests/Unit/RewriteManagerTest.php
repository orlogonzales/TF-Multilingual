<?php
/**
 * Rewrite Manager Test.
 *
 * @package TF\Multilingual\Tests\Unit
 */

declare( strict_types=1 );

namespace TF\Multilingual\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TF\Multilingual\Domain\Language\Language;
use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Domain\Language\SettingsRepository;
use TF\Multilingual\Routing\RewriteManager;

/**
 * Class RewriteManagerTest
 */
class RewriteManagerTest extends TestCase {

	/**
	 * Registry.
	 *
	 * @var LanguageRegistry
	 */
	private LanguageRegistry $registry;

	/**
	 * Rewrite manager.
	 *
	 * @var RewriteManager
	 */
	private RewriteManager $manager;

	/**
	 * Sets up test environment.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->registry = new LanguageRegistry( new SettingsRepository() );
		$this->registry->add_language( new Language( 'es', 'es_ES', 'Spanish', 'Español', true, 1 ), true );
		$this->registry->add_language( new Language( 'en', 'en_US', 'English', 'English', true, 2 ) );
		$this->registry->add_language( new Language( 'pt-br', 'pt_BR', 'Portuguese', 'Português', true, 3 ) );
		$this->registry->add_language( new Language( 'fr', 'fr_FR', 'French', 'Français', false, 4 ) ); // Inactive.

		$this->manager = new RewriteManager( $this->registry );
	}

	/**
	 * Tests get_secondary_active_languages returns only active secondary languages.
	 *
	 * @return void
	 */
	public function test_get_secondary_active_languages(): void {
		$secondary = $this->manager->get_secondary_active_languages();

		$this->assertContains( 'en', $secondary );
		$this->assertContains( 'pt-br', $secondary );
		$this->assertNotContains( 'es', $secondary ); // Default excluded.
		$this->assertNotContains( 'fr', $secondary ); // Inactive excluded.
	}

	/**
	 * Tests registers query var.
	 *
	 * @return void
	 */
	public function test_register_query_vars(): void {
		$vars = $this->manager->register_query_vars( array( 'foo', 'bar' ) );

		$this->assertContains( RewriteManager::QUERY_VAR, $vars );
	}

	/**
	 * Tests generates prefixed rewrite rules with match shifting.
	 *
	 * @return void
	 */
	public function test_generates_prefixed_rules(): void {
		$existing_rules = array(
			'rooms/?$'                  => 'index.php?post_type=rooms',
			'rooms/page/([0-9]{1,})/?$' => 'index.php?post_type=rooms&paged=$matches[1]',
			'^wp-json/?$'               => 'index.php?rest_route=/',
		);

		$new_rules = $this->manager->filter_rewrite_rules( $existing_rules );

		// Home rule for secondary languages.
		$this->assertArrayHasKey( '^(en|pt\-br)/?$', $new_rules );
		$this->assertSame( 'index.php?tfml_lang=$matches[1]', $new_rules['^(en|pt\-br)/?$'] );

		// Prefixed room rule.
		$this->assertArrayHasKey( '^(en|pt\-br)/rooms/?$', $new_rules );
		$this->assertSame( 'index.php?post_type=rooms&tfml_lang=$matches[1]', $new_rules['^(en|pt\-br)/rooms/?$'] );

		// Shifted matches.
		$this->assertArrayHasKey( '^(en|pt\-br)/rooms/page/([0-9]{1,})/?$', $new_rules );
		$this->assertSame( 'index.php?post_type=rooms&paged=$matches[2]&tfml_lang=$matches[1]', $new_rules['^(en|pt\-br)/rooms/page/([0-9]{1,})/?$'] );

		// Excluded system endpoint not prefixed.
		$this->assertArrayNotHasKey( '^(en|pt\-br)/wp-json/?$', $new_rules );
	}

	/**
	 * Tests filter_rewrite_rules returns unmodified rules when unconfigured.
	 *
	 * @return void
	 */
	public function test_unconfigured_registry_does_not_modify_rules(): void {
		$empty_registry = new LanguageRegistry( new SettingsRepository() );
		$manager        = new RewriteManager( $empty_registry );

		$existing_rules = array( 'rooms/?$' => 'index.php?post_type=rooms' );
		$filtered       = $manager->filter_rewrite_rules( $existing_rules );

		$this->assertSame( $existing_rules, $filtered );
	}
}
