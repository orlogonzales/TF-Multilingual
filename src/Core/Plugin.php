<?php
/**
 * Main Plugin Orchestrator.
 *
 * @package TF\Multilingual\Core
 */

declare( strict_types=1 );

namespace TF\Multilingual\Core;

use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Query\QueryLanguageFilter;
use TF\Multilingual\Routing\CurrentLanguageResolver;
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

		$this->rewrite_manager->init_hooks();
		$this->query_filter->init_hooks();

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
