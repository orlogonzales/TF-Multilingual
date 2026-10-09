<?php
/**
 * Main Plugin Orchestrator.
 *
 * @package TF\Multilingual\Core
 */

declare( strict_types=1 );

namespace TF\Multilingual\Core;

use TF\Multilingual\Admin\AdminListColumnsUi;
use TF\Multilingual\Admin\CustomFieldsSettingsUi;
use TF\Multilingual\Admin\MediaEditorialUi;
use TF\Multilingual\Admin\NavMenuEditorialUi;
use TF\Multilingual\Admin\PostEditorialUi;
use TF\Multilingual\Admin\StringEditorialUi;
use TF\Multilingual\Admin\TermEditorialUi;
use TF\Multilingual\Domain\CustomField\CustomFieldPolicyRegistry;
use TF\Multilingual\Domain\CustomField\SharedMetaSynchronizer;
use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Domain\Language\SettingsRepository;
use TF\Multilingual\Domain\Media\MediaFrontendFilter;
use TF\Multilingual\Domain\Media\MediaTranslationRepository;
use TF\Multilingual\Domain\Media\MediaTranslationResolver;
use TF\Multilingual\Domain\Navigation\BlockNavigationFrontendFilter;
use TF\Multilingual\Domain\Navigation\NavMenuFrontendFilter;
use TF\Multilingual\Domain\Navigation\NavMenuLocationRepository;
use TF\Multilingual\Domain\Seo\CanonicalUrlManager;
use TF\Multilingual\Domain\Seo\CoreSitemapsFilter;
use TF\Multilingual\Domain\Seo\HreflangGenerator;
use TF\Multilingual\Domain\Seo\SeoFrontendFilter;
use TF\Multilingual\Domain\Strings\StringRepository;
use TF\Multilingual\Domain\Strings\StringTranslationService;
use TF\Multilingual\Domain\Translation\ContentTranslationResolver;
use TF\Multilingual\Domain\Translation\TranslationGroupRepository;
use TF\Multilingual\Editorial\TranslationEditorialService;
use TF\Multilingual\Integration\IntegrationManager;
use TF\Multilingual\Query\QueryLanguageFilter;
use TF\Multilingual\Query\TermQueryLanguageFilter;
use TF\Multilingual\Rest\RestApiRegistrar;
use TF\Multilingual\Routing\CurrentLanguageResolver;
use TF\Multilingual\Routing\LocalizedUrlGenerator;
use TF\Multilingual\Routing\RewriteManager;
use TF\Multilingual\Routing\UrlLanguageResolver;

/**
 * Class Plugin
 *
 * Coordinates initialization and lifecycle verification for TF Multilingual.
 */
class Plugin {

	/**
	 * Plugin technical version.
	 */
	public const VERSION = '0.1.0';

	/**
	 * Minimum supported PHP version.
	 */
	public const MIN_PHP_VERSION = '8.1';

	/**
	 * Minimum supported WordPress version.
	 */
	public const MIN_WP_VERSION = '6.8';

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Initialization state flag.
	 *
	 * @var bool
	 */
	private bool $initialized = false;

	/**
	 * Language registry.
	 *
	 * @var LanguageRegistry|null
	 */
	private ?LanguageRegistry $language_registry = null;

	/**
	 * Current language resolver.
	 *
	 * @var CurrentLanguageResolver|null
	 */
	private ?CurrentLanguageResolver $current_language_resolver = null;

	/**
	 * Rewrite manager.
	 *
	 * @var RewriteManager|null
	 */
	private ?RewriteManager $rewrite_manager = null;

	/**
	 * Query language filter.
	 *
	 * @var QueryLanguageFilter|null
	 */
	private ?QueryLanguageFilter $query_filter = null;

	/**
	 * Term query language filter.
	 *
	 * @var TermQueryLanguageFilter|null
	 */
	private ?TermQueryLanguageFilter $term_query_filter = null;

	/**
	 * Translation editorial service.
	 *
	 * @var TranslationEditorialService|null
	 */
	private ?TranslationEditorialService $editorial_service = null;

	/**
	 * Post editorial UI component.
	 *
	 * @var PostEditorialUi|null
	 */
	private ?PostEditorialUi $post_editorial_ui = null;

