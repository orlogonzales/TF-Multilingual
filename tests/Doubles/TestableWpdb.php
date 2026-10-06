<?php
/**
 * Testable wpdb Double.
 *
 * @package TF\Multilingual\Tests\Doubles
 */

declare( strict_types=1 );

namespace TF\Multilingual\Tests\Doubles;

use wpdb;

/**
 * Class TestableWpdb
 *
 * Double for wpdb database operations in domain and repository tests.
 */
class TestableWpdb extends wpdb {

	/**
	 * Simulated table data for tfml_groups.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $groups = array();

	/**
	 * Simulated table data for tfml_group_elements.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $group_elements = array();

	/**
	 * Simulated table data for tfml_media_translations.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $media_translations = array();

	/**
	 * Posts table name.
	 *
	 * @var string
	 */
	public string $posts = 'wp_posts';

	/**
	 * Terms table name.
	 *
	 * @var string
	 */
	public string $terms = 'wp_terms';

	/**
	 * Term taxonomy table name.
	 *
	 * @var string
	 */
	public string $term_taxonomy = 'wp_term_taxonomy';

	/**
	 * Mock posts storage.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $mock_posts = array();

	/**
	 * Mock terms storage.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $mock_terms = array();

	/**
	 * Auto-increment counter.
	 *
	 * @var int
	 */
	private int $auto_increment = 0;

	/**
	 * Next error string to simulate failure.
	 *
	 * @var string|null
	 */
	public ?string $force_next_error = null;

	/**
	 * Resets simulated tables.
	 *
	 * @return void
	 */
	public function reset(): void {
		$this->groups             = array();
		$this->group_elements     = array();
		$this->media_translations = array();
		$this->mock_posts         = array();
		$this->mock_terms         = array();
		$this->auto_increment     = 0;
		$this->last_error         = '';
		$this->force_next_error   = null;
	}

	/**
	 * Simulates insert.
	 *
	 * @param string               $table  Table name.
	 * @param array<string, mixed> $data   Data array.
	 * @param array<string>        $format Format array.
	 * @return int|false
	 */
	public function insert( string $table, array $data, array $format = array() ): int|false {
		if ( null !== $this->force_next_error ) {
			$this->last_error       = $this->force_next_error;
			$this->force_next_error = null;
			return false;
		}

		++$this->auto_increment;
		$id = $this->auto_increment;

		$row       = array_merge( array( 'id' => $id ), $data );
		$row['id'] = $id;

		if ( str_contains( $table, 'tfml_groups' ) ) {
			$this->groups[ $id ] = $row;
			$this->insert_id     = $id;
			return 1;
		}

		if ( str_contains( $table, 'tfml_group_elements' ) ) {
			// Enforce UNIQUE(element_type, element_id).
			foreach ( $this->group_elements as $existing ) {
				if (
					$existing['element_type'] === $row['element_type'] &&
					(int) $existing['element_id'] === (int) $row['element_id']
				) {
					$this->last_error = "Duplicate entry for key 'uq_element'";
					return false;
				}
			}

			// Enforce UNIQUE(group_id, language_code).
			foreach ( $this->group_elements as $existing ) {
				if (
					(int) $existing['group_id'] === (int) $row['group_id'] &&
					$existing['language_code'] === $row['language_code']
				) {
					$this->last_error = "Duplicate entry for key 'uq_group_language'";
					return false;
				}
			}

			$this->group_elements[ $id ] = $row;
			$this->insert_id             = $id;
			return 1;
		}

		if ( str_contains( $table, 'tfml_media_translations' ) ) {
			// Enforce UNIQUE(attachment_id, language_code).
			foreach ( $this->media_translations as $existing ) {
				if (
					(int) $existing['attachment_id'] === (int) $row['attachment_id'] &&
					$existing['language_code'] === $row['language_code']
				) {
					$this->last_error = "Duplicate entry for key 'uq_attachment_language'";
					return false;
				}
			}

			$this->media_translations[ $id ] = $row;
			$this->insert_id                 = $id;
			return 1;
		}

		return 0;
	}

