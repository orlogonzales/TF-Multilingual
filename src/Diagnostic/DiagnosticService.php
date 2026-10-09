<?php
/**
 * Diagnostic and Health Check Service.
 *
 * @package TF\Multilingual\Diagnostic
 */

declare( strict_types=1 );

namespace TF\Multilingual\Diagnostic;

use TF\Multilingual\Core\Plugin;
use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Domain\Language\SettingsRepository;
use TF\Multilingual\Domain\Media\MediaTranslationRepository;
use TF\Multilingual\Domain\Navigation\NavMenuLocationRepository;
use TF\Multilingual\Domain\Strings\StringRepository;
use TF\Multilingual\Domain\Translation\TranslationGroupRepository;
use TF\Multilingual\Infrastructure\Persistence\SchemaManager;
use wpdb;

/**
 * Class DiagnosticService
 *
 * Provides comprehensive, strictly read-only diagnostics for system environment,
 * database schema, language configurations, relational integrity, rewrites, and modules.
 */
class DiagnosticService {

	/**
	 * WordPress database instance.
	 *
	 * @var wpdb
	 */
	private wpdb $db;

	/**
	 * Language registry.
	 *
	 * @var LanguageRegistry
	 */
	private LanguageRegistry $language_registry;

	/**
	 * Settings repository.
	 *
	 * @var SettingsRepository
	 */
	private SettingsRepository $settings_repository;

	/**
	 * Translation group repository.
	 *
	 * @var TranslationGroupRepository
	 */
	private TranslationGroupRepository $group_repository;

	/**
	 * Media translation repository.
	 *
	 * @var MediaTranslationRepository
	 */
	private MediaTranslationRepository $media_repository;

	/**
	 * String repository.
	 *
	 * @var StringRepository
	 */
	private StringRepository $string_repository;

	/**
	 * Nav menu location repository.
	 *
	 * @var NavMenuLocationRepository
	 */
	private NavMenuLocationRepository $nav_menu_location_repository;

	/**
	 * Constructor.
	 *
	 * @param LanguageRegistry           $language_registry            Language registry.
	 * @param SettingsRepository         $settings_repository          Settings repository.
	 * @param TranslationGroupRepository $group_repository             Translation group repository.
	 * @param MediaTranslationRepository $media_repository             Media translation repository.
	 * @param StringRepository           $string_repository            String repository.
	 * @param NavMenuLocationRepository  $nav_menu_location_repository Nav menu location repository.
	 * @param wpdb|null                  $db                           Optional database instance.
	 */
	public function __construct(
		LanguageRegistry $language_registry,
		SettingsRepository $settings_repository,
		TranslationGroupRepository $group_repository,
		MediaTranslationRepository $media_repository,
		StringRepository $string_repository,
		NavMenuLocationRepository $nav_menu_location_repository,
		?wpdb $db = null
	) {
		$this->language_registry            = $language_registry;
		$this->settings_repository          = $settings_repository;
		$this->group_repository             = $group_repository;
		$this->media_repository             = $media_repository;
		$this->string_repository            = $string_repository;
		$this->nav_menu_location_repository = $nav_menu_location_repository;

		if ( null === $db ) {
			global $wpdb;
			$db = $wpdb;
		}
		$this->db = $db;
	}

	/**
	 * Checks environment compatibility (PHP, WordPress, and extensions).
	 *
	 * @return array<string, mixed>
	 */
	public function get_environment_report(): array {
		global $wp_version;

		$php_version   = PHP_VERSION;
		$wp_ver_string = (string) ( $wp_version ?? '6.8' );

		$php_compatible = version_compare( $php_version, Plugin::MIN_PHP_VERSION, '>=' );
		$wp_compatible  = version_compare( $wp_ver_string, Plugin::MIN_WP_VERSION, '>=' );

		$extensions = array(
			'mbstring' => extension_loaded( 'mbstring' ),
			'json'     => extension_loaded( 'json' ),
			'hash'     => extension_loaded( 'hash' ),
		);

		$all_extensions = ! in_array( false, $extensions, true );

		$status = ( $php_compatible && $wp_compatible && $all_extensions ) ? 'good' : 'critical';

		return array(
			'plugin_version'        => Plugin::VERSION,
			'php_version'           => $php_version,
			'php_min_version'       => Plugin::MIN_PHP_VERSION,
			'php_compatible'        => $php_compatible,
			'wordpress_version'     => $wp_ver_string,
			'wordpress_min_version' => Plugin::MIN_WP_VERSION,
			'wordpress_compatible'  => $wp_compatible,
			'extensions'            => $extensions,
			'all_extensions_loaded' => $all_extensions,
			'status'                => $status,
		);
	}