	/**
	 * Term editorial UI component.
	 *
	 * @var TermEditorialUi|null
	 */
	private ?TermEditorialUi $term_editorial_ui = null;

	/**
	 * Admin list columns UI component.
	 *
	 * @var AdminListColumnsUi|null
	 */
	private ?AdminListColumnsUi $admin_list_columns_ui = null;

	/**
	 * Custom field policy registry.
	 *
	 * @var CustomFieldPolicyRegistry|null
	 */
	private ?CustomFieldPolicyRegistry $custom_field_policy_registry = null;

	/**
	 * Shared meta synchronizer.
	 *
	 * @var SharedMetaSynchronizer|null
	 */
	private ?SharedMetaSynchronizer $shared_meta_synchronizer = null;

	/**
	 * Custom fields settings UI component.
	 *
	 * @var CustomFieldsSettingsUi|null
	 */
	private ?CustomFieldsSettingsUi $custom_fields_settings_ui = null;

	/**
	 * Third-party integrations manager.
	 *
	 * @var IntegrationManager|null
	 */
	private ?IntegrationManager $integration_manager = null;

	/**
	 * Media translation repository.
	 *
	 * @var MediaTranslationRepository|null
	 */
	private ?MediaTranslationRepository $media_repository = null;

	/**
	 * Media translation resolver.
	 *
	 * @var MediaTranslationResolver|null
	 */
	private ?MediaTranslationResolver $media_resolver = null;

	/**
	 * Media frontend filter.
	 *
	 * @var MediaFrontendFilter|null
	 */
	private ?MediaFrontendFilter $media_frontend_filter = null;

	/**
	 * Media editorial UI component.
	 *
	 * @var MediaEditorialUi|null
	 */
	private ?MediaEditorialUi $media_editorial_ui = null;

	/**
	 * Nav menu location repository.
	 *
	 * @var NavMenuLocationRepository|null
	 */
	private ?NavMenuLocationRepository $nav_menu_location_repository = null;

	/**
	 * Nav menu frontend filter.
	 *
	 * @var NavMenuFrontendFilter|null
	 */
	private ?NavMenuFrontendFilter $nav_menu_frontend_filter = null;

	/**
	 * Block navigation frontend filter.
	 *
	 * @var BlockNavigationFrontendFilter|null
	 */
	private ?BlockNavigationFrontendFilter $block_navigation_frontend_filter = null;

	/**
	 * Nav menu editorial UI component.
	 *
	 * @var NavMenuEditorialUi|null
	 */
	private ?NavMenuEditorialUi $nav_menu_editorial_ui = null;

	/**
	 * String repository.
	 *
	 * @var StringRepository|null
	 */
	private ?StringRepository $string_repository = null;

	/**
	 * String translation service.
	 *
	 * @var StringTranslationService|null
	 */
	private ?StringTranslationService $string_translation_service = null;

	/**
	 * String editorial UI component.
	 *
	 * @var StringEditorialUi|null
	 */
	private ?StringEditorialUi $string_editorial_ui = null;

	/**
	 * Hreflang generator.
	 *
	 * @var HreflangGenerator|null
	 */
	private ?HreflangGenerator $hreflang_generator = null;

	/**
	 * Canonical URL manager.
	 *
	 * @var CanonicalUrlManager|null
	 */
	private ?CanonicalUrlManager $canonical_url_manager = null;

	/**
	 * Core sitemaps filter.
	 *
	 * @var CoreSitemapsFilter|null
	 */
	private ?CoreSitemapsFilter $core_sitemaps_filter = null;

	/**
	 * SEO frontend filter.
	 *
	 * @var SeoFrontendFilter|null
	 */
	private ?SeoFrontendFilter $seo_frontend_filter = null;

	/**
	 * REST API registrar.
	 *
	 * @var RestApiRegistrar|null
	 */
	private ?RestApiRegistrar $rest_api_registrar = null;

	/**
	 * Retrieves the singleton instance.
	 *
	 * @return self
	 */
	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Resets the singleton instance (primarily for testing purposes).
	 */
	public static function reset_instance(): void {
		self::$instance = null;
	}

	/**
	 * Protected constructor to prevent direct instantiation.
	 */
	protected function __construct() {
		// Constructor intentionally left minimal in Phase 1.0.
	}