	/**
	 * Simulates update.
	 *
	 * @param string               $table        Table name.
	 * @param array<string, mixed> $data         Data array.
	 * @param array<string, mixed> $where        Where clause.
	 * @param array<string>        $format       Format array.
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

		if ( str_contains( $table, 'tfml_media_translations' ) && isset( $where['id'] ) ) {
			$id = (int) $where['id'];
			if ( isset( $this->media_translations[ $id ] ) ) {
				$this->media_translations[ $id ] = array_merge( $this->media_translations[ $id ], $data );
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

		if ( str_contains( $table, 'tfml_media_translations' ) ) {
			$deleted = 0;
			foreach ( $this->media_translations as $id => $row ) {
				$match = true;
				if ( isset( $where['attachment_id'] ) && (int) $row['attachment_id'] !== (int) $where['attachment_id'] ) {
					$match = false;
				}
				if ( isset( $where['language_code'] ) && $row['language_code'] !== $where['language_code'] ) {
					$match = false;
				}
				if ( $match ) {
					unset( $this->media_translations[ $id ] );
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

		if ( preg_match( '/FROM `?[^` ]*tfml_media_translations`? WHERE `?attachment_id`? = (\d+) AND `?language_code`? = \'([^\']+)\'/i', $query, $matches ) ) {
			$attachment_id = (int) $matches[1];
			$lang          = $matches[2];
			foreach ( $this->media_translations as $row ) {
				if ( (int) $row['attachment_id'] === $attachment_id && $row['language_code'] === $lang ) {
					return ( 'OBJECT' === $output ) ? (object) $row : $row;
				}
			}
		}

		if ( preg_match( '/WHERE `?post_name`? = \'([^\']+)\'/i', $query, $matches ) ) {
			$slug = $matches[1];
			foreach ( $this->mock_posts as $row ) {
				if ( $row['post_name'] === $slug ) {
					return $row;
				}
			}
		}

		if ( preg_match( '/WHERE t\.slug = \'([^\']+)\'/i', $query, $matches ) ) {
			$slug = $matches[1];
			foreach ( $this->mock_terms as $row ) {
				if ( $row['slug'] === $slug ) {
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

		if ( preg_match( '/SELECT COUNT\(\*\) FROM `?[^` ]*tfml_media_translations`? WHERE `?attachment_id`? = (\d+) AND `?language_code`? = \'([^\']+)\'/i', $query, $matches ) ) {
			$attachment_id = (int) $matches[1];
			$lang          = $matches[2];
			$count         = 0;
			foreach ( $this->media_translations as $row ) {
				if ( (int) $row['attachment_id'] === $attachment_id && $row['language_code'] === $lang ) {
					++$count;
				}
			}
			return $count;
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
		if ( preg_match( '/FROM `?[^` ]*tfml_group_elements`? WHERE `?element_type`? = \'([^\']+)\' AND `?element_id`? IN \(([^)]+)\)/i', $query, $matches ) ) {
			$type    = $matches[1];
			$ids     = array_map( 'intval', explode( ',', $matches[2] ) );
			$results = array();
			foreach ( $this->group_elements as $row ) {
				if ( $row['element_type'] === $type && in_array( (int) $row['element_id'], $ids, true ) ) {
					$results[] = ( 'OBJECT' === $output ) ? (object) $row : $row;
				}
			}
			return $results;
		}

		if ( preg_match( '/FROM `?[^` ]*tfml_groups`? WHERE `?id`? IN \(([^)]+)\)/i', $query, $matches ) ) {
			$ids     = array_map( 'intval', explode( ',', $matches[1] ) );
			$results = array();
			foreach ( $this->groups as $id => $row ) {
				if ( in_array( (int) $id, $ids, true ) ) {
					$results[] = ( 'OBJECT' === $output ) ? (object) $row : $row;
				}
			}
			return $results;
		}

		if ( preg_match( '/FROM `?[^` ]*tfml_group_elements`? WHERE `?group_id`? IN \(([^)]+)\)/i', $query, $matches ) ) {
			$ids     = array_map( 'intval', explode( ',', $matches[1] ) );
			$results = array();
			foreach ( $this->group_elements as $row ) {
				if ( in_array( (int) $row['group_id'], $ids, true ) ) {
					$results[] = ( 'OBJECT' === $output ) ? (object) $row : $row;
				}
			}
			return $results;
		}

		if ( preg_match( '/FROM `?[^` ]*tfml_group_elements`? WHERE `?group_id`? = (\d+)/i', $query, $matches ) ) {
			$group_id = (int) $matches[1];
			$results  = array();
			foreach ( $this->group_elements as $row ) {
				if ( (int) $row['group_id'] === $group_id ) {
					$results[] = ( 'OBJECT' === $output ) ? (object) $row : $row;
				}
			}
			return $results;
		}

		if ( preg_match( '/FROM `?[^` ]*tfml_media_translations`? WHERE `?attachment_id`? IN \(([^)]+)\)/i', $query, $matches ) ) {
			$ids     = array_map( 'intval', explode( ',', $matches[1] ) );
			$results = array();
			foreach ( $this->media_translations as $row ) {
				if ( in_array( (int) $row['attachment_id'], $ids, true ) ) {
					$results[] = ( 'OBJECT' === $output ) ? (object) $row : $row;
				}
			}
			return $results;
		}

		if ( preg_match( '/FROM `?[^` ]*tfml_media_translations`? WHERE `?attachment_id`? = (\d+)/i', $query, $matches ) ) {
			$attachment_id = (int) $matches[1];
			$results       = array();
			foreach ( $this->media_translations as $row ) {
				if ( (int) $row['attachment_id'] === $attachment_id ) {
					$results[] = ( 'OBJECT' === $output ) ? (object) $row : $row;
				}
			}
			return $results;
		}

		return array();
	}

	/**
	 * Simulates query execution.
	 *
	 * @param string $query SQL query.
	 * @return int|bool
	 */
	public function query( string $query ): int|bool {
		return true;
	}
}
