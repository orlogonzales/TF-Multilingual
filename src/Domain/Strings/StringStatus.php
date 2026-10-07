<?php
/**
 * String Status Domain Value Object and Schema Parity Mapper.
 *
 * @package TF\Multilingual\Domain\String
 */

declare( strict_types=1 );

namespace TF\Multilingual\Domain\Strings;

use TF\Multilingual\Domain\Translation\TranslationStatus;

/**
 * Class StringStatus
 *
 * Provides bidirectional mapping and conceptual parity between the physical
 * tfml_string_translations schema ('up_to_date', 'needs_review', 'untranslated')
 * and the sovereign domain model homologated in Phase 2.3 (TranslationStatus::UPDATED,
 * TranslationStatus::REVIEW, TranslationStatus::UNTRANSLATED).
 */
final class StringStatus {

	/**
	 * Database schema values in tfml_string_translations.
	 */
	public const DB_UP_TO_DATE   = 'up_to_date';
	public const DB_NEEDS_REVIEW = 'needs_review';
	public const DB_UNTRANSLATED = 'untranslated';

	/**
	 * Domain status aliases for conceptual coherence with Phase 2.3.
	 */
	public const UNTRANSLATED = TranslationStatus::UNTRANSLATED; // 'untranslated'
	public const UPDATED      = TranslationStatus::UPDATED;      // 'updated'
	public const REVIEW       = TranslationStatus::REVIEW;       // 'review'

	/**
	 * Map of DB schema status to canonical domain status.
	 *
	 * @var array<string, string>
	 */
	private const DB_TO_DOMAIN_MAP = array(
		self::DB_UP_TO_DATE   => TranslationStatus::UPDATED,
		self::DB_NEEDS_REVIEW => TranslationStatus::REVIEW,
		self::DB_UNTRANSLATED => TranslationStatus::UNTRANSLATED,
	);

	/**
	 * Map of canonical domain status to DB schema status.
	 *
	 * @var array<string, string>
	 */
	private const DOMAIN_TO_DB_MAP = array(
		TranslationStatus::UPDATED      => self::DB_UP_TO_DATE,
		TranslationStatus::REVIEW       => self::DB_NEEDS_REVIEW,
		TranslationStatus::UNTRANSLATED => self::DB_UNTRANSLATED,
	);

	/**
	 * Maps a physical database status to its canonical domain status.
	 *
	 * @param string $db_status Status stored in tfml_string_translations.
	 * @return string Canonical domain status ('updated', 'review', 'untranslated').
	 */
	public static function to_domain_status( string $db_status ): string {
		return self::DB_TO_DOMAIN_MAP[ $db_status ] ?? TranslationStatus::UNTRANSLATED;
	}

	/**
	 * Maps a canonical domain status to its physical database status.
	 *
	 * @param string $domain_status Canonical domain status.
	 * @return string Physical schema status ('up_to_date', 'needs_review', 'untranslated').
	 */
	public static function to_db_status( string $domain_status ): string {
		return self::DOMAIN_TO_DB_MAP[ $domain_status ] ?? self::DB_UP_TO_DATE;
	}

	/**
	 * Checks if a given string is a valid physical schema status.
	 *
	 * @param string $status Physical status string.
	 * @return bool
	 */
	public static function is_valid_db_status( string $status ): bool {
		return isset( self::DB_TO_DOMAIN_MAP[ $status ] );
	}

	/**
	 * Gets localized, human-readable label for a status.
	 *
	 * @param string $status Physical DB status or canonical domain status.
	 * @return string
	 */
	public static function get_label( string $status ): string {
		$domain_status = isset( self::DB_TO_DOMAIN_MAP[ $status ] )
			? self::DB_TO_DOMAIN_MAP[ $status ]
			: $status;

		return TranslationStatus::get_label( $domain_status );
	}
}