	/**
	 * Initializes the plugin subsystems.
	 *
	 * @return void
	 */
	public function init(): void {
		if ( $this->initialized ) {
			return;
		}

		$this->language_registry         = new LanguageRegistry();
		$url_resolver                    = new UrlLanguageResolver( $this->language_registry );
		$this->current_language_resolver = new CurrentLanguageResolver( $url_resolver );
		$this->rewrite_manager           = new RewriteManager(
			$this->language_registry,
			$this->current_language_resolver
		);
		$this->query_filter              = new QueryLanguageFilter(
			$this->language_registry,
			$this->current_language_resolver
		);
		$this->term_query_filter         = new TermQueryLanguageFilter(
			$this->language_registry,
			$this->current_language_resolver
		);

		$group_repo                         = new TranslationGroupRepository();
		$translation_resolver               = new ContentTranslationResolver( $group_repo, $this->language_registry );
		$this->custom_field_policy_registry = new CustomFieldPolicyRegistry();
		$this->editorial_service            = new TranslationEditorialService(
			$this->language_registry,
			$group_repo,
			$translation_resolver,
			null,
			null,
			$this->custom_field_policy_registry
		);
		$this->shared_meta_synchronizer     = new SharedMetaSynchronizer(
			$this->custom_field_policy_registry,
			$translation_resolver
		);
		$this->custom_fields_settings_ui    = new CustomFieldsSettingsUi(
			$this->custom_field_policy_registry
		);
		$this->post_editorial_ui            = new PostEditorialUi(
			$this->editorial_service,
			$this->language_registry
		);
		$this->term_editorial_ui            = new TermEditorialUi(
			$this->editorial_service,
			$this->language_registry
		);
		$this->admin_list_columns_ui        = new AdminListColumnsUi(
			$this->language_registry,
			$this->editorial_service
		);
		$this->integration_manager          = new IntegrationManager(
			$this->custom_field_policy_registry
		);
		$this->media_repository             = new MediaTranslationRepository(
			null,
			$this->language_registry
		);
		$this->media_resolver               = new MediaTranslationResolver(
			$this->media_repository,
			$this->language_registry
		);
		$this->media_frontend_filter        = new MediaFrontendFilter(
			$this->media_resolver,
			$this->current_language_resolver,
			$this->media_repository
		);
		$this->media_editorial_ui           = new MediaEditorialUi(
			$this->media_repository,
			$this->media_resolver,
			$this->language_registry
		);

		$settings_repository                    = new SettingsRepository();
		$this->nav_menu_location_repository     = new NavMenuLocationRepository(
			$settings_repository,
			$this->language_registry,
			$translation_resolver
		);
		$url_generator                          = new LocalizedUrlGenerator(
			$this->language_registry,
			$url_resolver,
			$translation_resolver
		);
		$this->nav_menu_frontend_filter         = new NavMenuFrontendFilter(
			$this->nav_menu_location_repository,
			$this->current_language_resolver,
			$this->language_registry,
			$translation_resolver,
			$url_generator
		);
		$this->block_navigation_frontend_filter = new BlockNavigationFrontendFilter(
			$this->current_language_resolver,
			$this->language_registry,
			$translation_resolver
		);
		$this->nav_menu_editorial_ui            = new NavMenuEditorialUi(
			$this->nav_menu_location_repository,
			$this->language_registry,
			$translation_resolver,
			$group_repo
		);
		$this->string_repository                = new StringRepository();
		$this->string_translation_service       = new StringTranslationService(
			$this->string_repository,
			$this->language_registry,
			$this->current_language_resolver
		);
		$this->string_editorial_ui              = new StringEditorialUi(
			$this->string_repository,
			$this->language_registry
		);
		$this->hreflang_generator               = new HreflangGenerator(
			$this->language_registry,
			$group_repo,
			$translation_resolver,
			$url_generator
		);
		$this->canonical_url_manager            = new CanonicalUrlManager(
			$this->language_registry,
			$translation_resolver,
			$url_generator,
			$url_resolver
		);
		$this->core_sitemaps_filter             = new CoreSitemapsFilter(
			$this->language_registry,
			$translation_resolver,
			$url_generator
		);
		$this->seo_frontend_filter              = new SeoFrontendFilter(
			$this->hreflang_generator,
			$this->canonical_url_manager,
			$this->core_sitemaps_filter
		);
		$this->rest_api_registrar               = new RestApiRegistrar(
			$this->language_registry,
			$group_repo,
			$translation_resolver,
			$this->editorial_service,
			$this->editorial_service->get_status_resolver()
		);

		$this->rewrite_manager->init_hooks();
		$this->query_filter->init_hooks();
		$this->term_query_filter->init_hooks();
		$this->shared_meta_synchronizer->init_hooks();
		$this->editorial_service->init_hooks();
		$this->post_editorial_ui->init_hooks();
		$this->term_editorial_ui->init_hooks();
		$this->admin_list_columns_ui->register_hooks();
		$this->custom_fields_settings_ui->register_hooks();
		$this->integration_manager->init();
		$this->media_frontend_filter->init_hooks();
		$this->media_editorial_ui->register_hooks();
		$this->nav_menu_frontend_filter->init_hooks();
		$this->block_navigation_frontend_filter->init_hooks();
		$this->nav_menu_editorial_ui->init_hooks();
		$this->string_editorial_ui->init_hooks();
		$this->seo_frontend_filter->init_hooks();
		$this->rest_api_registrar->init_hooks();
		if ( function_exists( 'add_action' ) ) {
			add_action( 'shutdown', array( $this->string_translation_service, 'flush_observed_strings' ) );
		}

		$this->initialized = true;
	}