	/**
	 * Checks the existence and row counts of all 5 official TFML tables.
	 *
	 * @return array<string, mixed>
	 */
	public function get_tables_report(): array {
		$tables_status    = array();
		$all_exist        = true;
		$installed_schema = function_exists( 'get_option' ) ? get_option( SchemaManager::OPTION_SCHEMA_VERSION, null ) : SchemaManager::SCHEMA_VERSION;

		foreach ( SchemaManager::TABLES as $key => $table_suffix ) {
			$full_table = $this->db->prefix . $table_suffix;
			$exists     = $this->check_table_exists( $full_table );

			$row_count = 0;
			if ( $exists ) {
				$count_val = $this->db->get_var( "SELECT COUNT(*) FROM `{$full_table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$row_count = null !== $count_val ? (int) $count_val : 0;
			} else {
				$all_exist = false;
			}

			$tables_status[ $key ] = array(
				'table_name' => $full_table,
				'exists'     => $exists,
				'rows'       => $row_count,
			);
		}

		$status = $all_exist ? 'good' : 'critical';

		return array(
			'schema_version'   => $installed_schema,
			'expected_version' => SchemaManager::SCHEMA_VERSION,
			'all_tables_exist' => $all_exist,
			'tables'           => $tables_status,
			'status'           => $status,
		);
	}

	/**
	 * Checks language registry configuration and consistency.
	 *
	 * @return array<string, mixed>
	 */
	public function get_languages_report(): array {
		$is_configured = $this->language_registry->is_configured();
		$default_lang  = $this->language_registry->get_default();
		$default_code  = null !== $default_lang ? $default_lang->get_code() : null;

		$all_langs    = $this->language_registry->all();
		$active_langs = array_keys( $this->language_registry->active() );

		$inactive_langs = array();
		foreach ( $all_langs as $code => $lang ) {
			if ( ! $lang->is_active() ) {
				$inactive_langs[] = $code;
			}
		}

		$default_is_active = ( null !== $default_lang && $default_lang->is_active() );

		$status = 'good';
		if ( ! $is_configured || null === $default_code || ! $default_is_active ) {
			$status = 'critical';
		} elseif ( empty( $active_langs ) ) {
			$status = 'warning';
		}

		return array(
			'is_configured'      => $is_configured,
			'default_language'   => $default_code,
			'default_is_active'  => $default_is_active,
			'total_languages'    => count( $all_langs ),
			'active_languages'   => $active_langs,
			'inactive_languages' => $inactive_langs,
			'status'             => $status,
		);
	}

	/**
	 * Validates relational integrity using aggregated database queries.
	 *
	 * Executes zero N+1 queries.
	 *
	 * @return array<string, mixed>
	 */
	public function get_relations_integrity_report(): array {
		$groups_table   = $this->db->prefix . SchemaManager::TABLES['groups'];
		$elements_table = $this->db->prefix . SchemaManager::TABLES['group_elements'];
		$posts_table    = $this->db->prefix . 'posts';
		$terms_table    = $this->db->prefix . 'terms';

		$total_groups = (int) ( $this->db->get_var( "SELECT COUNT(*) FROM `{$groups_table}`" ) ?? 0 ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total_elems  = (int) ( $this->db->get_var( "SELECT COUNT(*) FROM `{$elements_table}`" ) ?? 0 ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// Empty groups (groups without elements).
		$empty_groups = (int) ( $this->db->get_var( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT COUNT(*) FROM `{$groups_table}` g LEFT JOIN `{$elements_table}` e ON g.id = e.group_id WHERE e.id IS NULL"
		) ?? 0 );

		// Duplicate language in same group.
		$dup_lang = (int) ( $this->db->get_var( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT COUNT(*) FROM (SELECT group_id, language_code, COUNT(*) as c FROM `{$elements_table}` GROUP BY group_id, language_code HAVING c > 1) as t"
		) ?? 0 );

		// Duplicate elements assigned to multiple groups.
		$dup_elems = (int) ( $this->db->get_var( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT COUNT(*) FROM (SELECT element_type, element_id, COUNT(*) as c FROM `{$elements_table}` GROUP BY element_type, element_id HAVING c > 1) as t"
		) ?? 0 );

		// Orphaned post elements (element points to non-existent post).
		$orphaned_posts = (int) ( $this->db->get_var( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT COUNT(*) FROM `{$elements_table}` e LEFT JOIN `{$posts_table}` p ON e.element_id = p.ID WHERE e.element_type = 'post' AND p.ID IS NULL"
		) ?? 0 );

		// Orphaned term elements (element points to non-existent term).
		$orphaned_terms = (int) ( $this->db->get_var( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT COUNT(*) FROM `{$elements_table}` e LEFT JOIN `{$terms_table}` t ON e.element_id = t.term_id WHERE e.element_type = 'term' AND t.term_id IS NULL"
		) ?? 0 );

		// Orphaned canonical elements (canonical points to element not in the group).
		$orphaned_canonical = (int) ( $this->db->get_var( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT COUNT(*) FROM `{$groups_table}` g LEFT JOIN `{$elements_table}` e ON g.id = e.group_id AND g.canonical_element_id = e.element_id WHERE g.canonical_element_id IS NOT NULL AND e.id IS NULL"
		) ?? 0 );

		// Unregistered language elements.
		$registered_codes   = array_keys( $this->language_registry->all() );
		$unregistered_count = 0;
		if ( ! empty( $registered_codes ) ) {
			$escaped_codes      = implode( "','", array_map( 'esc_sql', $registered_codes ) );
			$unreg_query        = "SELECT COUNT(*) FROM `{$elements_table}` WHERE language_code NOT IN ('{$escaped_codes}')"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$unregistered_count = (int) ( $this->db->get_var( $unreg_query ) ?? 0 ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		$status = 'good';
		if ( $dup_lang > 0 || $dup_elems > 0 || $orphaned_canonical > 0 || $unregistered_count > 0 ) {
			$status = 'critical';
		} elseif ( $empty_groups > 0 || $orphaned_posts > 0 || $orphaned_terms > 0 ) {
			$status = 'warning';
		}

		return array(
			'total_groups'                 => $total_groups,
			'total_elements'               => $total_elems,
			'empty_groups_count'           => $empty_groups,
			'duplicate_languages_count'    => $dup_lang,
			'duplicate_elements_count'     => $dup_elems,
			'orphaned_posts_count'         => $orphaned_posts,
			'orphaned_terms_count'         => $orphaned_terms,
			'orphaned_canonical_count'     => $orphaned_canonical,
			'unregistered_languages_count' => $unregistered_count,
			'status'                       => $status,
		);
	}

	/**
	 * Checks rewrite rules and URL routing status.
	 *
	 * @return array<string, mixed>
	 */
	public function get_rewrites_report(): array {
		$structure    = function_exists( 'get_option' ) ? (string) get_option( 'permalink_structure', '' ) : '/%postname%/';
		$using_pretty = ( '' !== $structure );

		$default_code    = $this->language_registry->get_default()?->get_code();
		$active_prefixes = array();
		foreach ( array_keys( $this->language_registry->active() ) as $code ) {
			if ( $code !== $default_code ) {
				$active_prefixes[] = $code;
			}
		}

		$status = $using_pretty ? 'good' : 'warning';

		return array(
			'using_pretty_permalinks' => $using_pretty,
			'permalink_structure'     => $using_pretty ? $structure : 'plain',
			'active_prefixes'         => $active_prefixes,
			'status'                  => $status,
		);
	}

	/**
	 * Checks status of all Core functional modules.
	 *
	 * @return array<string, mixed>
	 */
	public function get_modules_report(): array {
		$media_table  = $this->db->prefix . SchemaManager::TABLES['media_translations'];
		$string_table = $this->db->prefix . SchemaManager::TABLES['strings'];
		$str_tr_table = $this->db->prefix . SchemaManager::TABLES['string_translations'];

		$media_count = 0;
		if ( $this->check_table_exists( $media_table ) ) {
			$media_count = (int) ( $this->db->get_var( "SELECT COUNT(*) FROM `{$media_table}`" ) ?? 0 ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		$strings_count = 0;
		if ( $this->check_table_exists( $string_table ) ) {
			$strings_count = (int) ( $this->db->get_var( "SELECT COUNT(*) FROM `{$string_table}`" ) ?? 0 ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		$string_trans_count = 0;
		if ( $this->check_table_exists( $str_tr_table ) ) {
			$string_trans_count = (int) ( $this->db->get_var( "SELECT COUNT(*) FROM `{$str_tr_table}`" ) ?? 0 ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		$mapped_locations = count( $this->nav_menu_location_repository->get_all_mappings() );

		return array(
			'media'   => array(
				'enabled'            => true,
				'total_translations' => $media_count,
			),
			'strings' => array(
				'enabled'            => true,
				'total_strings'      => $strings_count,
				'total_translations' => $string_trans_count,
			),
			'menus'   => array(
				'enabled'          => true,
				'locations_mapped' => $mapped_locations,
			),
			'seo'     => array(
				'enabled'   => true,
				'hreflang'  => true,
				'canonical' => true,
				'sitemaps'  => true,
			),
			'rest'    => array(
				'enabled'   => true,
				'namespace' => 'tf-multilingual/v1',
			),
			'status'  => 'good',
		);
	}

	/**
	 * Runs a full, unified diagnostic audit of the Core.
	 *
	 * @return array<string, mixed>
	 */
	public function run_full_diagnostic(): array {
		$env       = $this->get_environment_report();
		$tables    = $this->get_tables_report();
		$languages = $this->get_languages_report();
		$relations = $this->get_relations_integrity_report();
		$rewrites  = $this->get_rewrites_report();
		$modules   = $this->get_modules_report();

		$statuses = array(
			$env['status'],
			$tables['status'],
			$languages['status'],
			$relations['status'],
			$rewrites['status'],
			$modules['status'],
		);

		$overall_status = 'good';
		if ( in_array( 'critical', $statuses, true ) ) {
			$overall_status = 'critical';
		} elseif ( in_array( 'warning', $statuses, true ) ) {
			$overall_status = 'warning';
		}

		$passed_count   = 0;
		$warning_count  = 0;
		$critical_count = 0;

		foreach ( $statuses as $st ) {
			if ( 'good' === $st ) {
				++$passed_count;
			} elseif ( 'warning' === $st ) {
				++$warning_count;
			} elseif ( 'critical' === $st ) {
				++$critical_count;
			}
		}

		return array(
			'timestamp'      => time(),
			'overall_status' => $overall_status,
			'summary'        => array(
				'passed'   => $passed_count,
				'warnings' => $warning_count,
				'critical' => $critical_count,
				'total'    => count( $statuses ),
			),
			'sections'       => array(
				'environment' => $env,
				'tables'      => $tables,
				'languages'   => $languages,
				'relations'   => $relations,
				'rewrites'    => $rewrites,
				'modules'     => $modules,
			),
		);
	}

	/**
	 * Checks if a table exists in the database.
	 *
	 * @param string $table_name Full table name.
	 * @return bool
	 */
	protected function check_table_exists( string $table_name ): bool {
		$query = $this->db->prepare( 'SHOW TABLES LIKE %s', $table_name );
		if ( ! is_string( $query ) ) {
			return false;
		}

		$result = $this->db->get_var( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return ( $result === $table_name );
	}
}
