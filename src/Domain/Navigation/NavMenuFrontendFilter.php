<?php
/**
 * Navigation Menu Frontend Filter.
 *
 * @package TF\Multilingual\Domain\Navigation
 */

declare( strict_types=1 );

namespace TF\Multilingual\Domain\Navigation;

use stdClass;
use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Domain\Translation\ContentTranslationResolver;
use TF\Multilingual\Migration\RetainedTranslationRegistry;
use TF\Multilingual\Routing\CurrentLanguageResolver;
use TF\Multilingual\Routing\LocalizedUrlGenerator;
use WP_Post;

/**
 * Class NavMenuFrontendFilter
 *
 * Intercepts WordPress navigation menus on frontend rendering:
 * - Switches theme locations to localized menus.
 * - Resolves internal post and taxonomy links to their translated targets.
 * - Passes translated post and term permalinks through LocalizedUrlGenerator::localize_url().
 * - Localizes custom internal URLs while preserving query parameters and fragments.
 * - Excludes retained translations and non-published posts from localization.
 * - Guarantees O(1) database queries through batch pre-fetching (Zero N+1).
 */
class NavMenuFrontendFilter {

	/**
	 * Location repository.
	 *
	 * @var NavMenuLocationRepository
	 */
	private NavMenuLocationRepository $location_repository;

	/**
	 * Current language resolver.
	 *
	 * @var CurrentLanguageResolver
	 */
	private CurrentLanguageResolver $current_language_resolver;

	/**
	 * Language registry.
	 *
	 * @var LanguageRegistry
	 */
	private LanguageRegistry $language_registry;

	/**
	 * Content translation resolver.
	 *
	 * @var ContentTranslationResolver
	 */
	private ContentTranslationResolver $translation_resolver;

	/**
	 * Localized URL generator.
	 *
	 * @var LocalizedUrlGenerator
	 */
	private LocalizedUrlGenerator $url_generator;

	/**
	 * Optional retained translation registry.
	 *
	 * @var RetainedTranslationRegistry|null
	 */
	private ?RetainedTranslationRegistry $retained_registry;

	/**
	 * Constructor.
	 *
	 * @param NavMenuLocationRepository        $location_repository       Location repository.
	 * @param CurrentLanguageResolver          $current_language_resolver Current language resolver.
	 * @param LanguageRegistry                 $language_registry         Language registry.
	 * @param ContentTranslationResolver       $translation_resolver      Translation resolver.
	 * @param LocalizedUrlGenerator            $url_generator             Localized URL generator.
	 * @param RetainedTranslationRegistry|null $retained_registry         Optional retained translation registry.
	 */
	public function __construct(
		NavMenuLocationRepository $location_repository,
		CurrentLanguageResolver $current_language_resolver,
		LanguageRegistry $language_registry,
		ContentTranslationResolver $translation_resolver,
		LocalizedUrlGenerator $url_generator,
		?RetainedTranslationRegistry $retained_registry = null
	) {
		$this->location_repository       = $location_repository;
		$this->current_language_resolver = $current_language_resolver;
		$this->language_registry         = $language_registry;
		$this->translation_resolver      = $translation_resolver;
		$this->url_generator             = $url_generator;
		$this->retained_registry         = $retained_registry;
	}

	/**
	 * Registers WordPress filter hooks.
	 *
	 * @return void
	 */
	public function init_hooks(): void {
		add_filter( 'theme_mod_nav_menu_locations', array( $this, 'filter_nav_menu_locations' ), 20, 1 );
		add_filter( 'wp_nav_menu_args', array( $this, 'filter_wp_nav_menu_args' ), 20, 1 );
		add_filter( 'wp_nav_menu_objects', array( $this, 'filter_wp_nav_menu_objects' ), 20, 2 );
	}

	/**
	 * Filters theme mod nav_menu_locations to swap location menus based on active language.
	 *
	 * @param mixed $locations Associative array of theme locations to menu term IDs.
	 * @return mixed Filtered locations array.
	 */
	public function filter_nav_menu_locations( mixed $locations ): mixed {
		if ( ! is_array( $locations ) || ! $this->language_registry->is_configured() ) {
			return $locations;
		}

		$default_lang = $this->language_registry->get_default_code();
		$current_lang = $this->current_language_resolver->get_current_language() ?? $default_lang;

		if ( null === $current_lang || ! $this->language_registry->is_active( $current_lang ) || $current_lang === $default_lang ) {
			return $locations;
		}

		$filtered = $locations;
		foreach ( $locations as $location => $native_menu_id ) {
			$mapped_id = $this->location_repository->get_menu_for_location( (string) $location, $current_lang, (int) $native_menu_id );
			if ( null !== $mapped_id && $mapped_id > 0 ) {
				$filtered[ $location ] = $mapped_id;
			}
		}

		return $filtered;
	}

