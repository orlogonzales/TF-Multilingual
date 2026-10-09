<?php
/**
 * REST API Subsystem Registrar.
 *
 * @package TF\Multilingual\Rest
 */

declare( strict_types=1 );

namespace TF\Multilingual\Rest;

use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Domain\Translation\ContentTranslationResolver;
use TF\Multilingual\Domain\Translation\TranslationGroupRepository;
use TF\Multilingual\Domain\Versioning\TranslationStatusResolver;
use TF\Multilingual\Editorial\TranslationEditorialService;

/**
 * Class RestApiRegistrar
 *
 * Coordinates initialization and registration of all TF Multilingual REST API controllers.
 */
class RestApiRegistrar {

	/**
	 * Language registry domain service.
	 *
	 * @var LanguageRegistry
	 */
	private LanguageRegistry $language_registry;

	/**
	 * Translation group repository.
	 *
	 * @var TranslationGroupRepository
	 */
	private TranslationGroupRepository $group_repository;

	/**
	 * Content translation resolver domain service.
	 *
	 * @var ContentTranslationResolver
	 */
	private ContentTranslationResolver $translation_resolver;

	/**
	 * Translation editorial application service.
	 *
	 * @var TranslationEditorialService
	 */
	private TranslationEditorialService $editorial_service;

	/**
	 * Translation status resolver domain service.
	 *
	 * @var TranslationStatusResolver
	 */
	private TranslationStatusResolver $status_resolver;

	/**
	 * Languages controller.
	 *
	 * @var LanguagesController
	 */
	private LanguagesController $languages_controller;

	/**
	 * Translations controller.
	 *
	 * @var TranslationsController
	 */
	private TranslationsController $translations_controller;

	/**
	 * Status controller.
	 *
	 * @var StatusController
	 */
	private StatusController $status_controller;

	/**
	 * Constructor.
	 *
	 * @param LanguageRegistry            $language_registry    Language registry service.
	 * @param TranslationGroupRepository   $group_repository     Translation group repository.
	 * @param ContentTranslationResolver  $translation_resolver Content translation resolver.
	 * @param TranslationEditorialService $editorial_service    Editorial application service.
	 * @param TranslationStatusResolver   $status_resolver       Translation status resolver.
	 */
	public function __construct(
		LanguageRegistry $language_registry,
		TranslationGroupRepository $group_repository,
		ContentTranslationResolver $translation_resolver,
		TranslationEditorialService $editorial_service,
		TranslationStatusResolver $status_resolver
	) {
		$this->language_registry    = $language_registry;
		$this->group_repository     = $group_repository;
		$this->translation_resolver = $translation_resolver;
		$this->editorial_service    = $editorial_service;
		$this->status_resolver      = $status_resolver;

		$this->languages_controller    = new LanguagesController( $this->language_registry );
		$this->translations_controller = new TranslationsController(
			$this->language_registry,
			$this->group_repository,
			$this->translation_resolver,
			$this->editorial_service,
			$this->status_resolver
		);
		$this->status_controller       = new StatusController(
			$this->language_registry,
			$this->group_repository,
			$this->status_resolver,
			$this->editorial_service
		);
	}

	/**
	 * Hooks into WordPress rest_api_init action.
	 *
	 * @return void
	 */
	public function init_hooks(): void {
		if ( function_exists( 'add_action' ) ) {
			add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		}
	}

	/**
	 * Registers routes across all REST controllers.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		$this->languages_controller->register_routes();
		$this->translations_controller->register_routes();
		$this->status_controller->register_routes();
	}

	/**
	 * Gets the Languages controller instance.
	 *
	 * @return LanguagesController
	 */
	public function get_languages_controller(): LanguagesController {
		return $this->languages_controller;
	}

	/**
	 * Gets the Translations controller instance.
	 *
	 * @return TranslationsController
	 */
	public function get_translations_controller(): TranslationsController {
		return $this->translations_controller;
	}

	/**
	 * Gets the Status controller instance.
	 *
	 * @return StatusController
	 */
	public function get_status_controller(): StatusController {
		return $this->status_controller;
	}
}
