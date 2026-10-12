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
	 * Classification: Group is partially imported with a validated subset of active languages,
	 * while inactive languages are explicitly retained and tracked.
	 */
	public const CLASSIFICATION_PARTIAL_RETAINED = 'PARTIAL_RETAINED';

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
	 * 3. All selected TRIDs must be classified as CLASSIFICATION_IMPORTABLE or explicitly authorized with retained languages.
	 * 4. The derived plan receives STATUS_READY because all selected groups have active, verified language pairs.
	 * 5. Lineage is cryptographically bound to the parent plan via parent_fingerprint.
	 *
	 * @param array<int, int>                                         $selected_trids      Array of TRIDs to include in the partial/pilot plan.
	 * @param string                                                  $label               Human-readable label for the pilot batch.
	 * @param array<int, array<string, mixed>|bool>|array<int, int>   $authorized_retained Optional authorizations for groups with retained languages.
	 * @return self New immutable partial migration plan.
	 * @throws InvalidArgumentException If selected TRIDs are empty or contain invalid/unimportable groups.
	 */
	public function derive_partial_plan(
		array $selected_trids,
		string $label = 'pilot',
		array $authorized_retained = array()
	): self {
		if ( empty( $selected_trids ) ) {
			throw new InvalidArgumentException( 'No se puede derivar un plan parcial con una lista vacía de TRIDs.' );
		}

		// Normalize authorized retained TRIDs map.
		$normalized_retained = array();
		foreach ( $authorized_retained as $key => $val ) {
			if ( is_int( $val ) ) {
				$normalized_retained[ $val ] = array();
			} elseif ( is_int( $key ) && ( is_array( $val ) || true === $val ) ) {
				$normalized_retained[ $key ] = is_array( $val ) ? $val : array();
			}
		}

		$selected_trids_map = array_fill_keys( array_map( 'intval', $selected_trids ), true );
		$indexed_groups     = array();
		foreach ( $this->planned_groups as $group ) {
			$indexed_groups[ (int) ( $group['trid'] ?? 0 ) ] = $group;
		}

		$filtered_groups        = array();
		$total_elements         = 0;
		$total_pending_elements = 0;
		$partial_retained_count = 0;

		foreach ( array_keys( $selected_trids_map ) as $trid ) {
			if ( ! isset( $indexed_groups[ $trid ] ) ) {
				throw new InvalidArgumentException(
					sprintf( 'El TRID %d no existe en el plan de migración original.', $trid )
				);
			}

			$group          = $indexed_groups[ $trid ];
			$classification = (string) ( $group['classification'] ?? '' );

			if ( self::CLASSIFICATION_IMPORTABLE === $classification ) {
				$filtered_groups[] = $group;
				$total_elements   += count( (array) ( $group['translations'] ?? array() ) );
			} elseif ( self::CLASSIFICATION_BLOCKED_BY_LANGUAGE === $classification && isset( $normalized_retained[ $trid ] ) ) {
				$retained_auth = $normalized_retained[ $trid ];
				$active_trans  = (array) ( $group['translations'] ?? array() );
				$pending_trans = (array) ( $group['pending_translations'] ?? array() );

				if ( empty( $active_trans ) ) {
					throw new InvalidArgumentException(
						sprintf( 'El TRID %d no posee traducciones activas para importar parcialmente.', $trid )
					);
				}

				if ( empty( $pending_trans ) ) {
					throw new InvalidArgumentException(
						sprintf( 'El TRID %d no posee traducciones en idiomas inactivos para retener.', $trid )
					);
				}

				// If specific authorized languages are specified, filter active translations:
				if ( ! empty( $retained_auth['authorized_languages'] ) ) {
					$allowed_langs = array_map( 'strtolower', (array) $retained_auth['authorized_languages'] );
					$active_trans  = array_filter(
						$active_trans,
						fn( string $l ) => in_array( strtolower( $l ), $allowed_langs, true ),
						ARRAY_FILTER_USE_KEY
					);
				}

				$canonical_id   = isset( $group['canonical_id'] ) ? (int) $group['canonical_id'] : null;
				$canonical_lang = isset( $group['canonical_lang'] ) ? (string) $group['canonical_lang'] : null;

				// Invariant: canonical element must be present in the active translations.
				if ( null === $canonical_id || null === $canonical_lang || ! in_array( $canonical_id, $active_trans, true ) ) {
					throw new InvalidArgumentException(
						sprintf( 'El TRID %d no tiene un elemento canónico válido dentro de las traducciones activas autorizadas.', $trid )
					);
				}

				$partial_group                         = $group;
				$partial_group['classification']       = self::CLASSIFICATION_PARTIAL_RETAINED;
				$partial_group['translations']         = $active_trans;
				$partial_group['pending_translations'] = $pending_trans;
				$partial_group['reasons']              = array_merge(
					(array) ( $group['reasons'] ?? array() ),
					array( 'Importación parcial autorizada: subconjunto de idiomas activos listo; idiomas inactivos retenidos.' )
				);

				$filtered_groups[]       = $partial_group;
				$total_elements         += count( $active_trans );
				$total_pending_elements += count( $pending_trans );
				++$partial_retained_count;
			} else {
				throw new InvalidArgumentException(
					sprintf(
						'El TRID %d no es elegible para el plan parcial: su clasificación es "%s" (se requiere "%s"%s).',
						$trid,
						$classification,
						self::CLASSIFICATION_IMPORTABLE,
						self::CLASSIFICATION_BLOCKED_BY_LANGUAGE === $classification
							? ' con autorización explícita para idiomas retenidos'
							: ''
					)
				);
			}
		}

		// Sort filtered groups deterministically by TRID ascending.
		usort( $filtered_groups, fn( array $a, array $b ) => ( (int) $a['trid'] ) <=> ( (int) $b['trid'] ) );

		$derived_summary = array(
			'total_groups'            => count( $filtered_groups ),
			'importable_groups'       => count( $filtered_groups ),
			'partial_retained_groups' => $partial_retained_count,
			'blocked_language_groups' => 0,
			'skipped_groups'          => 0,
			'conflict_groups'         => 0,
			'orphan_groups'           => 0,
			'total_elements'          => $total_elements,
			'importable_elements'     => $total_elements,
			'pending_elements'        => $total_pending_elements,
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
	 * Derives a partial migration plan with explicit support for retained language groups.
	 *
	 * @param array<int, int|array<string, mixed>> $authorized_selections List of TRIDs or authorized specifications.
	 * @param string                               $label                 Human-readable label for the plan.
	 * @return self New immutable partial migration plan.
	 */
	public function derive_partial_plan_with_retained_languages(
		array $authorized_selections,
		string $label = 'partial-retained'
	): self {
		$selected_trids      = array();
		$authorized_retained = array();

		foreach ( $authorized_selections as $item ) {
			if ( is_int( $item ) ) {
				$selected_trids[] = $item;
			} elseif ( is_array( $item ) && isset( $item['trid'] ) ) {
				$trid                         = (int) $item['trid'];
				$selected_trids[]             = $trid;
				$authorized_retained[ $trid ] = $item;
			}
		}

		return $this->derive_partial_plan( $selected_trids, $label, $authorized_retained );
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