	/**
	 * Filters wp_nav_menu arguments when a menu is called directly by ID or slug.
	 *
	 * @param array<string, mixed> $args Menu arguments.
	 * @return array<string, mixed> Filtered arguments.
	 */
	public function filter_wp_nav_menu_args( array $args ): array {
		if ( ! $this->language_registry->is_configured() ) {
			return $args;
		}

		$default_lang = $this->language_registry->get_default_code();
		$current_lang = $this->current_language_resolver->get_current_language() ?? $default_lang;

		if ( null === $current_lang || ! $this->language_registry->is_active( $current_lang ) || $current_lang === $default_lang ) {
			return $args;
		}

		// Only translate explicit menu arg when theme_location is not provided
		if ( empty( $args['theme_location'] ) && ! empty( $args['menu'] ) ) {
			$menu_id = $this->resolve_menu_id_from_arg( $args['menu'] );
			if ( $menu_id > 0 ) {
				$translated = $this->translation_resolver->resolve( 'term', $menu_id, $current_lang );
				if ( null !== $translated && $translated->get_element_id() > 0 ) {
					$args['menu'] = $translated->get_element_id();
				}
			}
		}

		return $args;
	}

	/**
	 * Filters menu items to localize internal post, taxonomy, and custom links with Zero N+1.
	 *
	 * @param array<WP_Post> $items Array of nav menu item post objects.
	 * @param stdClass       $args  Menu display arguments.
	 * @return array<WP_Post> Processed items with localized links.
	 */
	public function filter_wp_nav_menu_objects( array $items, stdClass $args ): array {
		if ( empty( $items ) || ! $this->language_registry->is_configured() ) {
			return $items;
		}

		$default_lang = $this->language_registry->get_default_code();
		$current_lang = $this->current_language_resolver->get_current_language() ?? $default_lang;

		if ( null === $current_lang || ! $this->language_registry->is_active( $current_lang ) ) {
			return $items;
		}

		// 1. Batch collect all post IDs and term IDs to pre-warm cache in O(1) queries.
		$post_ids = array();
		$term_ids = array();

		foreach ( $items as $item ) {
			if ( ! $item instanceof WP_Post ) {
				continue;
			}

			$object_id = (int) ( $item->object_id ?? 0 );
			if ( $object_id <= 0 ) {
				continue;
			}

			$type = $item->type ?? '';
			if ( 'post_type' === $type ) {
				$post_ids[] = $object_id;
			} elseif ( 'taxonomy' === $type ) {
				$term_ids[] = $object_id;
			}
		}

		if ( ! empty( $post_ids ) ) {
			$this->translation_resolver->prefetch( 'post', array_unique( $post_ids ) );

			if ( function_exists( '_prime_post_caches' ) ) {
				$target_post_ids = array();
				foreach ( array_unique( $post_ids ) as $pid ) {
					$trans = $this->translation_resolver->resolve( 'post', $pid, $current_lang );
					if ( null !== $trans && $trans->get_element_id() > 0 ) {
						$target_post_ids[] = $trans->get_element_id();
					}
				}
				if ( ! empty( $target_post_ids ) ) {
					_prime_post_caches( $target_post_ids, false, false );
				}
			}
		}

		if ( ! empty( $term_ids ) ) {
			$this->translation_resolver->prefetch( 'term', array_unique( $term_ids ) );

			if ( function_exists( '_prime_term_caches' ) ) {
				$target_term_ids = array();
				foreach ( array_unique( $term_ids ) as $tid ) {
					$trans = $this->translation_resolver->resolve( 'term', $tid, $current_lang );
					if ( null !== $trans && $trans->get_element_id() > 0 ) {
						$target_term_ids[] = $trans->get_element_id();
					}
				}
				if ( ! empty( $target_term_ids ) ) {
					_prime_term_caches( $target_term_ids, false );
				}
			}
		}

		// 2. Localize individual menu items in memory (0 queries).
		foreach ( $items as $item ) {
			if ( ! $item instanceof WP_Post ) {
				continue;
			}

			$this->localize_menu_item( $item, $current_lang );
		}

		return $items;
	}

