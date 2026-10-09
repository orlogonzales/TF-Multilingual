<?php
/**
 * REST Languages Controller Unit Tests.
 *
 * @package TF\Multilingual\Tests\Unit\Rest
 */

declare( strict_types=1 );

namespace TF\Multilingual\Tests\Unit\Rest;

use PHPUnit\Framework\TestCase;
use TF\Multilingual\Domain\Language\Language;
use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Domain\Language\SettingsRepository;
use TF\Multilingual\Rest\LanguagesController;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Class LanguagesControllerTest
 */
class LanguagesControllerTest extends TestCase {

	/**
	 * Language registry mock/instance.
	 *
	 * @var LanguageRegistry
	 */
	private LanguageRegistry $registry;

	/**
	 * Languages controller under test.
	 *
	 * @var LanguagesController
	 */
	private LanguagesController $controller;

	/**
	 * Sets up test fixtures.
	 */
	protected function setUp(): void {
		parent::setUp();

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
					'en' => array(
						'code'        => 'en',
						'locale'      => 'en_US',
						'name'        => 'English',
						'native_name' => 'English',
						'active'      => true,
						'order'       => 20,
					),
					'pt' => array(
						'code'        => 'pt',
						'locale'      => 'pt_PT',
						'name'        => 'Portuguese',
						'native_name' => 'Português',
						'active'      => false,
						'order'       => 30,
					),
				),
				'default_language' => 'es',
			)
		);

		$this->registry   = new LanguageRegistry( $repo );
		$this->controller = new LanguagesController( $this->registry );
	}

	/**
	 * Tests route registration in WordPress REST server.
	 */
	public function test_register_routes(): void {
		$GLOBALS['wp_rest_routes'] = array();
		$this->controller->register_routes();

		$this->assertArrayHasKey( 'tf-multilingual/v1', $GLOBALS['wp_rest_routes'] );
		$this->assertArrayHasKey( '/languages', $GLOBALS['wp_rest_routes']['tf-multilingual/v1'] );
	}

	/**
	 * Tests that language listing is publicly accessible.
	 */
	public function test_get_items_permissions_check_returns_true(): void {
		$request = new WP_REST_Request( 'GET', '/tf-multilingual/v1/languages' );
		$this->assertTrue( $this->controller->get_items_permissions_check( $request ) );
	}

	/**
	 * Tests get_items returns only active languages by default.
	 */
	public function test_get_items_returns_active_languages_by_default(): void {
		$request  = new WP_REST_Request( 'GET', '/tf-multilingual/v1/languages' );
		$response = $this->controller->get_items( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$data = $response->get_data();

		$this->assertIsArray( $data );
		$this->assertCount( 2, $data );

		$codes = array_column( $data, 'code' );
		$this->assertContains( 'es', $codes );
		$this->assertContains( 'en', $codes );
		$this->assertNotContains( 'pt', $codes );

		// Check first item fields.
		$es = $data[0];
		$this->assertSame( 'es', $es['code'] );
		$this->assertSame( 'es_ES', $es['locale'] );
		$this->assertSame( 'Spanish', $es['name'] );
		$this->assertSame( 'Español', $es['native_name'] );
		$this->assertTrue( $es['is_default'] );
		$this->assertTrue( $es['active'] );
		$this->assertSame( 10, $es['order'] );
	}

	/**
	 * Tests get_items returns all languages when all=true is specified.
	 */
	public function test_get_items_returns_all_languages_when_all_is_true(): void {
		$request = new WP_REST_Request( 'GET', '/tf-multilingual/v1/languages' );
		$request->set_param( 'all', true );

		$response = $this->controller->get_items( $request );
		$data     = $response->get_data();

		$this->assertCount( 3, $data );
		$codes = array_column( $data, 'code' );
		$this->assertContains( 'pt', $codes );

		$pt = array_values(
			array_filter(
				$data,
				static fn( $item ) => 'pt' === $item['code']
			)
		)[0];
		$this->assertFalse( $pt['active'] );
		$this->assertFalse( $pt['is_default'] );
	}

	/**
	 * Tests schema definition.
	 */
	public function test_get_item_schema(): void {
		$schema = $this->controller->get_item_schema();

		$this->assertSame( 'language', $schema['title'] );
		$this->assertArrayHasKey( 'code', $schema['properties'] );
		$this->assertArrayHasKey( 'locale', $schema['properties'] );
		$this->assertArrayHasKey( 'is_default', $schema['properties'] );
		$this->assertArrayHasKey( 'active', $schema['properties'] );
	}
}
