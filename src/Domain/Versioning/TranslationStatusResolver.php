<?php
/**
 * Translation Status Resolver Domain Service.
 *
 * @package TF\Multilingual\Domain\Versioning
 */

declare( strict_types=1 );

namespace TF\Multilingual\Domain\Versioning;

use TF\Multilingual\Domain\Language\Language;
use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Domain\Translation\TranslationElement;
use TF\Multilingual\Domain\Translation\TranslationGroup;
use TF\Multilingual\Domain\Translation\TranslationGroupRepository;
use TF\Multilingual\Domain\Translation\TranslationStatus;

/**
 * Class TranslationStatusResolver
 *
 * Computes deterministic translation lifecycle status (UNTRANSLATED, UPDATED, REVIEW)
 * by comparing source version at translation against the canonical element's current content version.
 */
class TranslationStatusResolver {

	/**
	 * Translation group repository.
	 *
	 * @var TranslationGroupRepository
	 */
	private TranslationGroupRepository $group_repository;

	/**
	 * Language registry service.
	 *
	 * @var LanguageRegistry
	 */
	private LanguageRegistry $language_registry;

	/**
	 * In-memory runtime cache for status lookups.
	 *
	 * @var array<string, string>
	 */
	private array $status_cache = array();

	/**
	 * Constructor.
	 *
	 * @param TranslationGroupRepository|null $group_repository Translation group repository.
	 * @param LanguageRegistry|null          $language_registry Language registry.
	 */
	public function __construct(
		?TranslationGroupRepository $group_repository = null,
		?LanguageRegistry $language_registry = null
	) {
		$this->group_repository  = null !== $group_repository ? $group_repository : new TranslationGroupRepository();
		$this->language_registry = null !== $language_registry ? $language_registry : new LanguageRegistry();
	}

	/**
	 * Resolves the translation status of a target language for a specific WordPress object.
	 *
	 * @param string                $element_type  Element type ('post' or 'term').
	 * @param int                   $element_id    WordPress object ID.
	 * @param string                $language_code Target canonical language code.
	 * @param TranslationGroup|null $group         Optional pre-loaded group entity (avoids DB queries).
	 * @return string TranslationStatus constant (UNTRANSLATED, UPDATED, or REVIEW).
	 */
	public function resolve_status(
		string $element_type,
		int $element_id,
		string $language_code,
		?TranslationGroup $group = null
	): string {
		$target_lang = Language::normalize_code( $language_code );
		$cache_key   = "{$element_type}:{$element_id}:{$target_lang}";

		if ( isset( $this->status_cache[ $cache_key ] ) ) {
			return $this->status_cache[ $cache_key ];
		}

		if ( null === $group ) {
			$group = $this->group_repository->find_by_element( $element_type, $element_id );
		}

		if ( null === $group ) {
			return TranslationStatus::UNTRANSLATED;
		}

		$element = $group->get_translation( $target_lang );
		if ( null === $element ) {
			$status = TranslationStatus::UNTRANSLATED;
		} else {
			$status = $this->resolve_element_status( $element, $group );
		}

		$this->status_cache[ $cache_key ] = $status;

		return $status;
	}

	/**
	 * Resolves the status of an existing TranslationElement belonging to a TranslationGroup.
	 *
	 * @param TranslationElement $element Translation element.
	 * @param TranslationGroup   $group   Parent translation group.
	 * @return string TranslationStatus constant (UPDATED or REVIEW).
	 */
	public function resolve_element_status( TranslationElement $element, TranslationGroup $group ): string {
		$canonical_id = $group->get_canonical_element_id();

		// Canonical element is by definition the sovereign source and is always UPDATED.
		if ( null === $canonical_id || $element->get_element_id() === $canonical_id ) {
			return TranslationStatus::UPDATED;
		}

		$canonical_element = $group->get_canonical_element();
		if ( null === $canonical_element ) {
			return TranslationStatus::UPDATED;
		}

		$source_version_at_trans = $element->get_source_version_at_translation();
		$canonical_current_ver   = $canonical_element->get_current_content_version();

		// If translation was created/updated at or after current canonical version, it is up-to-date.
		if ( $source_version_at_trans >= $canonical_current_ver ) {
			return TranslationStatus::UPDATED;
		}

		// Source content has advanced past the version when translation was produced.
		return TranslationStatus::REVIEW;
	}

	/**
	 * Resolves statuses for all active languages within a translation group in memory (Zero SQL).
	 *
	 * @param TranslationGroup $group Group entity.
	 * @return array<string, string> Map of language_code => TranslationStatus.
	 */
	public function resolve_all_statuses( TranslationGroup $group ): array {
		$statuses     = array();
		$active_langs = $this->language_registry->active();

		foreach ( $active_langs as $code => $lang ) {
			$element = $group->get_translation( $code );
			if ( null === $element ) {
				$statuses[ $code ] = TranslationStatus::UNTRANSLATED;
			} else {
				$statuses[ $code ] = $this->resolve_element_status( $element, $group );
			}
		}

		return $statuses;
	}

	/**
	 * Clears the in-memory runtime cache.
	 *
	 * @return void
	 */
	public function clear_cache(): void {
		$this->status_cache = array();
	}
}