	/**
	 * Gets the language registry instance.
	 *
	 * @return LanguageRegistry
	 */
	public function get_language_registry(): LanguageRegistry {
		if ( null === $this->language_registry ) {
			$this->language_registry = new LanguageRegistry();
		}

		return $this->language_registry;
	}

	/**
	 * Gets the current language resolver instance.
	 *
	 * @return CurrentLanguageResolver
	 */
	public function get_current_language_resolver(): CurrentLanguageResolver {
		if ( null === $this->current_language_resolver ) {
			$url_resolver                    = new UrlLanguageResolver( $this->get_language_registry() );
			$this->current_language_resolver = new CurrentLanguageResolver( $url_resolver );
		}

		return $this->current_language_resolver;
	}

	/**
	 * Gets the query language filter instance.
	 *
	 * @return QueryLanguageFilter
	 */
	public function get_query_filter(): QueryLanguageFilter {
		if ( null === $this->query_filter ) {
			$this->query_filter = new QueryLanguageFilter(
				$this->get_language_registry(),
				$this->get_current_language_resolver()
			);
		}

		return $this->query_filter;
	}

	/**
	 * Gets the term query language filter instance.
	 *
	 * @return TermQueryLanguageFilter
	 */
	public function get_term_query_filter(): TermQueryLanguageFilter {
		if ( null === $this->term_query_filter ) {
			$this->term_query_filter = new TermQueryLanguageFilter(
				$this->get_language_registry(),
				$this->get_current_language_resolver()
			);
		}

		return $this->term_query_filter;
	}

	/**
	 * Gets the translation editorial service instance.
	 *
	 * @return TranslationEditorialService
	 */
	public function get_editorial_service(): TranslationEditorialService {
		if ( null === $this->editorial_service ) {
			$group_repo              = new TranslationGroupRepository();
			$translation_resolver    = new ContentTranslationResolver( $group_repo, $this->get_language_registry() );
			$this->editorial_service = new TranslationEditorialService(
				$this->get_language_registry(),
				$group_repo,
				$translation_resolver
			);
		}

		return $this->editorial_service;
	}

	/**
	 * Gets the post editorial UI component instance.
	 *
	 * @return PostEditorialUi
	 */
	public function get_post_editorial_ui(): PostEditorialUi {
		if ( null === $this->post_editorial_ui ) {
			$this->post_editorial_ui = new PostEditorialUi(
				$this->get_editorial_service(),
				$this->get_language_registry()
			);
		}

		return $this->post_editorial_ui;
	}

