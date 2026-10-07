<?php
/**
 * Navigation Menu Location Repository.
 *
 * @package TF\Multilingual\Domain\Navigation
 */

declare( strict_types=1 );

namespace TF\Multilingual\Domain\Navigation;

use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Domain\Language\SettingsRepository;
use TF\Multilingual\Domain\Translation\ContentTranslationResolver;

/**
 * Class NavMenuLocationRepository
 *
 * Manages multilingual mappings between WordPress theme locations and menu IDs.
 * Persists configuration within the unified tfml_settings structure without creating additional tables.
 */
class NavMenuLocationRepository {

	/**
	 * Settings key for menu location mappings.
	 */
	public const SETTINGS_KEY = 'nav_menu_locations';

	/**
	 * Settings repository.
	 *
	 * @var SettingsRepository
	 */
	private SettingsRepository $settings_repository;

	/**
	 * Language registry.
	 *
	 * @var LanguageRegistry
	 */
	private LanguageRegistry $language_registry;

	/**
	 * Content translation resolver for automatic group resolution.
	 *
	 * @var ContentTranslationResolver|null
	 */
	private ?ContentTranslationResolver $translation_resolver;

	/**
	 * Whether to fallback to the default language menu if no translation is configured.
	 *
	 * @var bool
	 */
	private bool $fallback_to_default = true;

	/**
	 * Constructor.
	 *
	 * @param SettingsRepository              $settings_repository  Settings repository.
	 * @param LanguageRegistry                $language_registry    Language registry.
	 * @param ContentTranslationResolver|null $translation_resolver Optional translation resolver.
	 * @param bool                            $fallback_to_default  Whether to fallback to default menu.
	 */
	public function __construct(
		SettingsRepository $settings_repository,
		LanguageRegistry $language_registry,
		?ContentTranslationResolver $translation_resolver = null,
		bool $fallback_to_default = true
	) {
		$this->settings_repository  = $settings_repository;
		$this->language_registry    = $language_registry;
		$this->translation_resolver = $translation_resolver;
		$this->fallback_to_default  = $fallback_to_default;
	}

	/**
	 * Resolves the menu ID for a given theme location and language code.
	 *
	 * @param string   $location      Theme location identifier (e.g. 'primary').
	 * @param string   $language_code Target language code (e.g. 'en').
	 * @param int|null $native_id     Optional known native menu ID for the location.
	 * @return int|null Resolved menu term ID or null if none assigned.
	 */
	public function get_menu_for_location( string $location, string $language_code, ?int $native_id = null ): ?int {
		$norm_loc  = strtolower( trim( $location ) );
		$norm_lang = strtolower( trim( $language_code ) );

		if ( '' === $norm_loc || '' === $norm_lang ) {
			return null;
		}

		$default_lang = $this->language_registry->get_default_code();
		if ( null === $native_id ) {
			$native_map = $this->get_native_locations();
			$native_id  = isset( $native_map[ $norm_loc ] ) ? (int) $native_map[ $norm_loc ] : null;
		}

		// 1. Default language uses native WordPress theme location mapping.
		if ( $norm_lang === $default_lang ) {
			return $native_id && $native_id > 0 ? $native_id : null;
		}

		// 2. Check explicit TFML location configuration in settings.
		$all_mappings = $this->get_all_mappings();
		if ( isset( $all_mappings[ $norm_loc ][ $norm_lang ] ) ) {
			$configured_id = (int) $all_mappings[ $norm_loc ][ $norm_lang ];
			if ( $configured_id > 0 ) {
				return $configured_id;
			}
		}

		// 3. Automatic resolution via TranslationGroup if resolver is available.
		if ( null !== $this->translation_resolver && null !== $native_id && $native_id > 0 ) {
			$translated = $this->translation_resolver->resolve( 'term', $native_id, $norm_lang );
			if ( null !== $translated && $translated->get_element_id() > 0 ) {
				return $translated->get_element_id();
			}
		}

		// 4. Fallback policy.
		if ( $this->fallback_to_default && null !== $native_id && $native_id > 0 ) {
			return $native_id;
		}

		return null;
	}

	/**
	 * Sets the menu ID for a given theme location and language.
	 *
	 * @param string   $location      Theme location identifier.
	/**
	 * In-memory cache of mappings.
	 *
	 * @var array<string, array<string, int>>|null
	 */
	private ?array $mappings = null;

	/**
	 * Ensures mappings are loaded from SettingsRepository.
	 *
	 * @return void
	 */
	private function ensure_loaded(): void {
		if ( null !== $this->mappings ) {
			return;
		}

		$settings       = $this->settings_repository->load();
		$raw            = $settings[ self::SETTINGS_KEY ] ?? array();
		$this->mappings = is_array( $raw ) ? $raw : array();
	}

	/**
	 * Sets the menu ID for a given theme location and language.
	 *
	 * @param string   $location      Theme location identifier.
	 * @param string   $language_code Target language code.
	 * @param int|null $menu_id       Menu term ID or null to remove mapping.
	 * @return void
	 */
	public function set_menu_for_location( string $location, string $language_code, ?int $menu_id ): void {
		$this->ensure_loaded();

		$norm_loc  = strtolower( trim( $location ) );
		$norm_lang = strtolower( trim( $language_code ) );

		if ( '' === $norm_loc || '' === $norm_lang ) {
			return;
		}

		if ( null === $menu_id || $menu_id <= 0 ) {
			unset( $this->mappings[ $norm_loc ][ $norm_lang ] );
			if ( isset( $this->mappings[ $norm_loc ] ) && empty( $this->mappings[ $norm_loc ] ) ) {
				unset( $this->mappings[ $norm_loc ] );
			}
		} else {
			if ( ! isset( $this->mappings[ $norm_loc ] ) || ! is_array( $this->mappings[ $norm_loc ] ) ) {
				$this->mappings[ $norm_loc ] = array();
			}
			$this->mappings[ $norm_loc ][ $norm_lang ] = (int) $menu_id;
		}
	}

	/**
	 * Returns all location mappings from settings.
	 *
	 * @return array<string, array<string, int>> Map of location => [ language => menu_id ].
	 */
	public function get_all_mappings(): array {
		$this->ensure_loaded();
		return $this->mappings ?? array();
	}

	/**
	 * Sets whether to fallback to default language menu when no translation exists.
	 *
	 * @param bool $fallback Whether to fallback.
	 * @return void
	 */
	public function set_fallback_to_default( bool $fallback ): void {
		$this->fallback_to_default = $fallback;
	}

	/**
	 * Gets whether fallback to default language menu is enabled.
	 *
	 * @return bool
	 */
	public function is_fallback_to_default_enabled(): bool {
		return $this->fallback_to_default;
	}

	/**
	 * Persists settings.
	 *
	 * @return bool True on success.
	 */
	public function persist(): bool {
		$this->ensure_loaded();
		$settings                       = $this->settings_repository->load();
		$settings[ self::SETTINGS_KEY ] = $this->mappings ?? array();
		return $this->settings_repository->save( $settings );
	}

	/**
	 * Retrieves native WordPress theme locations.
	 *
	 * @return array<string, int>
	 */
	protected function get_native_locations(): array {
		if ( function_exists( 'get_nav_menu_locations' ) ) {
			$locs = get_nav_menu_locations();
			return is_array( $locs ) ? $locs : array();
		}

		if ( isset( $GLOBALS['wp_test_nav_menu_locations'] ) && is_array( $GLOBALS['wp_test_nav_menu_locations'] ) ) {
			return $GLOBALS['wp_test_nav_menu_locations'];
		}

		return array();
	}
}
