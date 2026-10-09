<?php
/**
 * Database Schema Manager.
 *
 * @package TF\Multilingual\Infrastructure\Persistence
 */

declare( strict_types=1 );

namespace TF\Multilingual\Infrastructure\Persistence;

use wpdb;
use RuntimeException;

/**
 * Class SchemaManager
 *
 * Manages database schema installation, verification and version migrations.
 */
class SchemaManager {

	/**
	 * Technical database schema version.
	 */
	public const SCHEMA_VERSION = '1.0.0';

	/**
	 * Option name storing the installed schema version.
	 */
	public const OPTION_SCHEMA_VERSION = 'tfml_schema_version';

	/**
	 * Canonical table suffixes managed by TF Multilingual.
	 */
	public const TABLES = array(
		'groups'              => 'tfml_groups',
		'group_elements'      => 'tfml_group_elements',
		'media_translations'  => 'tfml_media_translations',
		'strings'             => 'tfml_strings',
		'string_translations' => 'tfml_string_translations',
	);

	/**
	 * WordPress database instance.
	 *
	 * @var wpdb
	 */
	private wpdb $db;

	/**
	 * Constructor.
	 *
	 * @param wpdb|null $db Optional wpdb instance.
	 */
	public function __construct( ?wpdb $db = null ) {
		if ( null === $db ) {
			global $wpdb;
			$db = $wpdb;
		}

		$this->db = $db;
	}

	/**
	 * Gets the required technical schema version defined in code.
	 *
	 * @return string
	 */
	public function get_required_schema_version(): string {
		return self::SCHEMA_VERSION;
	}

	/**
	 * Gets the currently installed schema version stored in options.
	 *
	 * @return string|null
	 */
	public function get_installed_schema_version(): ?string {
		$version = get_option( self::OPTION_SCHEMA_VERSION, null );

		return is_string( $version ) && '' !== trim( $version ) ? $version : null;
	}

	/**
	 * Checks if schema installation or upgrade is needed.
	 *
	 * @return bool
	 */
	public function needs_upgrade(): bool {
		$installed = $this->get_installed_schema_version();
		if ( null === $installed ) {
			return true;
		}

		return version_compare( $installed, self::SCHEMA_VERSION, '<' );
	}

	/**
	 * Resolves the full table name for a canonical suffix using the current table prefix.
	 *
	 * @param string $suffix Table key or suffix (e.g. 'groups' or 'tfml_groups').
	 * @return string Full table name with db prefix.
	 */
	public function get_table_name( string $suffix ): string {
		if ( isset( self::TABLES[ $suffix ] ) ) {
			return $this->db->prefix . self::TABLES[ $suffix ];
		}

		// If full suffix provided (e.g. tfml_groups).
		if ( in_array( $suffix, self::TABLES, true ) ) {
			return $this->db->prefix . $suffix;
		}

		throw new RuntimeException(
			sprintf( 'Unknown table suffix "%s" requested in SchemaManager.', esc_html( $suffix ) )
		);
	}

	/**
	 * Returns all managed table names with current prefix.
	 *
	 * @return array<string, string>
	 */
	public function get_all_table_names(): array {
		$tables = array();
		foreach ( self::TABLES as $key => $suffix ) {
			$tables[ $key ] = $this->db->prefix . $suffix;
		}

		return $tables;
	}