	/**
	 * Gets the term editorial UI component instance.
	 *
	 * @return TermEditorialUi
	 */
	public function get_term_editorial_ui(): TermEditorialUi {
		if ( null === $this->term_editorial_ui ) {
			$this->term_editorial_ui = new TermEditorialUi(
				$this->get_editorial_service(),
				$this->get_language_registry()
			);
		}

		return $this->term_editorial_ui;
	}

	/**
	 * Gets the admin list columns UI instance.
	 *
	 * @return AdminListColumnsUi
	 */
	public function get_admin_list_columns_ui(): AdminListColumnsUi {
		if ( null === $this->admin_list_columns_ui ) {
			$this->admin_list_columns_ui = new AdminListColumnsUi(
				$this->get_language_registry(),
				$this->get_editorial_service()
			);
		}

		return $this->admin_list_columns_ui;
	}

	/**
	 * Gets the custom field policy registry instance.
	 *
	 * @return CustomFieldPolicyRegistry
	 */
	public function get_custom_field_policy_registry(): CustomFieldPolicyRegistry {
		if ( null === $this->custom_field_policy_registry ) {
			$this->custom_field_policy_registry = new CustomFieldPolicyRegistry();
		}

		return $this->custom_field_policy_registry;
	}

	/**
	 * Gets the shared meta synchronizer instance.
	 *
	 * @return SharedMetaSynchronizer
	 */
	public function get_shared_meta_synchronizer(): SharedMetaSynchronizer {
		if ( null === $this->shared_meta_synchronizer ) {
			$group_repo                     = new TranslationGroupRepository();
			$translation_resolver           = new ContentTranslationResolver( $group_repo, $this->get_language_registry() );
			$this->shared_meta_synchronizer = new SharedMetaSynchronizer(
				$this->get_custom_field_policy_registry(),
				$translation_resolver
			);
		}

		return $this->shared_meta_synchronizer;
	}

	/**
	 * Gets the custom fields settings UI instance.
	 *
	 * @return CustomFieldsSettingsUi
	 */
	public function get_custom_fields_settings_ui(): CustomFieldsSettingsUi {
		if ( null === $this->custom_fields_settings_ui ) {
			$this->custom_fields_settings_ui = new CustomFieldsSettingsUi(
				$this->get_custom_field_policy_registry()
			);
		}

		return $this->custom_fields_settings_ui;
	}

	/**
	 * Gets the integration manager instance.
	 *
	 * @return IntegrationManager
	 */
	public function get_integration_manager(): IntegrationManager {
		if ( null === $this->integration_manager ) {
			$this->integration_manager = new IntegrationManager(
				$this->get_custom_field_policy_registry()
			);
		}

		return $this->integration_manager;
	}

	/**
	 * Gets the media translation repository instance.
	 *
	 * @return MediaTranslationRepository
	 */
	public function get_media_repository(): MediaTranslationRepository {
		if ( null === $this->media_repository ) {
			$this->media_repository = new MediaTranslationRepository(
				null,
				$this->get_language_registry()
			);
		}

		return $this->media_repository;
	}

	/**
	 * Gets the media translation resolver instance.
	 *
	 * @return MediaTranslationResolver
	 */
	public function get_media_resolver(): MediaTranslationResolver {
		if ( null === $this->media_resolver ) {
			$this->media_resolver = new MediaTranslationResolver(
				$this->get_media_repository(),
				$this->get_language_registry()
			);
		}

		return $this->media_resolver;
	}

	/**
	 * Gets the media frontend filter instance.
	 *
	 * @return MediaFrontendFilter
	 */
	public function get_media_frontend_filter(): MediaFrontendFilter {
		if ( null === $this->media_frontend_filter ) {
			$this->media_frontend_filter = new MediaFrontendFilter(
				$this->get_media_resolver(),
				$this->get_current_language_resolver(),
				$this->get_media_repository()
			);
		}

		return $this->media_frontend_filter;
	}

	/**
	 * Gets the media editorial UI instance.
	 *
	 * @return MediaEditorialUi
	 */
	public function get_media_editorial_ui(): MediaEditorialUi {
		if ( null === $this->media_editorial_ui ) {
			$this->media_editorial_ui = new MediaEditorialUi(
				$this->get_media_repository(),
				$this->get_media_resolver(),
				$this->get_language_registry()
			);
		}

		return $this->media_editorial_ui;
	}

