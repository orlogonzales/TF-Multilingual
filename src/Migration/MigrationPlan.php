<?php
/**
 * Migration Plan Value Object.
 *
 * @package TF\Multilingual\Migration
 */

declare( strict_types=1 );

namespace TF\Multilingual\Migration;

use InvalidArgumentException;

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
	 * Cryptographic fingerprint of the parent plan if derived as a partial/pilot plan.
	 *
	 * @var string|null
	 */
	private ?string $parent_fingerprint;

	/**
	 * Whether this plan is an explicitly derived partial/pilot plan.
	 *
	 * @var bool
	 */
	private bool $is_partial;

	/**
	 * Optional label describing the partial/pilot plan.
	 *
	 * @var string
	 */
	private string $label;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $data Initialization data.
	 */
	public function __construct( array $data ) {
		$this->source             = (string) ( $data['source'] ?? 'wpml' );
		$this->status             = (string) ( $data['status'] ?? self::STATUS_BLOCKED );
		$this->missing_languages  = (array) ( $data['missing_languages'] ?? array() );
		$this->summary            = (array) ( $data['summary'] ?? array() );
		$this->media_plan         = (array) ( $data['media_plan'] ?? array() );
		$this->planned_groups     = (array) ( $data['planned_groups'] ?? array() );
		$this->parent_fingerprint = isset( $data['parent_fingerprint'] ) ? (string) $data['parent_fingerprint'] : null;
		$this->is_partial         = ! empty( $data['is_partial'] );
		$this->label              = (string) ( $data['label'] ?? '' );
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
	 * Checks whether this plan is an explicitly derived partial/pilot plan.
	 *
	 * @return bool
	 */
	public function is_partial(): bool {
		return $this->is_partial;
	}

	/**
	 * Gets parent plan cryptographic fingerprint if partial.
	 *
	 * @return string|null
	 */
	public function get_parent_fingerprint(): ?string {
		return $this->parent_fingerprint;
	}

	/**
	 * Gets the label assigned to this plan.
	 *
	 * @return string
	 */
	public function get_label(): string {
		return $this->label;
	}

	/**
	 * Derives a valid, partial/pilot migration plan for an explicit subset of importable groups.
	 *
	 * Guarantees that:
	 * 1. The original plan is untouched and preserved.
	 * 2. All selected TRIDs must exist in the original plan.
	 * 3. All selected TRIDs must be classified as CLASSIFICATION_IMPORTABLE (no blocked/orphan/skipped groups allowed).
	 * 4. The derived plan receives STATUS_READY because all selected groups have active, verified language pairs.
	 * 5. Lineage is cryptographically bound to the parent plan via parent_fingerprint.
	 *
	 * @param array<int, int> $selected_trids Array of TRIDs to include in the partial/pilot plan.
	 * @param string          $label          Human-readable label for the pilot batch.
	 * @return self New immutable partial migration plan.
	 * @throws InvalidArgumentException If selected TRIDs are empty or contain invalid/unimportable groups.
	 */
	public function derive_partial_plan( array $selected_trids, string $label = 'pilot' ): self {
		if ( empty( $selected_trids ) ) {
			throw new InvalidArgumentException( 'No se puede derivar un plan parcial con una lista vacía de TRIDs.' );
		}

		$selected_trids_map = array_fill_keys( array_map( 'intval', $selected_trids ), true );
		$indexed_groups     = array();
		foreach ( $this->planned_groups as $group ) {
			$indexed_groups[ (int) ( $group['trid'] ?? 0 ) ] = $group;
		}

		$filtered_groups = array();
		$total_elements  = 0;

		foreach ( array_keys( $selected_trids_map ) as $trid ) {
			if ( ! isset( $indexed_groups[ $trid ] ) ) {
				throw new InvalidArgumentException(
					sprintf( 'El TRID %d no existe en el plan de migración original.', $trid )
				);
			}

			$group = $indexed_groups[ $trid ];
			if ( self::CLASSIFICATION_IMPORTABLE !== ( $group['classification'] ?? '' ) ) {
				throw new InvalidArgumentException(
					sprintf(
						'El TRID %d no es elegible para el plan parcial: su clasificación es "%s" (se requiere "%s").',
						$trid,
						$group['classification'] ?? '',
						self::CLASSIFICATION_IMPORTABLE
					)
				);
			}

			$filtered_groups[] = $group;
			$total_elements   += count( (array) ( $group['translations'] ?? array() ) );
		}

		// Sort filtered groups deterministically by TRID ascending.
		usort( $filtered_groups, fn( array $a, array $b ) => ( (int) $a['trid'] ) <=> ( (int) $b['trid'] ) );

		$derived_summary = array(
			'total_groups'            => count( $filtered_groups ),
			'importable_groups'       => count( $filtered_groups ),
			'blocked_language_groups' => 0,
			'skipped_groups'          => 0,
			'conflict_groups'         => 0,
			'orphan_groups'           => 0,
			'total_elements'          => $total_elements,
			'importable_elements'     => $total_elements,
			'pending_elements'        => 0,
		);

		return new self(
			array(
				'source'             => $this->source,
				'status'             => self::STATUS_READY,
				'missing_languages'  => array(),
				'summary'            => $derived_summary,
				'media_plan'         => array(
					'total_attachment_rows' => 0,
					'strategy'              => $this->media_plan['strategy'] ?? 'MEDIA_MODEL_B_DEDUPLICATION',
					'description'           => 'Plan parcial derivado: gestión de adjuntos según plan matriz.',
				),
				'planned_groups'     => $filtered_groups,
				'parent_fingerprint' => $this->get_fingerprint(),
				'is_partial'         => true,
				'label'              => $label,
			)
		);
	}

	/**
	 * Computes a deterministic cryptographic fingerprint of the migration plan.
	 *
	 * Detects alterations in plan structure, source, language dependencies, or group definitions.
	 *
	 * @return string SHA-256 fingerprint.
	 */
	public function get_fingerprint(): string {
		$payload = array(
			'source'            => $this->source,
			'status'            => $this->status,
			'missing_languages' => $this->missing_languages,
			'summary'           => $this->summary,
			'media_plan'        => $this->media_plan,
			'planned_groups'    => $this->planned_groups,
		);

		if ( $this->is_partial ) {
			$payload['parent_fingerprint'] = $this->parent_fingerprint;
			$payload['is_partial']         = true;
			$payload['label']              = $this->label;
		}

		$encoded = function_exists( 'wp_json_encode' ) ? wp_json_encode( $payload ) : json_encode( $payload );

		return hash( 'sha256', (string) $encoded );
	}

	/**
	 * Verifies whether an expected fingerprint matches this plan.
	 *
	 * @param string $expected Expected SHA-256 fingerprint.
	 * @return bool True if fingerprints match identically.
	 */
	public function verify_fingerprint( string $expected ): bool {
		return hash_equals( $this->get_fingerprint(), $expected );
	}

	/**
	 * Convert plan to array for reporting and REST exposure.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		$data = array(
			'source'            => $this->source,
			'status'            => $this->status,
			'missing_languages' => $this->missing_languages,
			'summary'           => $this->summary,
			'media_plan'        => $this->media_plan,
			'planned_groups'    => $this->planned_groups,
			'fingerprint'       => $this->get_fingerprint(),
		);

		if ( $this->is_partial ) {
			$data['parent_fingerprint'] = $this->parent_fingerprint;
			$data['is_partial']         = true;
			$data['label']              = $this->label;
		}

		return $data;
	}
}
