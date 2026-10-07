<?php
/**
 * String Translation Domain Service.
 *
 * @package TF\Multilingual\Domain\String
 */

declare( strict_types=1 );

namespace TF\Multilingual\Domain\Strings;

use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Domain\Language\Exceptions\LanguageNotFoundException;
use TF\Multilingual\Domain\Language\Exceptions\InvalidLanguageException;
use TF\Multilingual\Routing\CurrentLanguageResolver;

/**
 * Class StringTranslationService
 *
 * Sovereign service for translating registered interface strings in memory,
 * enforcing explicit language authority, strict fallback to source text (Zero lateral fallback),
 * and Zero N+1 preloading.
 */
class StringTranslationService {

	/**
	 * String repository.
	 *
	 * @var StringRepository
	 */
	private StringRepository $repository;

	/**
	 * Language registry.
	 *
	 * @var LanguageRegistry
	 */
	private LanguageRegistry $language_registry;

	/**
	 * Current language resolver.
	 *
	 * @var CurrentLanguageResolver
	 */
	private CurrentLanguageResolver $current_language_resolver;

	/**
	 * In-memory buffer of string IDs observed during this request for batched last_seen_at updates.
	 *
	 * @var array<int, bool>
	 */
	private array $observed_strings = array();

	/**
	 * Constructor.
	 *
	 * @param StringRepository        $repository                String repository.
	 * @param LanguageRegistry        $language_registry        Language registry.
	 * @param CurrentLanguageResolver $current_language_resolver Current language resolver.
	 */
	public function __construct(
		StringRepository $repository,
		LanguageRegistry $language_registry,
		CurrentLanguageResolver $current_language_resolver
	) {
		$this->repository                = $repository;
		$this->language_registry         = $language_registry;
		$this->current_language_resolver = $current_language_resolver;
	}

	/**
	 * Translates an interface string identified by domain and key.
	 *
	 * Fallback rules:
	 * 1. Default language -> returns default/source text without DB overhead.
	 * 2. Secondary active language -> returns translated text if exists.
	 * 3. Status 'needs_review' (REVIEW) -> returns translated text in frontend (ensuring visual continuity).
	 * 4. Missing translation (UNTRANSLATED) -> returns default/source text.
	 * 5. Inactive language -> returns default/source text (No silent lateral fallback).
	 * 6. ZERO lateral fallback between secondary languages.
	 *
	 * @param string      $domain        String domain (e.g. 'travel-flow').
	 * @param string      $key           Semantic key (e.g. 'booking.button.confirm').
	 * @param string      $default_value Default/source text to return if untranslated.
	 * @param string|null $language_code Explicit language code or null for current request language.
	 * @param string      $context       Optional context.
	 * @return string Translated text or default source text.
	 */
	public function translate(
		string $domain,
		string $key,
		string $default_value = '',
		?string $language_code = null,
		string $context = ''
	): string {
		$domain = trim( $domain );
		$key    = trim( $key );

		if ( '' === $domain || '' === $key ) {
			return $default_value;
		}

		$target_language = $this->resolve_target_language( $language_code );

		// If no language resolved or target language is the sovereign default language, return source text.
		if ( null === $target_language || $this->is_default_language( $target_language ) ) {
			return $default_value;
		}

		// Check in-memory preloaded cache first (Zero queries).
		$translation = $this->repository->get_preloaded_translation( $domain, $key, $target_language );

		if ( null === $translation ) {
			// If domain was not preloaded, fetch string and translation on-demand.
			$string = $this->repository->find_by_domain_and_key( $domain, $key );
			if ( null === $string ) {
				return $default_value;
			}

			$this->track_observed_string( $string->get_id() );
			$translation = $this->repository->get_translation( $string->get_id(), $target_language );
		}

		if ( null === $translation ) {
			// UNTRANSLATED: Strict fallback to default source text. Zero lateral fallback.
			return $default_value;
		}

		$translated_value = $translation->get_translated_value();
		if ( null === $translated_value || '' === trim( $translated_value ) ) {
			return $default_value;
		}

		return $translated_value;
	}

