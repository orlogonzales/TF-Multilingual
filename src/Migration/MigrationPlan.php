<?php
/**
 * Migration Plan Value Object.
 *
 * @package TF\Multilingual\Migration
 */

declare( strict_types=1 );

namespace TF\Multilingual\Migration;

/**
 * Class MigrationPlan
 *
 * Immutable specification and manifesto of an end-to-end migration execution plan,
 * defining classification, language dependencies, media strategy, and planned groups.
 */
class MigrationPlan {

	/**
	 * Plan status: Fully ready for execution.
	 */
	public const STATUS_READY = 'READY';

	/**
	 * Plan status: Needs administrative configuration (e.g. missing language activation).
	 */
	public const STATUS_NEEDS_CONFIGURATION = 'NEEDS_CONFIGURATION';

	/**
	 * Plan status: Blocked by critical inconsistencies or unavailable source.
	 */
	public const STATUS_BLOCKED = 'BLOCKED';

	/**
	 * Classification: Group is fully importable.
	 */
	public const CLASSIFICATION_IMPORTABLE = 'IMPORTABLE';

	/**
	 * Classification: Group is blocked by pending/unregistered languages.
	 */
	public const CLASSIFICATION_BLOCKED_BY_LANGUAGE = 'BLOCKED_BY_LANGUAGE';

	/**
	 * Classification: Group is skipped (unsupported type or deliberately excluded).
	 */
	public const CLASSIFICATION_SKIPPED = 'SKIPPED';

	/**
	 * Classification: Group conflicts with pre-existing TFML records.
	 */
	public const CLASSIFICATION_CONFLICT = 'CONFLICT';

	/**
	 * Classification: Group references deleted or non-existent WordPress posts/terms.
	 */
	public const CLASSIFICATION_ORPHAN = 'ORPHAN';

	/**
	 * Source identifier ('wpml', 'polylang').
	 *
	 * @var string
	 */
	private string $source;

	/**
	 * Overall plan status.
	 *
	 * @var string
	 */
	private string $status;

	/**
	 * List of missing language codes that block execution.
	 *
	 * @var array<int, string>
	 */
	private array $missing_languages;

	/**
	 * Plan summary metrics.
	 *
	 * @var array{
	 *     total_groups: int,
	 *     importable_groups: int,
	 *     blocked_language_groups: int,
	 *     skipped_groups: int,
	 *     conflict_groups: int,
	 *     orphan_groups: int,
	 *     total_elements: int,
	 *     importable_elements: int,
	 *     pending_elements: int
	 * }
	 */
	private array $summary;

	/**
	 * Media migration plan according to Media Model B Refinado.
	 *
	 * @var array{
	 *     total_attachment_rows: int,
	 *     strategy: string,
	 *     description: string
	 * }
	 */
	private array $media_plan;

	/**
	 * Detailed list of planned groups.
	 *
	 * @var array<int, array{
	 *     trid: int,
	 *     element_type: string,
	 *     subtype: string,
	 *     canonical_id: ?int,
	 *     canonical_lang: ?string,
	 *     classification: string,
	 *     reasons: array<int, string>,
	 *     translations: array<string, int>,
	 *     pending_translations: array<string, int>
	 * }>
	 */
	private array $planned_groups;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $data Initialization data.
	 */
	public function __construct( array $data ) {
		$this->source            = (string) ( $data['source'] ?? 'wpml' );
		$this->status            = (string) ( $data['status'] ?? self::STATUS_BLOCKED );
		$this->missing_languages = (array) ( $data['missing_languages'] ?? array() );
		$this->summary           = (array) ( $data['summary'] ?? array() );
		$this->media_plan        = (array) ( $data['media_plan'] ?? array() );
		$this->planned_groups    = (array) ( $data['planned_groups'] ?? array() );
	}

	/**
	 * Get source name.
	 *
	 * @return string
	 */
	public function get_source(): string {
		return $this->source;
	}

	/**
	 * Get plan status.
	 *
	 * @return string
	 */
	public function get_status(): string {
		return $this->status;
	}

	/**
	 * Get missing languages.
	 *
	 * @return array<int, string>
	 */
	public function get_missing_languages(): array {
		return $this->missing_languages;
	}

	/**
	 * Get summary statistics.
	 *
	 * @return array<string, int>
	 */
	public function get_summary(): array {
		return $this->summary;
	}

	/**
	 * Get media plan details.
	 *
	 * @return array<string, mixed>
	 */
	public function get_media_plan(): array {
		return $this->media_plan;
	}

	/**
	 * Get all planned groups.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_planned_groups(): array {
		return $this->planned_groups;
	}

	/**
	 * Filter planned groups by classification.
	 *
	 * @param string $classification Classification filter.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_groups_by_classification( string $classification ): array {
		return array_values(
			array_filter(
				$this->planned_groups,
				fn( array $g ) => ( $g['classification'] ?? '' ) === $classification
			)
		);
	}

	/**
	 * Check if plan has blocking missing languages.
	 *
	 * @return bool
	 */
	public function has_missing_languages(): bool {
		return ! empty( $this->missing_languages );
	}

	/**
	 * Convert plan to array for reporting and REST exposure.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'source'            => $this->source,
			'status'            => $this->status,
			'missing_languages' => $this->missing_languages,
			'summary'           => $this->summary,
			'media_plan'        => $this->media_plan,
			'planned_groups'    => $this->planned_groups,
		);
	}
}
