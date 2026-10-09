<?php
/**
 * REST API Registrar Unit Tests.
 *
 * @package TF\Multilingual\Tests\Unit\Rest
 */

declare( strict_types=1 );

namespace TF\Multilingual\Tests\Unit\Rest;

use PHPUnit\Framework\TestCase;
use TF\Multilingual\Core\Plugin;
use TF\Multilingual\Domain\CustomField\CustomFieldPolicyRegistry;
use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Domain\Language\SettingsRepository;
use TF\Multilingual\Domain\Translation\ContentTranslationResolver;
use TF\Multilingual\Domain\Translation\TranslationGroupRepository;
use TF\Multilingual\Domain\Versioning\TranslationStatusResolver;
use TF\Multilingual\Editorial\TranslationEditorialService;
use TF\Multilingual\Rest\LanguagesController;
use TF\Multilingual\Rest\RestApiRegistrar;
use TF\Multilingual\Rest\StatusController;
use TF\Multilingual\Rest\TranslationsController;
use TF\Multilingual\Tests\Doubles\TestableWpdb;

/**
 * Class RestApiRegistrarTest
 */
class RestApiRegistrarTest extends TestCase {

	/**
	 * Registrar instance.
	 *
	 * @var RestApiRegistrar
	 */
	private RestApiRegistrar $registrar;

	/**
	 * Sets up test fixtures.
	 */
	protected function setUp(): void {
		parent::setUp();

		$db              = new TestableWpdb();
		$GLOBALS['wpdb'] = $db;

		$repo = $this->createMock( SettingsRepository::class );
		$repo->method( 'load' )->willReturn(
			array(
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
				'default_language' => 'es',
			)
		);

		$registry             = new LanguageRegistry( $repo );
		$group_repo           = new TranslationGroupRepository( $db, $registry );
		$translation_resolver = new ContentTranslationResolver( $group_repo, $registry );
		$status_resolver      = new TranslationStatusResolver( $group_repo, $registry );
		$editorial_service    = new TranslationEditorialService(
			$registry,
			$group_repo,
			$translation_resolver,
			null,
			$db,
			new CustomFieldPolicyRegistry(),
			$status_resolver
		);

		$this->registrar = new RestApiRegistrar(
			$registry,
			$group_repo,
			$translation_resolver,
			$editorial_service,
			$status_resolver
		);
	}

	/**
	 * Tests getters return proper controller types.
	 */
	public function test_getters_return_controller_instances(): void {
		$this->assertInstanceOf( LanguagesController::class, $this->registrar->get_languages_controller() );
		$this->assertInstanceOf( TranslationsController::class, $this->registrar->get_translations_controller() );
		$this->assertInstanceOf( StatusController::class, $this->registrar->get_status_controller() );
	}

	/**
	 * Tests register_routes registers all endpoints.
	 */
	public function test_register_routes_registers_all_endpoints(): void {
		$GLOBALS['wp_rest_routes'] = array();
		$this->registrar->register_routes();

		$this->assertArrayHasKey( 'tf-multilingual/v1', $GLOBALS['wp_rest_routes'] );
		$routes = $GLOBALS['wp_rest_routes']['tf-multilingual/v1'];

		$this->assertArrayHasKey( '/languages', $routes );
		$this->assertArrayHasKey( '/translations', $routes );
		$this->assertArrayHasKey( '/translations/(?P<element_type>post|term)/(?P<id>[\\d]+)', $routes );
		$this->assertArrayHasKey( '/translations/link', $routes );
		$this->assertArrayHasKey( '/translations/unlink', $routes );
		$this->assertArrayHasKey( '/status/(?P<element_type>post|term)/(?P<id>[\\d]+)', $routes );
		$this->assertArrayHasKey( '/status/reviewed', $routes );
	}

	/**
	 * Tests Plugin getter returns RestApiRegistrar instance.
	 */
	public function test_plugin_getter_returns_registrar(): void {
		Plugin::reset_instance();
		$plugin = Plugin::get_instance();

		$this->assertInstanceOf( RestApiRegistrar::class, $plugin->get_rest_api_registrar() );
	}
}
