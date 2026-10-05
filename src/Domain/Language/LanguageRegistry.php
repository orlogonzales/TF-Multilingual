<?php
/**
 * Language Registry Domain Service.
 *
 * @package TF\Multilingual\Domain\Language
 */

declare( strict_types=1 );

namespace TF\Multilingual\Domain\Language;

use TF\Multilingual\Domain\Language\Exceptions\DefaultLanguageException;
use TF\Multilingual\Domain\Language\Exceptions\DuplicateLanguageException;
use TF\Multilingual\Domain\Language\Exceptions\LanguageNotFoundException;
use TF\Multilingual\Domain\Language\Exceptions\InvalidLanguageException;

/**
 * Class LanguageRegistry
 *
 * Domain service managing configured languages, active states and the sovereign default language.
 */
class LanguageRegistry {

	/**
	 * Settings repository for Options API interaction.
	 *
	 * @var SettingsRepository
	 */
	private SettingsRepository $repository;

	/**
	 * In-memory map of registered languages indexed by canonical code.
	 *
	 * @var array<string, Language>
	 */
	private array $languages = array();

	/**
	 * Canonical code of the sovereign default language.
	 *
	 * @var string|null
	 */
	private ?string $default_language = null;

	/**
	 * Whether the registry has been hydrated from persistence.
	 *
	 * @var bool
	 */
	private bool $loaded = false;

	/**
	 * Constructor.
	 *
	 * @param SettingsRepository|null $repository Optional repository.
	 */
	public function __construct( ?SettingsRepository $repository = null ) {
		$this->repository = null !== $repository ? $repository : new SettingsRepository();
	}

	/**
	 * Ensures languages are loaded into memory cache from persistence.
	 *
	 * @return void
	 */
	public function ensure_loaded(): void {
		if ( $this->loaded ) {
			return;
		}

		$payload = $this->repository->load();

		$this->languages        = array();
		$this->default_language = null;

		foreach ( $payload['languages'] as $code => $data ) {
			try {
				$language = Language::from_array( $data, $code );

				$this->languages[ $language->get_code() ] = $language;
			} catch ( InvalidLanguageException $e ) {
				// Corrupted entry: skip safely without crashing the system.
				continue;
			}
		}

		if ( null !== $payload['default_language'] ) {
			$canonical_default = Language::normalize_code( $payload['default_language'] );
			if ( isset( $this->languages[ $canonical_default ] ) ) {
				$this->default_language = $canonical_default;
			}
		}

		$this->loaded = true;
	}

	/**
	 * Resets in-memory state, forcing a reload on next access.
	 *
	 * @return void
	 */
	public function reset(): void {
		$this->languages        = array();
		$this->default_language = null;
		$this->loaded           = false;
	}

	/**
	 * Checks if the system is fully configured with at least one active language and valid default.
	 *
	 * @return bool True if CONFIGURED, false if NOT_CONFIGURED.
	 */
	public function is_configured(): bool {
		$this->ensure_loaded();

		if ( empty( $this->languages ) ) {
			return false;
		}

		if ( null === $this->default_language ) {
			return false;
		}

		if ( ! isset( $this->languages[ $this->default_language ] ) ) {
			return false;
		}

		if ( ! $this->languages[ $this->default_language ]->is_active() ) {
			return false;
		}

		$has_active = false;
		foreach ( $this->languages as $lang ) {
			if ( $lang->is_active() ) {
				$has_active = true;
				break;
			}
		}

		return $has_active;
	}

	/**
	 * Returns all registered languages sorted by order ascending.
	 *
	 * @return array<string, Language>
	 */
	public function all(): array {
		$this->ensure_loaded();

		$sorted = $this->languages;
		uasort(
			$sorted,
			fn( Language $a, Language $b ) => $a->get_order() <=> $b->get_order()
		);

		return $sorted;
	}

	/**
	 * Returns only active languages sorted by order ascending.
	 *
	 * @return array<string, Language>
	 */
	public function active(): array {
		$this->ensure_loaded();

		$active = array();
		foreach ( $this->languages as $code => $lang ) {
			if ( $lang->is_active() ) {
				$active[ $code ] = $lang;
			}
		}

		uasort(
			$active,
			fn( Language $a, Language $b ) => $a->get_order() <=> $b->get_order()
		);

		return $active;
	}