	/**
	 * Builds the DDL SQL string for all five master tables.
	 *
	 * Adheres strictly to WordPress dbDelta requirements:
	 * - Each field on its own line.
	 * - Two spaces after PRIMARY KEY.
	 * - KEY definitions on own line.
	 * - No quotes around table or column names.
	 * - Trailing semicolon per CREATE TABLE.
	 *
	 * @return string Full SQL DDL string.
	 */
	public function get_schema_sql(): string {
		$charset_collate = $this->db->get_charset_collate();

		$table_groups              = $this->get_table_name( 'groups' );
		$table_group_elements      = $this->get_table_name( 'group_elements' );
		$table_media_translations  = $this->get_table_name( 'media_translations' );
		$table_strings             = $this->get_table_name( 'strings' );
		$table_string_translations = $this->get_table_name( 'string_translations' );

		return "CREATE TABLE {$table_groups} (
  id bigint(20) unsigned NOT NULL auto_increment,
  element_type varchar(20) NOT NULL default '',
  subtype varchar(32) NOT NULL default '',
  canonical_element_id bigint(20) unsigned default NULL,
  created_at datetime NOT NULL default '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  KEY idx_type_subtype (element_type,subtype)
) {$charset_collate};
CREATE TABLE {$table_group_elements} (
  id bigint(20) unsigned NOT NULL auto_increment,
  group_id bigint(20) unsigned NOT NULL default 0,
  element_type varchar(20) NOT NULL default '',
  element_id bigint(20) unsigned NOT NULL default 0,
  language_code varchar(10) NOT NULL default '',
  source_version_at_translation int(10) unsigned NOT NULL default 1,
  current_content_version int(10) unsigned NOT NULL default 1,
  translatable_fingerprint varchar(128) NOT NULL default '',
  updated_at datetime NOT NULL default '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  UNIQUE KEY uq_element (element_type,element_id),
  UNIQUE KEY uq_group_language (group_id,language_code),
  KEY idx_lookup (element_type,language_code,element_id),
  KEY idx_group (group_id)
) {$charset_collate};
CREATE TABLE {$table_media_translations} (
  id bigint(20) unsigned NOT NULL auto_increment,
  attachment_id bigint(20) unsigned NOT NULL default 0,
  language_code varchar(10) NOT NULL default '',
  alt_text text,
  title text,
  caption longtext,
  description longtext,
  updated_at datetime NOT NULL default '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  UNIQUE KEY uq_attachment_language (attachment_id,language_code),
  KEY idx_language (language_code)
) {$charset_collate};
CREATE TABLE {$table_strings} (
  id bigint(20) unsigned NOT NULL auto_increment,
  domain varchar(100) NOT NULL default 'default',
  string_key varchar(191) NOT NULL default '',
  context varchar(100) NOT NULL default '',
  original_value longtext NOT NULL,
  source_language varchar(10) NOT NULL default 'es',
  string_version int(10) unsigned NOT NULL default 1,
  has_conflict tinyint(1) NOT NULL default 0,
  last_seen_at datetime NOT NULL default '0000-00-00 00:00:00',
  created_at datetime NOT NULL default '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  UNIQUE KEY uq_domain_key (domain,string_key),
  KEY idx_context (context),
  KEY idx_last_seen (last_seen_at)
) {$charset_collate};
CREATE TABLE {$table_string_translations} (
  id bigint(20) unsigned NOT NULL auto_increment,
  string_id bigint(20) unsigned NOT NULL default 0,
  language_code varchar(10) NOT NULL default '',
  translated_value longtext,
  source_version_translated int(10) unsigned NOT NULL default 1,
  status varchar(20) NOT NULL default 'up_to_date',
  updated_at datetime NOT NULL default '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  UNIQUE KEY uq_string_lang (string_id,language_code),
  KEY idx_lang (language_code)
) {$charset_collate};";
	}

	/**
	 * Installs or upgrades the database schema idempotently.
	 *
	 * @return bool True if schema is successfully verified and installed.
	 * @throws RuntimeException If table creation or verification fails.
	 */
	public function install(): bool {
		if ( ! function_exists( 'dbDelta' ) ) {
			$upgrade_file = ABSPATH . 'wp-admin/includes/upgrade.php';
			if ( file_exists( $upgrade_file ) ) {
				require_once $upgrade_file;
			} else {
				throw new RuntimeException( 'WordPress upgrade.php could not be found to execute dbDelta.' );
			}
		}

		$sql = $this->get_schema_sql();
		dbDelta( $sql );

		// Physical verification gate: confirm all 5 tables exist in the database.
		$missing = $this->get_missing_tables();
		if ( ! empty( $missing ) ) {
			throw new RuntimeException(
				sprintf(
					'Database schema installation failed. Missing tables: %s',
					implode( ', ', $missing )
				)
			);
		}

		// Record schema version in Options API only after verification.
		update_option( self::OPTION_SCHEMA_VERSION, self::SCHEMA_VERSION, false );

		return true;
	}

	/**
	 * Drops all managed database tables in reverse dependency order.
	 *
	 * CAUTION: Destructive operation. Should only be called when explicit purge is requested.
	 *
	 * @return void
	 */
	public function drop_tables(): void {
		$table_keys = array(
			'string_translations',
			'strings',
			'media_translations',
			'group_elements',
			'groups',
		);

		foreach ( $table_keys as $key ) {
			$table_name = $this->get_table_name( $key );
			$this->db->query( "DROP TABLE IF EXISTS {$table_name}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		delete_option( self::OPTION_SCHEMA_VERSION );
	}

	/**
	 * Verifies physically whether all five managed tables exist in the database.
	 *
	 * @return bool True if all tables exist.
	 */
	public function verify_tables(): bool {
		return empty( $this->get_missing_tables() );
	}

	/**
	 * Returns an array of any managed table names currently missing in the database.
	 *
	 * @return array<string> Missing table names.
	 */
	public function get_missing_tables(): array {
		$missing = array();
		$tables  = $this->get_all_table_names();

		foreach ( $tables as $table_name ) {
			$found = $this->db->get_var(
				$this->db->prepare(
					'SHOW TABLES LIKE %s',
					$table_name
				)
			);

			if ( $found !== $table_name ) {
				$missing[] = $table_name;
			}
		}

		return $missing;
	}

	/**
	 * Inspects structural columns for a given table name.
	 *
	 * @param string $suffix Table key.
	 * @return array<string, array<string, mixed>> Column definitions keyed by field name.
	 */
	public function get_table_columns( string $suffix ): array {
		$table_name = $this->get_table_name( $suffix );
		// Table name is derived safely from internal constants and prefix.
		$results = $this->db->get_results( "SHOW COLUMNS FROM {$table_name}", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$columns = array();
		if ( is_array( $results ) ) {
			foreach ( $results as $row ) {
				$columns[ $row['Field'] ] = $row;
			}
		}

		return $columns;
	}

	/**
	 * Inspects structural indexes for a given table name.
	 *
	 * @param string $suffix Table key.
	 * @return array<string, array<string, mixed>> Indexes grouped by Key_name.
	 */
	public function get_table_indexes( string $suffix ): array {
		$table_name = $this->get_table_name( $suffix );
		// Table name is derived safely from internal constants and prefix.
		$results = $this->db->get_results( "SHOW INDEX FROM {$table_name}", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$indexes = array();
		if ( is_array( $results ) ) {
			foreach ( $results as $row ) {
				$key_name = $row['Key_name'];
				if ( ! isset( $indexes[ $key_name ] ) ) {
					$indexes[ $key_name ] = array(
						'non_unique' => (int) $row['Non_unique'],
						'columns'    => array(),
					);
				}
				$indexes[ $key_name ]['columns'][ (int) $row['Seq_in_index'] ] = $row['Column_name'];
			}
		}

		return $indexes;
	}
}
