<?php
/**
 * Content Translation Resolver Service.
 *
 * @package TF\Multilingual\Domain\Translation
 */

declare( strict_types=1 );

namespace TF\Multilingual\Domain\Translation;

use TF\Multilingual\Domain\Language\Language;
use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Domain\Language\Exceptions\InvalidLanguageException;
use TF\Multilingual\Domain\Language\Exceptions\LanguageNotFoundException;
use TF\Multilingual\Domain\Translation\Exceptions\InvalidTranslationElementException;

/**
 * Class ContentTranslationResolver
 *
 * Application / Domain service responsible for programmatic resolution of multilingual
 * content across WordPress entities (posts, pages, CPTs, and terms).
 */
class ContentTranslationResolver {

	/**
	 * Translation group repository.
	 *
	 * @var TranslationGroupRepository
	 */
	private TranslationGroupRepository $group_repository;

	/**
	 * Language registry domain service.
	 *
	 * @var LanguageRegistry
	 */
	private LanguageRegistry $language_registry;

	/**
	 * WordPress element validator service.
	 *
	 * @var WordPressElementValidator
	 */
	private WordPressElementValidator $element_validator;

	/**
	 * In-request memory cache for TranslationGroups.
	 * Maps "element_type:element_id" => ?TranslationGroup.
	 *
	 * @var array<string, TranslationGroup|null>
	 */
	private array $group_cache = array();

	/**
	 * Constructor.
	 *
	 * @param TranslationGroupRepository|null $group_repository  Translation group repository.
	 * @param LanguageRegistry|null           $language_registry Language registry.
	 * @param WordPressElementValidator|null  $element_validator Element validator.
	 */
	public function __construct(
		?TranslationGroupRepository $group_repository = null,
		?LanguageRegistry $language_registry = null,
		?WordPressElementValidator $element_validator = null
	) {
		$this->group_repository  = null !== $group_repository ? $group_repository : new TranslationGroupRepository();
		$this->language_registry = null !== $language_registry ? $language_registry : new LanguageRegistry();
		$this->element_validator = null !== $element_validator ? $element_validator : new WordPressElementValidator();
	}

	/**
	 * Resolves the equivalent TranslationElement for a given WordPress object and target language.
	 *
	 * @param string $element_type    Element type ('post' or 'term').
	 * @param int    $element_id      WordPress object ID (post ID or term ID).
	 * @param string $target_language Requested target language code (e.g. 'en', 'es').
	 * @return TranslationElement|null The resolved translation element, or null if no translation exists.
	 * @throws InvalidTranslationElementException If element type is unsupported.
	 * @throws InvalidLanguageException If target language is empty or registered but inactive.
	 * @throws LanguageNotFoundException If target language is not registered in the system.
	 */
	public function resolve( string $element_type, int $element_id, string $target_language ): ?TranslationElement {
		$normalized_type = strtolower( trim( $element_type ) );
		if ( ! in_array( $normalized_type, array( 'post', 'term' ), true ) ) {
			throw InvalidTranslationElementException::for_unsupported_type( $element_type );
		}

		if ( $element_id <= 0 ) {
			return null;
		}

		$normalized_lang = Language::normalize_code( $target_language );
		if ( '' === $normalized_lang ) {
			throw new InvalidLanguageException( 'Target language code cannot be empty.' );
		}

		// Validate target language against sovereign LanguageRegistry.
		if ( ! $this->language_registry->has( $normalized_lang ) ) {
			throw LanguageNotFoundException::for_code( $normalized_lang );
		}

		if ( ! $this->language_registry->is_active( $normalized_lang ) ) {
			throw InvalidLanguageException::for_inactive_language( $normalized_lang );
		}

		// Retrieve group for source element.
		$group = $this->get_group_for_element( $normalized_type, $element_id );
		if ( null === $group ) {
			return null;
		}

		// Verify source element physically exists in WordPress Core.
		if ( ! $this->element_validator->exists( $normalized_type, $group->get_subtype(), $element_id ) ) {
			return null;
		}

		// Retrieve translation element from the group for the target language.
		$target_element = $group->get_translation( $normalized_lang );
		if ( null === $target_element ) {
			return null;
		}

		// Verify target element physically exists in WordPress Core.
		if ( ! $this->element_validator->exists(
			$target_element->get_element_type(),
			$group->get_subtype(),
			$target_element->get_element_id()
		) ) {
			return null;
		}

		return $target_element;
	}

