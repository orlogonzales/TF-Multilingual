<?php
/**
 * Testable wpdb double for unit testing.
 *
 * @package TF\Multilingual\Tests\Doubles
 */

declare( strict_types=1 );

namespace TF\Multilingual\Tests\Doubles;

use wpdb;

/**
 * Class TestableWpdb
 *
 * In-memory test double for wpdb simulating relational tables tfml_groups and tfml_group_elements.
 */
class TestableWpdb extends wpdb {

	/**
	 * In-memory rows for groups table.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $groups = array();

	/**
	 * In-memory rows for group elements table.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $group_elements = array();

	/**
	 * Auto-increment counter.
	 *
	 * @var int
	 */
	private int $auto_increment = 0;

	/**
	 * Force error on next insert for concurrency testing.
	 *
	 * @var string|null
	 */
	public ?string $force_next_error = null;

	/**
	 * Reset in-memory database.
	 */
	public function reset(): void {
		$this->groups           = array();
		$this->group_elements   = array();
		$this->auto_increment   = 0;
		$this->last_error       = '';
		$this->force_next_error = null;
	}

	/**
	 * Simulates insert.
	 *
	 * @param string               $table  Table name.
	 * @param array<string, mixed> $data   Row data.
	 * @param array<string>        $format Formats.
	 * @return int|false
	 */
	public function insert( string $table, array $data, array $format = array() ): int|false {
		if ( null !== $this->force_next_error ) {
			$this->last_error       = $this->force_next_error;
			$this->force_next_error = null;
			return false;
		}

		++$this->auto_increment;
		$id              = $this->auto_increment;
		$data['id']      = $id;
		$this->insert_id = $id;

		if ( str_contains( $table, 'tfml_groups' ) ) {
			$this->groups[ $id ] = $data;
			return 1;
		}

		if ( str_contains( $table, 'tfml_group_elements' ) ) {
			// Check unique constraints.
			foreach ( $this->group_elements as $existing ) {
				if (
					$existing['element_type'] === $data['element_type'] &&
					(int) $existing['element_id'] === (int) $data['element_id']
				) {
					$this->last_error = "Duplicate entry '{$data['element_type']}-{$data['element_id']}' for key 'uq_element'";
					return false;
				}

				if (
					(int) $existing['group_id'] === (int) $data['group_id'] &&
					$existing['language_code'] === $data['language_code']
				) {
					$this->last_error = "Duplicate entry '{$data['group_id']}-{$data['language_code']}' for key 'uq_group_language'";
					return false;
				}
			}

			$this->group_elements[ $id ] = $data;
			return 1;
		}

		return 1;
	}

	/**
	 * Simulates update.
	 *
	 * @param string               $table        Table name.
	 * @param array<string, mixed> $data         Row data.
	 * @param array<string, mixed> $where        Where clause.
	 * @param array<string>        $format       Format.
	 * @param array<string>        $where_format Where format.
	 * @return int|false
	 */
	public function update(
		string $table,
		array $data,
		array $where,
		array $format = array(),
		array $where_format = array()
	): int|false {
		if ( str_contains( $table, 'tfml_groups' ) && isset( $where['id'] ) ) {
			$id = (int) $where['id'];
			if ( isset( $this->groups[ $id ] ) ) {
				$this->groups[ $id ] = array_merge( $this->groups[ $id ], $data );
				return 1;
			}
		}

		return 0;
	}

	/**
	 * Simulates delete.
	 *
	 * @param string               $table        Table name.
	 * @param array<string, mixed> $where        Where clause.
	 * @param array<string>        $where_format Where format.
	 * @return int|false
	 */
	public function delete( string $table, array $where, array $where_format = array() ): int|false {
		if ( str_contains( $table, 'tfml_groups' ) && isset( $where['id'] ) ) {
			$id = (int) $where['id'];
			if ( isset( $this->groups[ $id ] ) ) {
				unset( $this->groups[ $id ] );
				return 1;
			}
		}

		if ( str_contains( $table, 'tfml_group_elements' ) ) {
			$deleted = 0;
			foreach ( $this->group_elements as $id => $row ) {
				$match = true;
				if ( isset( $where['group_id'] ) && (int) $row['group_id'] !== (int) $where['group_id'] ) {
					$match = false;
				}
				if ( isset( $where['language_code'] ) && $row['language_code'] !== $where['language_code'] ) {
					$match = false;
				}
				if ( $match ) {
					unset( $this->group_elements[ $id ] );
					++$deleted;
				}
			}
			return $deleted;
		}

		return 0;
	}

	/**
	 * Simulates get_row.
	 *
	 * @param string $query  SQL query.
	 * @param string $output Output format.
	 * @return mixed
	 */
	public function get_row( string $query, string $output = 'OBJECT' ): mixed {
		if ( preg_match( '/FROM `?[^` ]*tfml_groups`? WHERE `?id`? = (\d+)/i', $query, $matches ) ) {
			$id = (int) $matches[1];
			return $this->groups[ $id ] ?? null;
		}

		if ( preg_match( '/FROM `?[^` ]*tfml_group_elements`? WHERE `?group_id`? = (\d+) AND `?language_code`? = \'([^\']+)\'/i', $query, $matches ) ) {
			$group_id = (int) $matches[1];
			$lang     = $matches[2];
			foreach ( $this->group_elements as $row ) {
				if ( (int) $row['group_id'] === $group_id && $row['language_code'] === $lang ) {
					return $row;
				}
			}
		}

		return null;
	}

	/**
	 * Simulates get_var.
	 *
	 * @param string $query SQL query.
	 * @return mixed
	 */
	public function get_var( string $query ): mixed {
		if ( preg_match( '/FROM `?[^` ]*tfml_group_elements`? WHERE `?element_type`? = \'([^\']+)\' AND `?element_id`? = (\d+)/i', $query, $matches ) ) {
			$type       = $matches[1];
			$element_id = (int) $matches[2];
			foreach ( $this->group_elements as $row ) {
				if ( $row['element_type'] === $type && (int) $row['element_id'] === $element_id ) {
					return $row['group_id'];
				}
			}
		}

		return null;
	}

	/**
	 * Simulates get_results.
	 *
	 * @param string $query  SQL query.
	 * @param string $output Output format.
	 * @return mixed
	 */
	public function get_results( string $query, string $output = 'OBJECT' ): mixed {
		if ( preg_match( '/FROM `?[^` ]*tfml_group_elements`? WHERE `?group_id`? = (\d+)/i', $query, $matches ) ) {
			$group_id = (int) $matches[1];
			$results  = array();
			foreach ( $this->group_elements as $row ) {
				if ( (int) $row['group_id'] === $group_id ) {
					$results[] = $row;
				}
			}
			return $results;
		}

		return array();
	}
}