	/**
	 * Explicitly registers an interface string in persistence.
	 *
	 * @param string $domain          String domain.
	 * @param string $key             Stable semantic key.
	 * @param string $original_value  Original source text.
	 * @param string $context         Optional context.
	 * @param string $source_language Source language.
	 * @return TranslatableString
	 */
	public function register_string(
		string $domain,
		string $key,
		string $original_value,
		string $context = '',
		string $source_language = 'es'
	): TranslatableString {
		return $this->repository->register( $domain, $key, $original_value, $context, $source_language );
	}

	/**
	 * Preloads all strings and translations for a domain into in-memory cache.
	 *
	 * Executes in strictly O(1) queries, ensuring zero DB queries during subsequent lookups.
	 *
	 * @param string      $domain        String domain.
	 * @param string|null $language_code Optional language filter.
	 * @return void
	 */
	public function preload_domain( string $domain, ?string $language_code = null ): void {
		$this->repository->preload_domain( $domain, $language_code );
	}

	/**
	 * Saves or updates a translation for a registered string.
	 *
	 * @param int         $string_id        Referenced string ID.
	 * @param string      $language_code    Target language code.
	 * @param string|null $translated_value Translated value.
	 * @param string|null $status           Optional status.
	 * @return StringTranslation
	 */
	public function save_translation(
		int $string_id,
		string $language_code,
		?string $translated_value,
		?string $status = null
	): StringTranslation {
		return $this->repository->save_translation( $string_id, $language_code, $translated_value, $status );
	}

	/**
	 * Verifies whether placeholders in translation match the source text.
	 *
	 * Detects %s, %d, %1$s, %2$d, etc. to prevent runtime formatting crashes.
	 *
	 * @param string $source      Source text.
	 * @param string $translation Translated text.
	 * @return bool True if placeholders are compatible.
	 */
	public function has_matching_placeholders( string $source, string $translation ): bool {
		$pattern = '/%(?:[0-9]+\$)?[+-]?(?:[ 0]|\'.)?-?[0-9]*(?:\.[0-9]+)?[bcdeEfFgGosuxX]/';

		preg_match_all( $pattern, $source, $source_matches );
		preg_match_all( $pattern, $translation, $trans_matches );

		$source_placeholders = $source_matches[0] ?? array();
		$trans_placeholders  = $trans_matches[0] ?? array();

		if ( empty( $source_placeholders ) && empty( $trans_placeholders ) ) {
			return true;
		}

		// Number of format tokens must match.
		if ( count( $source_placeholders ) !== count( $trans_placeholders ) ) {
			return false;
		}

		// Sort and compare tokens.
		$sorted_source = $source_placeholders;
		$sorted_trans  = $trans_placeholders;
		sort( $sorted_source );
		sort( $sorted_trans );

		return $sorted_source === $sorted_trans;
	}

	/**
	 * Flushes observed strings last_seen_at in batch (e.g. on shutdown).
	 *
	 * @return void
	 */
	public function flush_observed_strings(): void {
		if ( empty( $this->observed_strings ) ) {
			return;
		}

		$ids                    = array_keys( $this->observed_strings );
		$this->observed_strings = array();
		$this->repository->touch_last_seen( $ids );
	}

	/**
	 * Resolves the target language code.
	 *
	 * @param string|null $language_code Explicit language code or null.
	 * @return string|null Resolved canonical language code or null.
	 */
	private function resolve_target_language( ?string $language_code ): ?string {
		if ( null !== $language_code && '' !== trim( $language_code ) ) {
			$canonical = trim( $language_code );
			if ( ! $this->language_registry->has( $canonical ) ) {
				return null;
			}

			$lang = $this->language_registry->get( $canonical );
			if ( ! $lang->is_active() ) {
				return null; // Inactive language: strict fallback to default without lateral degradation.
			}

			return $canonical;
		}

		return $this->current_language_resolver->get_current_language();
	}

	/**
	 * Checks if a language code is the configured default language.
	 *
	 * @param string $language_code Language code.
	 * @return bool
	 */
	private function is_default_language( string $language_code ): bool {
		$default = $this->language_registry->get_default();
		return null !== $default && $default->get_code() === $language_code;
	}

	/**
	 * Buffers an observed string ID in memory for batched touch.
	 *
	 * @param int $string_id String ID.
	 * @return void
	 */
	private function track_observed_string( int $string_id ): void {
		$this->observed_strings[ $string_id ] = true;
	}
}