	/**
	 * Gets the nav menu location repository instance.
	 *
	 * @return NavMenuLocationRepository
	 */
	public function get_nav_menu_location_repository(): NavMenuLocationRepository {
		if ( null === $this->nav_menu_location_repository ) {
			$this->nav_menu_location_repository = new NavMenuLocationRepository(
				new SettingsRepository(),
				$this->get_language_registry()
			);
		}

		return $this->nav_menu_location_repository;
	}

	/**
	 * Gets the nav menu frontend filter instance.
	 *
	 * @return NavMenuFrontendFilter
	 */
	public function get_nav_menu_frontend_filter(): NavMenuFrontendFilter {
		if ( null === $this->nav_menu_frontend_filter ) {
			$group_repo           = new TranslationGroupRepository();
			$translation_resolver = new ContentTranslationResolver( $group_repo, $this->get_language_registry() );
			$url_resolver         = new UrlLanguageResolver( $this->get_language_registry() );
			$url_generator        = new LocalizedUrlGenerator(
				$this->get_language_registry(),
				$url_resolver,
				$translation_resolver
			);

			$this->nav_menu_frontend_filter = new NavMenuFrontendFilter(
				$this->get_nav_menu_location_repository(),
				$this->get_current_language_resolver(),
				$this->get_language_registry(),
				$translation_resolver,
				$url_generator
			);
		}

		return $this->nav_menu_frontend_filter;
	}

	/**
	 * Gets the block navigation frontend filter instance.
	 *
	 * @return BlockNavigationFrontendFilter
	 */
	public function get_block_navigation_frontend_filter(): BlockNavigationFrontendFilter {
		if ( null === $this->block_navigation_frontend_filter ) {
			$group_repo           = new TranslationGroupRepository();
			$translation_resolver = new ContentTranslationResolver( $group_repo, $this->get_language_registry() );

			$this->block_navigation_frontend_filter = new BlockNavigationFrontendFilter(
				$this->get_current_language_resolver(),
				$this->get_language_registry(),
				$translation_resolver
			);
		}

		return $this->block_navigation_frontend_filter;
	}

	/**
	 * Gets the nav menu editorial UI instance.
	 *
	 * @return NavMenuEditorialUi
	 */
	public function get_nav_menu_editorial_ui(): NavMenuEditorialUi {
		if ( null === $this->nav_menu_editorial_ui ) {
			$group_repo           = new TranslationGroupRepository();
			$translation_resolver = new ContentTranslationResolver( $group_repo, $this->get_language_registry() );

			$this->nav_menu_editorial_ui = new NavMenuEditorialUi(
				$this->get_nav_menu_location_repository(),
				$this->get_language_registry(),
				$translation_resolver,
				$group_repo
			);
		}

		return $this->nav_menu_editorial_ui;
	}

	/**
	 * Gets the string repository instance.
	 *
	 * @return StringRepository
	 */
	public function get_string_repository(): StringRepository {
		if ( null === $this->string_repository ) {
			$this->string_repository = new StringRepository();
		}

		return $this->string_repository;
	}

	/**
	 * Gets the string translation service instance.
	 *
	 * @return StringTranslationService
	 */
	public function get_string_translation_service(): StringTranslationService {
		if ( null === $this->string_translation_service ) {
			$this->string_translation_service = new StringTranslationService(
				$this->get_string_repository(),
				$this->get_language_registry(),
				$this->get_current_language_resolver()
			);
		}

		return $this->string_translation_service;
	}

	/**
	 * Gets the string editorial UI instance.
	 *
	 * @return StringEditorialUi
	 */
	public function get_string_editorial_ui(): StringEditorialUi {
		if ( null === $this->string_editorial_ui ) {
			$this->string_editorial_ui = new StringEditorialUi(
				$this->get_string_repository(),
				$this->get_language_registry()
			);
		}

		return $this->string_editorial_ui;
	}