	/**
	 * Checks if a language code is registered.
	 *
	 * @param string $code Language code.
	 * @return bool
	 */
	public function has( string $code ): bool {
		$this->ensure_loaded();
		$canonical = Language::normalize_code( $code );

		return isset( $this->languages[ $canonical ] );
	}

	/**
	 * Checks if a language code is registered and active.
	 *
	 * @param string $code Language code.
	 * @return bool
	 */
	public function is_active( string $code ): bool {
		$this->ensure_loaded();
		$canonical = Language::normalize_code( $code );

		return isset( $this->languages[ $canonical ] ) && $this->languages[ $canonical ]->is_active();
	}

	/**
	 * Gets a language by code, or null if not registered.
	 *
	 * @param string $code Language code.
	 * @return Language|null
	 */
	public function get( string $code ): ?Language {
		$this->ensure_loaded();
		$canonical = Language::normalize_code( $code );

		return $this->languages[ $canonical ] ?? null;
	}

	/**
	 * Gets a language by code or throws LanguageNotFoundException.
	 *
	 * @param string $code Language code.
	 * @return Language
	 * @throws LanguageNotFoundException If not registered.
	 */
	public function get_or_fail( string $code ): Language {
		$language = $this->get( $code );
		if ( null === $language ) {
			throw LanguageNotFoundException::for_code( $code );
		}

		return $language;
	}

	/**
	 * Gets the sovereign default language instance, or null if not configured.
	 *
	 * @return Language|null
	 */
	public function get_default(): ?Language {
		$this->ensure_loaded();
		if ( null === $this->default_language ) {
			return null;
		}

		return $this->languages[ $this->default_language ] ?? null;
	}

	/**
	 * Gets the sovereign default language code, or null if not configured.
	 *
	 * @return string|null
	 */
	public function get_default_code(): ?string {
		$this->ensure_loaded();

		return $this->default_language;
	}

	/**
	 * Registers a new language into the registry.
	 *
	 * Registering a language does NOT automatically designate it as the default language.
	 * An unconfigured registry retains default_language = null (NOT_CONFIGURED state)
	 * unless explicitly set via $is_default = true or set_default().
	 *
	 * @param Language $language   Language entity.
	 * @param bool     $is_default Whether to explicitly designate as sovereign default language.
	 * @return void
	 * @throws DuplicateLanguageException If code already exists.
	 * @throws DefaultLanguageException If designating an inactive language as default.
	 */
	public function add_language( Language $language, bool $is_default = false ): void {
		$this->ensure_loaded();

		$code = $language->get_code();
		if ( isset( $this->languages[ $code ] ) ) {
			throw DuplicateLanguageException::for_code( $code );
		}

		if ( $is_default ) {
			if ( ! $language->is_active() ) {
				throw new DefaultLanguageException( 'Cannot designate an inactive language as default.' );
			}
			$this->default_language = $code;
		}

		$this->languages[ $code ] = $language;
	}

	/**
	 * Sets an existing active language as sovereign default.
	 *
	 * @param string $code Canonical language code.
	 * @return void
	 * @throws LanguageNotFoundException If language does not exist.
	 * @throws DefaultLanguageException If language is not active.
	 */
	public function set_default( string $code ): void {
		$this->ensure_loaded();
		$canonical = Language::normalize_code( $code );

		if ( ! isset( $this->languages[ $canonical ] ) ) {
			throw LanguageNotFoundException::for_code( $code );
		}

		if ( ! $this->languages[ $canonical ]->is_active() ) {
			throw new DefaultLanguageException(
				sprintf( 'Cannot set inactive language "%s" as default.', $code )
			);
		}

		$this->default_language = $canonical;
	}

	/**
	 * Activates a registered language.
	 *
	 * @param string $code Language code.
	 * @return void
	 * @throws LanguageNotFoundException If language does not exist.
	 */
	public function activate( string $code ): void {
		$this->ensure_loaded();
		$canonical = Language::normalize_code( $code );

		if ( ! isset( $this->languages[ $canonical ] ) ) {
			throw LanguageNotFoundException::for_code( $code );
		}

		$this->languages[ $canonical ] = $this->languages[ $canonical ]->with_active( true );
	}

