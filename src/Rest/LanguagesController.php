<?php
/**
 * REST API Languages Controller.
 *
 * @package TF\Multilingual\Rest
 */

declare( strict_types=1 );

namespace TF\Multilingual\Rest;

use TF\Multilingual\Domain\Language\Language;
use TF\Multilingual\Domain\Language\LanguageRegistry;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Class LanguagesController
 *
 * Exposes read-only REST endpoints for registered languages in TF Multilingual.
 */
class LanguagesController extends WP_REST_Controller {

	/**
	 * Route namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'tf-multilingual/v1';

	/**
	 * Route resource base.
	 *
	 * @var string
	 */
	protected $rest_base = 'languages';

	/**
	 * Item schema cache.
	 *
	 * @var array<string, mixed>|null
	 */
	protected $schema = null;

	/**
	 * Language registry domain service.
	 *
	 * @var LanguageRegistry
	 */
	private LanguageRegistry $language_registry;

	/**
	 * Constructor.
	 *
	 * @param LanguageRegistry $language_registry Language registry service.
	 */
	public function __construct( LanguageRegistry $language_registry ) {
		$this->language_registry = $language_registry;
	}

	/**
	 * Registers REST API routes for languages.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => array(
						'all' => array(
							'description'       => 'Whether to include inactive languages.',
							'type'              => 'boolean',
							'default'           => false,
							'sanitize_callback' => 'rest_sanitize_boolean',
							'validate_callback' => 'rest_validate_request_arg',
						),
					),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
	}

	/**
	 * Checks permissions for retrieving languages.
	 *
	 * Languages list is publicly readable.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool
	 */
	public function get_items_permissions_check( $request ): bool {
		return true;
	}

	/**
	 * Retrieves list of registered languages.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_items( $request ): WP_REST_Response {
		$include_all = (bool) $request->get_param( 'all' );

		if ( $include_all ) {
			$languages = $this->language_registry->all();
		} else {
			$languages = $this->language_registry->active();
		}

		$data = array();
		foreach ( $languages as $language ) {
			$item   = $this->prepare_item_for_response( $language, $request );
			$data[] = $this->prepare_response_for_collection( $item );
		}

		return rest_ensure_response( $data );
	}

	/**
	 * Prepares a single Language entity for the REST response.
	 *
	 * @param mixed           $item    Language entity.
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function prepare_item_for_response( $item, $request ): WP_REST_Response {
		if ( ! $item instanceof Language ) {
			return new WP_REST_Response( array() );
		}

		$code = $item->get_code();
		$data = array(
			'code'        => $code,
			'locale'      => $item->get_locale(),
			'name'        => $item->get_name(),
			'native_name' => $item->get_native_name(),
			'is_default'  => $this->language_registry->is_default( $code ),
			'active'      => $item->is_active(),
			'order'       => $item->get_order(),
		);

		return new WP_REST_Response( $data );
	}

	/**
	 * Prepares a response for collection output.
	 *
	 * @param WP_REST_Response $response Response object.
	 * @return array<string, mixed>
	 */
	public function prepare_response_for_collection( $response ): array {
		if ( $response instanceof WP_REST_Response ) {
			return (array) $response->get_data();
		}

		return (array) $response;
	}

	/**
	 * Retrieves the schema for language resources.
	 *
	 * @return array<string, mixed>
	 */
	public function get_item_schema(): array {
		if ( null !== $this->schema ) {
			return $this->add_additional_fields_schema( $this->schema );
		}

		$this->schema = array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'language',
			'type'       => 'object',
			'properties' => array(
				'code'        => array(
					'description' => 'Canonical ISO language code.',
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'locale'      => array(
					'description' => 'WordPress locale identifier.',
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'name'        => array(
					'description' => 'Display name in English / admin context.',
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'native_name' => array(
					'description' => 'Native endonym of the language.',
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'is_default'  => array(
					'description' => 'Whether this is the sovereign default language.',
					'type'        => 'boolean',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'active'      => array(
					'description' => 'Whether the language is active for frontend availability.',
					'type'        => 'boolean',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'order'       => array(
					'description' => 'Sort order priority.',
					'type'        => 'integer',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
			),
		);

		return $this->add_additional_fields_schema( $this->schema );
	}
}