	/**
	 * Gets the hreflang generator instance.
	 *
	 * @return HreflangGenerator
	 */
	public function get_hreflang_generator(): HreflangGenerator {
		if ( null === $this->hreflang_generator ) {
			$group_repo               = new TranslationGroupRepository();
			$translation_resolver     = new ContentTranslationResolver( $group_repo, $this->get_language_registry() );
			$url_resolver             = new UrlLanguageResolver( $this->get_language_registry() );
			$url_generator            = new LocalizedUrlGenerator(
				$this->get_language_registry(),
				$url_resolver,
				$translation_resolver
			);
			$this->hreflang_generator = new HreflangGenerator(
				$this->get_language_registry(),
				$group_repo,
				$translation_resolver,
				$url_generator
			);
		}

		return $this->hreflang_generator;
	}

	/**
	 * Gets the canonical URL manager instance.
	 *
	 * @return CanonicalUrlManager
	 */
	public function get_canonical_url_manager(): CanonicalUrlManager {
		if ( null === $this->canonical_url_manager ) {
			$group_repo                  = new TranslationGroupRepository();
			$translation_resolver        = new ContentTranslationResolver( $group_repo, $this->get_language_registry() );
			$url_resolver                = new UrlLanguageResolver( $this->get_language_registry() );
			$url_generator               = new LocalizedUrlGenerator(
				$this->get_language_registry(),
				$url_resolver,
				$translation_resolver
			);
			$this->canonical_url_manager = new CanonicalUrlManager(
				$this->get_language_registry(),
				$translation_resolver,
				$url_generator,
				$url_resolver
			);
		}

		return $this->canonical_url_manager;
	}

	/**
	 * Gets the core sitemaps filter instance.
	 *
	 * @return CoreSitemapsFilter
	 */
	public function get_core_sitemaps_filter(): CoreSitemapsFilter {
		if ( null === $this->core_sitemaps_filter ) {
			$group_repo                 = new TranslationGroupRepository();
			$translation_resolver       = new ContentTranslationResolver( $group_repo, $this->get_language_registry() );
			$url_resolver               = new UrlLanguageResolver( $this->get_language_registry() );
			$url_generator              = new LocalizedUrlGenerator(
				$this->get_language_registry(),
				$url_resolver,
				$translation_resolver
			);
			$this->core_sitemaps_filter = new CoreSitemapsFilter(
				$this->get_language_registry(),
				$translation_resolver,
				$url_generator
			);
		}

		return $this->core_sitemaps_filter;
	}

	/**
	 * Gets the SEO frontend filter instance.
	 *
	 * @return SeoFrontendFilter
	 */
	public function get_seo_frontend_filter(): SeoFrontendFilter {
		if ( null === $this->seo_frontend_filter ) {
			$this->seo_frontend_filter = new SeoFrontendFilter(
				$this->get_hreflang_generator(),
				$this->get_canonical_url_manager(),
				$this->get_core_sitemaps_filter()
			);
		}

		return $this->seo_frontend_filter;
	}

	/**
	 * Gets the REST API registrar instance.
	 *
	 * @return RestApiRegistrar
	 */
	public function get_rest_api_registrar(): RestApiRegistrar {
		if ( null === $this->rest_api_registrar ) {
			$group_repo               = new TranslationGroupRepository();
			$translation_resolver     = new ContentTranslationResolver( $group_repo, $this->get_language_registry() );
			$this->rest_api_registrar = new RestApiRegistrar(
				$this->get_language_registry(),
				$group_repo,
				$translation_resolver,
				$this->get_editorial_service(),
				$this->get_editorial_service()->get_status_resolver()
			);
		}

		return $this->rest_api_registrar;
	}


	/**
	 * Returns whether the plugin has been initialized.
	 *
	 * @return bool
	 */
	public function is_initialized(): bool {
		return $this->initialized;
	}


	/**
	 * Gets the current technical plugin version.
	 *
	 * @return string
	 */
	public function get_version(): string {
		return self::VERSION;
	}

	/**
	 * Validates environment requirements against runtime versions.
	 *
	 * @param string $current_php_version Current PHP version.
	 * @param string $current_wp_version  Current WordPress version.
	 * @return bool True if requirements are satisfied.
	 */
	public function check_requirements( string $current_php_version, string $current_wp_version ): bool {
		$php_valid = version_compare( $current_php_version, self::MIN_PHP_VERSION, '>=' );
		$wp_valid  = version_compare( $current_wp_version, self::MIN_WP_VERSION, '>=' );

		return $php_valid && $wp_valid;
	}
}