	/**
	 * Resolves the destination WordPress object ID (post_id or term_id) for a target language.
	 *
	 * Convenience helper that unwraps the TranslationElement.
	 *
	 * @param string $element_type    Element type ('post' or 'term').
	 * @param int    $element_id      WordPress object ID.
	 * @param string $target_language Requested target language code.
	 * @return int|null Destination WordPress object ID, or null if no translation exists.
	 * @throws InvalidTranslationElementException If element type is unsupported.
	 * @throws InvalidLanguageException If target language is empty or inactive.
	 * @throws LanguageNotFoundException If target language is not registered.
	 */
	public function resolve_element_id( string $element_type, int $element_id, string $target_language ): ?int {
		$element = $this->resolve( $element_type, $element_id, $target_language );

		return null !== $element ? $element->get_element_id() : null;
	}

	/**
	 * Identifies the sovereign language code of a given WordPress object.
	 *
	 * The sovereign truth is derived strictly from tfml_group_elements.language_code.
	 * Never inferred from URL, slug, locale or postmeta.
	 *
	 * @param string $element_type Element type ('post' or 'term').
	 * @param int    $element_id   WordPress object ID.
	 * @return string|null Canonical language code, or null if object is unassigned or does not exist.
	 * @throws InvalidTranslationElementException If element type is unsupported.
	 */
	public function language_of( string $element_type, int $element_id ): ?string {
		$normalized_type = strtolower( trim( $element_type ) );
		if ( ! in_array( $normalized_type, array( 'post', 'term' ), true ) ) {
			throw InvalidTranslationElementException::for_unsupported_type( $element_type );
		}

		if ( $element_id <= 0 ) {
			return null;
		}

		$group = $this->get_group_for_element( $normalized_type, $element_id );
		if ( null === $group ) {
			return null;
		}

		// Verify element physically exists in WordPress Core.
		if ( ! $this->element_validator->exists( $normalized_type, $group->get_subtype(), $element_id ) ) {
			return null;
		}

		foreach ( $group->get_elements() as $element ) {
			if ( $element->get_element_id() === $element_id ) {
				return $element->get_language_code();
			}
		}

		return null;
	}

	/**
	 * Returns all active translation elements associated with an object's group, indexed by language code.
	 *
	 * @param string $element_type Element type ('post' or 'term').
	 * @param int    $element_id   WordPress object ID.
	 * @return array<string, TranslationElement> Map of language_code => TranslationElement.
	 * @throws InvalidTranslationElementException If element type is unsupported.
	 */
	public function get_translations( string $element_type, int $element_id ): array {
		$normalized_type = strtolower( trim( $element_type ) );
		if ( ! in_array( $normalized_type, array( 'post', 'term' ), true ) ) {
			throw InvalidTranslationElementException::for_unsupported_type( $element_type );
		}

		if ( $element_id <= 0 ) {
			return array();
		}

		$group = $this->get_group_for_element( $normalized_type, $element_id );
		if ( null === $group ) {
			return array();
		}

		if ( ! $this->element_validator->exists( $normalized_type, $group->get_subtype(), $element_id ) ) {
			return array();
		}

		$translations = array();
		foreach ( $group->get_elements() as $element ) {
			if ( $this->element_validator->exists(
				$element->get_element_type(),
				$group->get_subtype(),
				$element->get_element_id()
			) ) {
				$translations[ $element->get_language_code() ] = $element;
			}
		}

		return $translations;
	}

	/**
	 * Retrieves the TranslationGroup for a given element, leveraging in-request memory cache.
	 *
	 * When a group is loaded, all its member elements are cross-indexed in the cache to
	 * prevent redundant database round-trips for reciprocal or sibling queries.
	 *
	 * @param string $element_type Element type ('post' or 'term').
	 * @param int    $element_id   WordPress object ID.
	 * @return TranslationGroup|null The translation group or null if unassigned.
	 */
	public function get_group_for_element( string $element_type, int $element_id ): ?TranslationGroup {
		$cache_key = "{$element_type}:{$element_id}";
		if ( array_key_exists( $cache_key, $this->group_cache ) ) {
			return $this->group_cache[ $cache_key ];
		}

		$group                           = $this->group_repository->find_by_element( $element_type, $element_id );
		$this->group_cache[ $cache_key ] = $group;

		if ( null !== $group ) {
			foreach ( $group->get_elements() as $member ) {
				$member_key                       = "{$member->get_element_type()}:{$member->get_element_id()}";
				$this->group_cache[ $member_key ] = $group;
			}
		}

		return $group;
	}

	/**
	 * Clears the in-memory runtime cache.
	 *
	 * @return void
	 */
	public function flush_cache(): void {
		$this->group_cache = array();
	}

	/**
	 * Alias of flush_cache().
	 *
	 * @return void
	 */
	public function clear_cache(): void {
		$this->flush_cache();
	}

	/**
	 * Alias of get_group_for_element().
	 *
	 * @param string $element_type Element type ('post' or 'term').
	 * @param int    $element_id   WordPress object ID.
	 * @return TranslationGroup|null
	 */
	public function get_group_of( string $element_type, int $element_id ): ?TranslationGroup {
		return $this->get_group_for_element( $element_type, $element_id );
	}
}