	/**
	 * Deactivates a registered language.
	 *
	 * @param string $code Language code.
	 * @return void
	 * @throws LanguageNotFoundException If language does not exist.
	 * @throws DefaultLanguageException If attempting to deactivate default language.
	 */
	public function deactivate( string $code ): void {
		$this->ensure_loaded();
		$canonical = Language::normalize_code( $code );

		if ( ! isset( $this->languages[ $canonical ] ) ) {
			throw LanguageNotFoundException::for_code( $code );
		}

		if ( $canonical === $this->default_language ) {
			throw new DefaultLanguageException(
				sprintf( 'Cannot deactivate language "%s" because it is the sovereign default language.', $code )
			);
		}

		$this->languages[ $canonical ] = $this->languages[ $canonical ]->with_active( false );
	}

	/**
	 * Removes a language from the registry.
	 *
	 * @param string $code Language code.
	 * @return void
	 * @throws LanguageNotFoundException If language does not exist.
	 * @throws DefaultLanguageException If attempting to remove default language.
	 */
	public function remove_language( string $code ): void {
		$this->ensure_loaded();
		$canonical = Language::normalize_code( $code );

		if ( ! isset( $this->languages[ $canonical ] ) ) {
			throw LanguageNotFoundException::for_code( $code );
		}

		if ( $canonical === $this->default_language ) {
			throw new DefaultLanguageException(
				sprintf( 'Cannot remove language "%s" because it is the sovereign default language.', $code )
			);
		}

		unset( $this->languages[ $canonical ] );
	}

	/**
	 * Updates properties of an existing language.
	 *
	 * @param string               $code       Language code.
	 * @param array<string, mixed> $properties Properties to update ('name', 'native_name', 'locale', 'order', 'active').
	 * @return void
	 * @throws LanguageNotFoundException If language does not exist.
	 * @throws DefaultLanguageException If deactivating default language.
	 */
	public function update_language( string $code, array $properties ): void {
		$this->ensure_loaded();
		$canonical = Language::normalize_code( $code );

		if ( ! isset( $this->languages[ $canonical ] ) ) {
			throw LanguageNotFoundException::for_code( $code );
		}

		$current = $this->languages[ $canonical ];

		$name        = isset( $properties['name'] ) && is_string( $properties['name'] ) ? $properties['name'] : $current->get_name();
		$native_name = isset( $properties['native_name'] ) && is_string( $properties['native_name'] ) ? $properties['native_name'] : $current->get_native_name();
		$locale      = isset( $properties['locale'] ) && is_string( $properties['locale'] ) ? $properties['locale'] : $current->get_locale();
		$order       = isset( $properties['order'] ) && is_numeric( $properties['order'] ) ? (int) $properties['order'] : $current->get_order();

		$active = $current->is_active();
		if ( isset( $properties['active'] ) ) {
			$new_active = (bool) $properties['active'];
			if ( ! $new_active && $canonical === $this->default_language ) {
				throw new DefaultLanguageException( 'Cannot deactivate the sovereign default language.' );
			}
			$active = $new_active;
		}

		$this->languages[ $canonical ] = Language::create( $canonical, $locale, $name, $native_name, $active, $order );
	}

	/**
	 * Reorders languages according to an ordered list of codes.
	 *
	 * @param array<string> $codes_in_order Ordered list of language codes.
	 * @return void
	 */
	public function reorder( array $codes_in_order ): void {
		$this->ensure_loaded();

		$current_order = 10;
		foreach ( $codes_in_order as $raw_code ) {
			$canonical = Language::normalize_code( $raw_code );
			if ( isset( $this->languages[ $canonical ] ) ) {
				$this->languages[ $canonical ] = $this->languages[ $canonical ]->with_order( $current_order );
				$current_order                += 10;
			}
		}
	}

	/**
	 * Persists current registry state into Options API through SettingsRepository.
	 *
	 * @return bool True on success.
	 */
	public function persist(): bool {
		$this->ensure_loaded();

		$data = array(
			'default_language' => $this->default_language,
			'languages'        => array(),
		);

		foreach ( $this->languages as $code => $lang ) {
			$data['languages'][ $code ] = $lang->to_array();
		}

		return $this->repository->save( $data );
	}
}