	/**
	 * Localizes a single menu item object.
	 *
	 * @param WP_Post $item         Nav menu item object.
	 * @param string  $current_lang Target language code.
	 * @return void
	 */
	protected function localize_menu_item( WP_Post $item, string $current_lang ): void {
		$type      = $item->type ?? '';
		$object_id = (int) ( $item->object_id ?? 0 );

		if ( 'post_type' === $type && $object_id > 0 ) {
			if ( $this->is_retained_element( 'post', $object_id ) ) {
				return;
			}

			$original_url = (string) ( $item->url ?? '' );
			$translated   = $this->translation_resolver->resolve( 'post', $object_id, $current_lang );
			if ( null !== $translated && $translated->get_element_id() > 0 ) {
				$trans_id = $translated->get_element_id();
				if ( $this->is_retained_element( 'post', $trans_id ) || ! $this->is_post_publicly_available( $trans_id ) ) {
					return;
				}

				$item->object_id = (string) $trans_id;
				if ( $this->url_generator->is_front_page_for_language( $trans_id, $current_lang ) ) {
					$item->url = $this->preserve_url_decorations( $this->url_generator->home_url( $current_lang ), $original_url );
				} else {
					$permalink = $this->get_post_permalink( $trans_id );
					if ( ! empty( $permalink ) ) {
						$decorated = $this->preserve_url_decorations( $permalink, $original_url );
						$item->url = $this->url_generator->localize_url( $decorated, $current_lang );
					}
				}
			} elseif ( $this->url_generator->is_front_page_for_language( $object_id, $current_lang ) && $this->is_post_publicly_available( $object_id ) ) {
				$item->url = $this->preserve_url_decorations( $this->url_generator->home_url( $current_lang ), $original_url );
			}
			return;
		}

		if ( 'taxonomy' === $type && $object_id > 0 ) {
			if ( $this->is_retained_element( 'term', $object_id ) ) {
				return;
			}

			$original_url = (string) ( $item->url ?? '' );
			$translated   = $this->translation_resolver->resolve( 'term', $object_id, $current_lang );
			if ( null !== $translated && $translated->get_element_id() > 0 ) {
				$trans_id = $translated->get_element_id();
				if ( $this->is_retained_element( 'term', $trans_id ) ) {
					return;
				}

				$item->object_id = (string) $trans_id;
				$term_link       = $this->get_taxonomy_term_link( $trans_id, (string) ( $item->object ?? '' ) );
				if ( ! empty( $term_link ) ) {
					$decorated = $this->preserve_url_decorations( $term_link, $original_url );
					$item->url = $this->url_generator->localize_url( $decorated, $current_lang );
				}
			}
			return;
		}

		if ( 'custom' === $type && ! empty( $item->url ) ) {
			$default_lang = $this->language_registry->get_default_code();
			if ( $current_lang !== $default_lang ) {
				$url = (string) $item->url;
				if ( $this->is_internal_url( $url ) ) {
					$item->url = $this->url_generator->localize_url( $url, $current_lang );
				}
			}
		}
	}

	/**
	 * Checks whether an element is registered in the RetainedTranslationRegistry.
	 *
	 * @param string $element_type Element type ('post' or 'term').
	 * @param int    $element_id   Element ID.
	 * @return bool True if retained and ineligible for public navigation.
	 */
	protected function is_retained_element( string $element_type, int $element_id ): bool {
		if ( null === $this->retained_registry || $element_id <= 0 ) {
			return false;
		}

		return $this->retained_registry->has_retained_element( $element_type, $element_id );
	}

	/**
	 * Checks whether a target post is publicly published.
	 *
	 * @param int $post_id Post ID.
	 * @return bool True if post status is 'publish'.
	 */
	protected function is_post_publicly_available( int $post_id ): bool {
		if ( $post_id <= 0 ) {
			return false;
		}

		if ( function_exists( 'get_post_status' ) ) {
			return 'publish' === get_post_status( $post_id );
		}

		return true;
	}

