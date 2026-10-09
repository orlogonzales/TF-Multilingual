<?php
/**
 * Migration Inventory Report Value Object.
 *
 * @package TF\Multilingual\Migration
 */

declare( strict_types=1 );

namespace TF\Multilingual\Migration;

/**
 * Class MigrationInventoryReport
 *
 * Represents an immutable, comprehensive diagnostic inventory report of a migration source.
 */
class MigrationInventoryReport {

	/**
	 * Source identifier ('wpml', 'polylang').
	 *
	 * @var string
	 */
	private string $source;

	/**
	 * Diagnostic status: 'ready', 'warning', 'blocked'.
	 *
	 * @var string
	 */
	private string $status;

	/**
	 * Human-readable name.
	 *
	 * @var string
	 */
	private string $source_name;

	/**
	 * Detected languages.
	 *
	 * @var array<int, array{code: string, active_in_source: bool, registered_in_tfml: bool, active_in_tfml: bool}>
	 */
	private array $languages;

	/**
	 * Missing languages that must be registered in TFML.
	 *
	 * @var array<int, string>
	 */
	private array $missing_languages;

	/**
	 * Elements count by element type.
	 *
	 * @var array<string, int>
	 */
	private array $elements_by_type;

	/**
	 * Total translation elements.
	 *
	 * @var int
	 */
	private int $total_elements;

	/**
	 * Total distinct translation groups in source.
	 *
	 * @var int
	 */
	private int $total_groups;

	/**
	 * Translation groups excluding media attachments.
	 *
	 * @var int
	 */
	private int $content_groups;

	/**
	 * Media attachment elements count.
	 *
	 * @var int
	 */
	private int $attachment_elements;

	/**
	 * Conflicts: elements already present in TFML tables.
	 *
	 * @var array<int, array{element_id: int, element_type: string, language: string}>
	 */
	private array $conflicts;

	/**
	 * Orphan elements in source pointing to deleted WordPress posts or terms.
	 *
	 * @var array<int, array{element_id: int, element_type: string, language: string}>
	 */
	private array $orphans;

	/**
	 * Detailed summary notes.
	 *
	 * @var array<int, string>
	 */
	private array $notes;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $data Initialization data.
	 */
	public function __construct( array $data ) {
		$this->source              = (string) ( $data['source'] ?? 'unknown' );
		$this->source_name         = (string) ( $data['source_name'] ?? 'Unknown Source' );
		$this->status              = (string) ( $data['status'] ?? 'blocked' );
		$this->languages           = (array) ( $data['languages'] ?? array() );
		$this->missing_languages   = (array) ( $data['missing_languages'] ?? array() );
		$this->elements_by_type    = (array) ( $data['elements_by_type'] ?? array() );
		$this->total_elements      = (int) ( $data['total_elements'] ?? 0 );
		$this->total_groups        = (int) ( $data['total_groups'] ?? 0 );
		$this->content_groups      = (int) ( $data['content_groups'] ?? 0 );
		$this->attachment_elements = (int) ( $data['attachment_elements'] ?? 0 );
		$this->conflicts           = (array) ( $data['conflicts'] ?? array() );
		$this->orphans             = (array) ( $data['orphans'] ?? array() );
		$this->notes               = (array) ( $data['notes'] ?? array() );
	}

	/**
	 * Get source identifier.
	 *
	 * @return string
	 */
	public function get_source(): string {
		return $this->source;
	}

	/**
	 * Get source name.
	 *
	 * @return string
	 */
	public function get_source_name(): string {
		return $this->source_name;
	}

	/**
	 * Get status.
	 *
	 * @return string
	 */
	public function get_status(): string {
		return $this->status;
	}

	/**
	 * Get detected languages.
	 *
	 * @return array<int, array{code: string, active_in_source: bool, registered_in_tfml: bool, active_in_tfml: bool}>
	 */
	public function get_languages(): array {
		return $this->languages;
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
	 * Get elements by type.
	 *
	 * @return array<string, int>
	 */
	public function get_elements_by_type(): array {
		return $this->elements_by_type;
	}

	/**
	 * Get total elements.
	 *
	 * @return int
	 */
	public function get_total_elements(): int {
		return $this->total_elements;
	}

	/**
	 * Get total groups count.
	 *
	 * @return int
	 */
	public function get_total_groups(): int {
		return $this->total_groups;
	}

	/**
	 * Get content groups count (excluding attachments).
	 *
	 * @return int
	 */
	public function get_content_groups(): int {
		return $this->content_groups;
	}

	/**
	 * Get attachment elements count.
	 *
	 * @return int
	 */
	public function get_attachment_elements(): int {
		return $this->attachment_elements;
	}

	/**
	 * Get conflicts.
	 *
	 * @return array<int, array{element_id: int, element_type: string, language: string}>
	 */
	public function get_conflicts(): array {
		return $this->conflicts;
	}

	/**
	 * Get orphans.
	 *
	 * @return array<int, array{element_id: int, element_type: string, language: string}>
	 */
	public function get_orphans(): array {
		return $this->orphans;
	}

	/**
	 * Get notes.
	 *
	 * @return array<int, string>
	 */
	public function get_notes(): array {
		return $this->notes;
	}

	/**
	 * Convert to array.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'source'              => $this->source,
			'source_name'         => $this->source_name,
			'status'              => $this->status,
			'languages'           => $this->languages,
			'missing_languages'   => $this->missing_languages,
			'elements_by_type'    => $this->elements_by_type,
			'total_elements'      => $this->total_elements,
			'total_groups'        => $this->total_groups,
			'content_groups'      => $this->content_groups,
			'attachment_elements' => $this->attachment_elements,
			'conflicts_count'     => count( $this->conflicts ),
			'conflicts'           => $this->conflicts,
			'orphans_count'       => count( $this->orphans ),
			'orphans'             => $this->orphans,
			'notes'               => $this->notes,
		);
	}
}