	/**
	 * Preserves custom query parameters and URL fragment from the original menu item URL
	 * when resolving a post or taxonomy permalink.
	 *
	 * Internal WordPress routing query variables (p, page_id, attachment_id, cat, tag_id, term_id, lang)
	 * from the source URL are excluded so they never override the translated destination.
	 *
	 * @param string $target_url   Target permalink or localized home URL.
	 * @param string $original_url Original menu item URL.
	 * @return string URL with preserved custom query string and fragment.
	 */
	protected function preserve_url_decorations( string $target_url, string $original_url ): string {
		if ( '' === $target_url || '' === $original_url ) {
			return $target_url;
		}

		$orig_parsed = parse_url( $original_url );
		if ( false === $orig_parsed ) {
			return $target_url;
		}

		$target_parsed = parse_url( $target_url );
		if ( false === $target_parsed ) {
			return $target_url;
		}

		$result = $target_url;

		if ( ! empty( $orig_parsed['query'] ) && empty( $target_parsed['query'] ) ) {
			parse_str( $orig_parsed['query'], $query_params );
			$internal_keys = array( 'p', 'page_id', 'attachment_id', 'cat', 'tag_id', 'term_id', 'lang' );
			foreach ( $internal_keys as $key ) {
				unset( $query_params[ $key ] );
			}

			if ( ! empty( $query_params ) ) {
				$hash_pos = strpos( $result, '#' );
				$fragment = '';
				if ( false !== $hash_pos ) {
					$fragment = substr( $result, $hash_pos );
					$result   = substr( $result, 0, $hash_pos );
				}
				$result .= '?' . http_build_query( $query_params ) . $fragment;
			}
		}

		if ( ! empty( $orig_parsed['fragment'] ) && empty( $target_parsed['fragment'] ) && ! str_contains( $result, '#' ) ) {
			$result .= '#' . $orig_parsed['fragment'];
		}

		return $result;
	}

	/**
	 * Resolves menu ID from arguments (supports integer ID, numeric string, or WP_Term object).
	 *
	 * @param mixed $arg Menu argument.
	 * @return int Menu term ID or 0 if unresolvable.
	 */
	protected function resolve_menu_id_from_arg( mixed $arg ): int {
		if ( is_numeric( $arg ) ) {
			return (int) $arg;
		}

		if ( is_object( $arg ) && isset( $arg->term_id ) ) {
			return (int) $arg->term_id;
		}

		if ( is_string( $arg ) && function_exists( 'wp_get_nav_menu_object' ) ) {
			$obj = wp_get_nav_menu_object( $arg );
			if ( is_object( $obj ) && isset( $obj->term_id ) ) {
				return (int) $obj->term_id;
			}
		}

		return 0;
	}

	/**
	 * Checks if a URL belongs to the current WordPress site host.
	 *
	 * @param string $url URL to check.
	 * @return bool True if internal or relative.
	 */
	protected function is_internal_url( string $url ): bool {
		$trimmed = trim( $url );
		if ( '' === $trimmed || '#' === $trimmed[0] ) {
			return false;
		}

		// Relative paths are always internal
		if ( str_starts_with( $trimmed, '/' ) && ! str_starts_with( $trimmed, '//' ) ) {
			return true;
		}

		$parsed = parse_url( $trimmed );
		if ( false === $parsed ) {
			return false;
		}

		if ( isset( $parsed['scheme'] ) && ! in_array( strtolower( (string) $parsed['scheme'] ), array( 'http', 'https' ), true ) ) {
			return false;
		}

		if ( empty( $parsed['host'] ) ) {
			return true;
		}

		$site_url  = function_exists( 'home_url' ) ? (string) home_url() : 'http://localhost';
		$site_host = parse_url( $site_url, PHP_URL_HOST );

		return strtolower( (string) $parsed['host'] ) === strtolower( (string) $site_host );
	}

	/**
	 * Gets permalink for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	protected function get_post_permalink( int $post_id ): string {
		if ( function_exists( 'get_permalink' ) ) {
			$link = get_permalink( $post_id );
			return is_string( $link ) ? $link : '';
		}

		if ( isset( $GLOBALS['wp_test_permalinks'][ $post_id ] ) ) {
			return (string) $GLOBALS['wp_test_permalinks'][ $post_id ];
		}

		return 'http://localhost/?p=' . $post_id;
	}

	/**
	 * Gets term link for a taxonomy term.
	 *
	 * @param int    $term_id  Term ID.
	 * @param string $taxonomy Taxonomy name.
	 * @return string
	 */
	protected function get_taxonomy_term_link( int $term_id, string $taxonomy ): string {
		if ( function_exists( 'get_term_link' ) ) {
			$link = get_term_link( $term_id, $taxonomy );
			return is_string( $link ) ? $link : '';
		}

		if ( isset( $GLOBALS['wp_test_term_links'][ $term_id ] ) ) {
			return (string) $GLOBALS['wp_test_term_links'][ $term_id ];
		}

		return 'http://localhost/?tag_id=' . $term_id;
	}
}
