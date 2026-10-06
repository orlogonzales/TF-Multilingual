<?php
/**
 * PHPUnit Test Bootstrap.
 *
 * @package TF\Multilingual\Tests
 */

declare( strict_types=1 );

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

// Stub minimal WordPress global class wpdb if running pure unit tests without Core loaded.
if ( ! class_exists( 'wpdb' ) ) {
	/**
	 * Minimal stub for wpdb class.
	 */
	// phpcs:ignore PEAR.NamingConventions.ValidClassName.StartWithCapital
	class wpdb {

		/**
		 * Table prefix.
		 *
		 * @var string
		 */
		public string $prefix = 'wp_';

		/**
		 * Posts table name.
		 *
		 * @var string
		 */
		public string $posts = 'wp_posts';

		/**
		 * Last insert ID.
		 *
		 * @var int
		 */
		public int $insert_id = 0;

		/**
		 * Last error string.
		 *
		 * @var string
		 */
		public string $last_error = '';

		/**
		 * Stubs charset collate retrieval.
		 *
		 * @return string
		 */
		public function get_charset_collate(): string {
			return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
		}

		/**
		 * Stubs query preparation.
		 *
		 * @param string $query Query template.
		 * @param mixed  ...$args Query arguments.
		 * @return string
		 */
		public function prepare( string $query, ...$args ): string {
			if ( isset( $args[0] ) && is_array( $args[0] ) && 1 === count( $args ) ) {
				$args = $args[0];
			}

			$pattern = '/(%[sdfF])/';
			$parts   = preg_split( $pattern, $query, -1, PREG_SPLIT_DELIM_CAPTURE );
			if ( false === $parts ) {
				return $query;
			}

			$result  = '';
			$arg_idx = 0;
			foreach ( $parts as $part ) {
				if ( '%s' === $part ) {
					$result .= "'" . addslashes( (string) ( $args[ $arg_idx++ ] ?? '' ) ) . "'";
				} elseif ( '%d' === $part ) {
					$result .= (int) ( $args[ $arg_idx++ ] ?? 0 );
				} elseif ( '%f' === $part || '%F' === $part ) {
					$result .= (float) ( $args[ $arg_idx++ ] ?? 0.0 );
				} else {
					$result .= $part;
				}
			}

			return $result;
		}

		/**
		 * Stubs insert method.
		 *
		 * @param string               $table  Table name.
		 * @param array<string, mixed> $data   Data array.
		 * @param array<string>        $format Format array.
		 * @return int|false
		 */
		public function insert( string $table, array $data, array $format = array() ): int|false {
			return 1;
		}

		/**
		 * Stubs update method.
		 *
		 * @param string               $table        Table name.
		 * @param array<string, mixed> $data         Data array.
		 * @param array<string, mixed> $where        Where array.
		 * @param array<string>        $format       Format array.
		 * @param array<string>        $where_format Where format array.
		 * @return int|false
		 */
		public function update(
			string $table,
			array $data,
			array $where,
			array $format = array(),
			array $where_format = array()
		): int|false {
			return 1;
		}

		/**
		 * Stubs delete method.
		 *
		 * @param string               $table        Table name.
		 * @param array<string, mixed> $where        Where array.
		 * @param array<string>        $where_format Where format array.
		 * @return int|false
		 */
		public function delete( string $table, array $where, array $where_format = array() ): int|false {
			return 1;
		}

		/**
		 * Stubs get_row method.
		 *
		 * @param string $query  SQL query.
		 * @param string $output Output format.
		 * @return mixed
		 */
		public function get_row( string $query, string $output = 'OBJECT' ): mixed {
			return null;
		}

		/**
		 * Stubs get_var method.
		 *
		 * @param string $query SQL query.
		 * @return mixed
		 */
		public function get_var( string $query ): mixed {
			return null;
		}

		/**
		 * Stubs get_results method.
		 *
		 * @param string $query  SQL query.
		 * @param string $output Output format.
		 * @return mixed
		 */
		public function get_results( string $query, string $output = 'OBJECT' ): mixed {
			return array();
		}

		/**
		 * Stubs query method.
		 *
		 * @param string $query SQL query.
		 * @return int|bool
		 */
		public function query( string $query ): int|bool {
			return true;
		}
	}
}

if ( ! isset( $GLOBALS['wpdb'] ) ) {
	$GLOBALS['wpdb'] = new wpdb();
}

// In-memory options storage for unit tests.
$GLOBALS['wp_test_options'] = array();

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * Stub for get_option.
	 *
	 * @param string $option        Option key.
	 * @param mixed  $default_value Default value.
	 * @return mixed
	 */
	function get_option( string $option, mixed $default_value = false ): mixed {
		return $GLOBALS['wp_test_options'][ $option ] ?? $default_value;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	/**
	 * Stub for update_option.
	 *
	 * @param string $option   Option key.
	 * @param mixed  $value    Option value.
	 * @param mixed  $autoload Autoload flag.
	 * @return bool
	 */
	function update_option( string $option, mixed $value, mixed $autoload = null ): bool {
		$GLOBALS['wp_test_options'][ $option ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	/**
	 * Stub for delete_option.
	 *
	 * @param string $option Option key.
	 * @return bool
	 */
	function delete_option( string $option ): bool {
		if ( array_key_exists( $option, $GLOBALS['wp_test_options'] ) ) {
			unset( $GLOBALS['wp_test_options'][ $option ] );
			return true;
		}
		return false;
	}
}

if ( ! function_exists( 'current_time' ) ) {
	/**
	 * Stub for current_time.
	 *
	 * @param string $type Timestamp type.
	 * @param mixed  $gmt  GMT flag.
	 * @return string
	 */
	function current_time( string $type, mixed $gmt = 0 ): string {
		return gmdate( 'Y-m-d H:i:s' );
	}
}

if ( ! class_exists( 'WP_Query' ) ) {
	/**
	 * Minimal stub for WP_Query.
	 */
	// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound,PEAR.NamingConventions.ValidClassName.StartWithCapital
	class WP_Query {

		/**
		 * Query variables array.
		 *
		 * @var array<string, mixed>
		 */
		public array $query_vars = array();

		/**
		 * Whether this is the main query.
		 *
		 * @var bool
		 */
		public bool $is_main_query = false;

		/**
		 * Whether this is a preview query.
		 *
		 * @var bool
		 */
		public bool $is_preview = false;

		/**
		 * Whether this is a search query.
		 *
		 * @var bool
		 */
		public bool $is_search = false;

		/**
		 * Whether this is a singular query.
		 *
		 * @var bool
		 */
		public bool $is_singular = false;

		/**
		 * Whether this is an archive query.
		 *
		 * @var bool
		 */
		public bool $is_archive = false;

		/**
		 * Constructor.
		 *
		 * @param array<string, mixed> $args Query variables.
		 */
		public function __construct( array $args = array() ) {
			$this->query_vars = $args;
		}

		/**
		 * Retrieves a query variable.
		 *
		 * @param string $query_var     Variable name.
		 * @param mixed  $default_value Default value.
		 * @return mixed
		 */
		public function get( string $query_var, mixed $default_value = '' ): mixed {
			return $this->query_vars[ $query_var ] ?? $default_value;
		}

		/**
		 * Sets a query variable.
		 *
		 * @param string $query_var Variable name.
		 * @param mixed  $value     Variable value.
		 * @return void
		 */
		public function set( string $query_var, mixed $value ): void {
			$this->query_vars[ $query_var ] = $value;
		}


		/**
		 * Checks if main query.
		 *
		 * @return bool
		 */
		public function is_main_query(): bool {
			return $this->is_main_query;
		}

		/**
		 * Checks if preview query.
		 *
		 * @return bool
		 */
		public function is_preview(): bool {
			return $this->is_preview;
		}

		/**
		 * Checks if search query.
		 *
		 * @return bool
		 */
		public function is_search(): bool {
			return $this->is_search;
		}

		/**
		 * Checks if singular query.
		 *
		 * @return bool
		 */
		public function is_singular(): bool {
			return $this->is_singular;
		}

		/**
		 * Checks if archive query.
		 *
		 * @return bool
		 */
		public function is_archive(): bool {
			return $this->is_archive;
		}
	}
}

if ( ! isset( $GLOBALS['wp_test_actions'] ) ) {
	$GLOBALS['wp_test_actions'] = array();
}

if ( ! function_exists( 'add_action' ) ) {
	/**
	 * Stub for add_action.
	 *
	 * @param string   $hook_name     Hook name.
	 * @param callable $callback      Callback.
	 * @param int      $priority      Priority.
	 * @param int      $accepted_args Accepted args.
	 * @return true
	 */
	function add_action( string $hook_name, callable $callback, int $priority = 10, int $accepted_args = 1 ): true {
		$GLOBALS['wp_test_actions'][ $hook_name ][] = array(
			'callback'      => $callback,
			'priority'      => $priority,
			'accepted_args' => $accepted_args,
		);
		return true;
	}
}

if ( ! function_exists( 'remove_action' ) ) {
	/**
	 * Stub for remove_action.
	 *
	 * @param string   $hook_name Hook name.
	 * @param callable $callback  Callback.
	 * @param int      $priority  Priority.
	 * @return bool
	 */
	function remove_action( string $hook_name, callable $callback, int $priority = 10 ): bool {
		if ( isset( $GLOBALS['wp_test_actions'][ $hook_name ] ) ) {
			foreach ( $GLOBALS['wp_test_actions'][ $hook_name ] as $idx => $entry ) {
				if ( $entry['callback'] === $callback ) {
					unset( $GLOBALS['wp_test_actions'][ $hook_name ][ $idx ] );
					return true;
				}
			}
		}
		return false;
	}
}

if ( ! function_exists( 'do_action' ) ) {
	/**
	 * Stub for do_action.
	 *
	 * @param string $hook_name Hook name.
	 * @param mixed  ...$args   Hook arguments.
	 * @return void
	 */
	function do_action( string $hook_name, mixed ...$args ): void {
		if ( isset( $GLOBALS['wp_test_actions'][ $hook_name ] ) ) {
			foreach ( $GLOBALS['wp_test_actions'][ $hook_name ] as $entry ) {
				$sliced_args = array_slice( $args, 0, $entry['accepted_args'] );
				call_user_func_array( $entry['callback'], $sliced_args );
			}
		}
	}
}

if ( ! isset( $GLOBALS['wp_test_postmeta'] ) ) {
	$GLOBALS['wp_test_postmeta'] = array();
}

if ( ! function_exists( 'wp_slash' ) ) {
	/**
	 * Stub for wp_slash.
	 *
	 * @param mixed $value Value to slash.
	 * @return mixed
	 */
	function wp_slash( mixed $value ): mixed {
		if ( is_string( $value ) ) {
			return addslashes( $value );
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $k => $v ) {
				$value[ $k ] = wp_slash( $v );
			}
		}
		return $value;
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	/**
	 * Stub for wp_unslash.
	 *
	 * @param mixed $value Value to unslash.
	 * @return mixed
	 */
	function wp_unslash( mixed $value ): mixed {
		if ( is_string( $value ) ) {
			return stripslashes( $value );
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $k => $v ) {
				$value[ $k ] = wp_unslash( $v );
			}
		}
		return $value;
	}
}

if ( ! function_exists( 'get_post_meta' ) ) {
	/**
	 * Stub for get_post_meta.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Meta key.
	 * @param bool   $single  Whether to return single value.
	 * @return mixed
	 */
	function get_post_meta( int $post_id, string $key = '', bool $single = false ): mixed {
		$store = $GLOBALS['wp_test_postmeta'][ $post_id ] ?? array();
		if ( '' === $key ) {
			return $store;
		}
		if ( ! array_key_exists( $key, $store ) ) {
			return $single ? '' : array();
		}
		return $single ? $store[ $key ] : array( $store[ $key ] );
	}
}

if ( ! function_exists( 'update_post_meta' ) ) {
	/**
	 * Stub for update_post_meta.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Meta key.
	 * @param mixed  $value   Meta value.
	 * @return bool
	 */
	function update_post_meta( int $post_id, string $key, mixed $value ): bool {
		if ( ! isset( $GLOBALS['wp_test_postmeta'] ) || ! is_array( $GLOBALS['wp_test_postmeta'] ) ) {
			$GLOBALS['wp_test_postmeta'] = array();
		}
		$unslashed = wp_unslash( $value );
		$is_new    = ! isset( $GLOBALS['wp_test_postmeta'][ $post_id ][ $key ] );
		$GLOBALS['wp_test_postmeta'][ $post_id ][ $key ] = $unslashed;

		if ( function_exists( 'do_action' ) ) {
			if ( $is_new ) {
				do_action( 'added_post_meta', 1, $post_id, $key, $unslashed );
			} else {
				do_action( 'updated_post_meta', 1, $post_id, $key, $unslashed );
			}
		}

		return true;
	}
}

if ( ! function_exists( 'delete_post_meta' ) ) {
	/**
	 * Stub for delete_post_meta.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Meta key.
	 * @param mixed  $value   Meta value.
	 * @return bool
	 */
	function delete_post_meta( int $post_id, string $key, mixed $value = '' ): bool {
		if ( isset( $GLOBALS['wp_test_postmeta'][ $post_id ][ $key ] ) ) {
			$old = $GLOBALS['wp_test_postmeta'][ $post_id ][ $key ];
			unset( $GLOBALS['wp_test_postmeta'][ $post_id ][ $key ] );
			if ( function_exists( 'do_action' ) ) {
				do_action( 'deleted_post_meta', array( 1 ), $post_id, $key, $old );
			}
			return true;
		}
		return false;
	}
}

if ( ! function_exists( 'metadata_exists' ) ) {
	/**
	 * Stub for metadata_exists.
	 *
	 * @param string $meta_type Meta type.
	 * @param int    $object_id Object ID.
	 * @param string $meta_key  Meta key.
	 * @return bool
	 */
	function metadata_exists( string $meta_type, int $object_id, string $meta_key ): bool {
		if ( 'post' === $meta_type ) {
			return isset( $GLOBALS['wp_test_postmeta'][ $object_id ][ $meta_key ] );
		}
		return false;
	}
}

if ( ! function_exists( 'wp_is_post_revision' ) ) {
	/**
	 * Stub for wp_is_post_revision.
	 *
	 * @param mixed $post Post ID or object.
	 * @return int|false
	 */
	function wp_is_post_revision( mixed $post ): int|false {
		if ( isset( $GLOBALS['wp_test_post_revisions'] ) && is_array( $GLOBALS['wp_test_post_revisions'] ) ) {
			$id = is_object( $post ) ? (int) $post->ID : (int) $post;
			return $GLOBALS['wp_test_post_revisions'][ $id ] ?? false;
		}
		return false;
	}
}

if ( ! function_exists( 'wp_is_post_autosave' ) ) {
	/**
	 * Stub for wp_is_post_autosave.
	 *
	 * @param mixed $post Post ID or object.
	 * @return int|false
	 */
	function wp_is_post_autosave( mixed $post ): int|false {
		if ( isset( $GLOBALS['wp_test_post_autosaves'] ) && is_array( $GLOBALS['wp_test_post_autosaves'] ) ) {
			$id = is_object( $post ) ? (int) $post->ID : (int) $post;
			return $GLOBALS['wp_test_post_autosaves'][ $id ] ?? false;
		}
		return false;
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	/**
	 * Stub for current_user_can.
	 *
	 * @param string $capability Capability name.
	 * @param mixed  ...$args    Additional arguments.
	 * @return bool
	 */
	function current_user_can( string $capability, mixed ...$args ): bool {
		if ( isset( $GLOBALS['wp_test_caps'][ $capability ] ) ) {
			return (bool) $GLOBALS['wp_test_caps'][ $capability ];
		}
		return true;
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	/**
	 * Stub for sanitize_text_field.
	 *
	 * @param string $str String to sanitize.
	 * @return string
	 */
	function sanitize_text_field( string $str ): string {
		return trim( strip_tags( $str ) );
	}
}

if ( ! function_exists( 'check_admin_referer' ) ) {
	/**
	 * Stub for check_admin_referer.
	 *
	 * @param string $action    Action name.
	 * @param string $query_arg Query arg.
	 * @return true
	 */
	function check_admin_referer( string $action = '-1', string $query_arg = '_wpnonce' ): true {
		return true;
	}
}

if ( ! function_exists( 'wp_create_nonce' ) ) {
	/**
	 * Stub for wp_create_nonce.
	 *
	 * @param string $action Action name.
	 * @return string
	 */
	function wp_create_nonce( string $action = '-1' ): string {
		return substr( md5( 'nonce_' . $action ), 0, 10 );
	}
}

if ( ! function_exists( 'wp_nonce_field' ) ) {
	/**
	 * Stub for wp_nonce_field.
	 *
	 * @param string $action       Action name.
	 * @param string $name         Nonce field name.
	 * @param bool   $referer      Referer field.
	 * @param bool   $display_echo Whether to echo.
	 * @return string
	 */
	function wp_nonce_field( string $action = '-1', string $name = '_wpnonce', bool $referer = true, bool $display_echo = true ): string {
		$html = '<input type="hidden" name="' . htmlspecialchars( $name ) . '" value="' . wp_create_nonce( $action ) . '" />';
		if ( $display_echo ) {
			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		return $html;
	}
}

if ( ! function_exists( 'add_query_arg' ) ) {
	/**
	 * Stub for add_query_arg.
	 *
	 * @param array<string, mixed>|string $param1 Param array or key.
	 * @param mixed                        $param2 Value or URL.
	 * @param string|null                  $param3 URL if 3 args.
	 * @return string
	 */
	function add_query_arg( array|string $param1, mixed $param2 = false, ?string $param3 = null ): string {
		if ( is_array( $param1 ) ) {
			$base_url = is_string( $param2 ) ? $param2 : '';
			$query    = http_build_query( $param1 );
			return str_contains( $base_url, '?' ) ? $base_url . '&' . $query : $base_url . '?' . $query;
		}

		$key      = (string) $param1;
		$val      = (string) $param2;
		$base_url = is_string( $param3 ) ? $param3 : '';
		$sep      = str_contains( $base_url, '?' ) ? '&' : '?';
		return $base_url . $sep . rawurlencode( $key ) . '=' . rawurlencode( $val );
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	/**
	 * Stub for add_filter.
	 *
	 * @param string   $hook_name     Hook name.
	 * @param callable $callback      Callback.
	 * @param int      $priority      Priority.
	 * @param int      $accepted_args Accepted args.
	 * @return true
	 */
	function add_filter( string $hook_name, callable $callback, int $priority = 10, int $accepted_args = 1 ): true {
		return true;
	}
}

if ( ! function_exists( 'is_admin' ) ) {
	/**
	 * Stub for is_admin.
	 *
	 * @return bool
	 */
	function is_admin(): bool {
		return false;
	}
}

if ( ! function_exists( 'wp_doing_cron' ) ) {
	/**
	 * Stub for wp_doing_cron.
	 *
	 * @return bool
	 */
	function wp_doing_cron(): bool {
		return false;
	}
}

if ( ! function_exists( 'wp_doing_ajax' ) ) {
	/**
	 * Stub for wp_doing_ajax.
	 *
	 * @return bool
	 */
	function wp_doing_ajax(): bool {
		return false;
	}
}

if ( ! function_exists( 'wp_is_json_request' ) ) {
	/**
	 * Stub for wp_is_json_request.
	 *
	 * @return bool
	 */
	function wp_is_json_request(): bool {
		return false;
	}
}

if ( ! function_exists( 'esc_sql' ) ) {
	/**
	 * Stub for esc_sql.
	 *
	 * @param string|array<mixed> $data Data to escape.
	 * @return string|array<mixed>
	 */
	function esc_sql( string|array $data ): string|array {
		if ( is_array( $data ) ) {
			return array_map( 'addslashes', $data );
		}
		return addslashes( $data );
	}
}

if ( ! class_exists( 'WP_Post' ) ) {
	/**
	 * Minimal stub for WP_Post class.
	 */
	// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound,PEAR.NamingConventions.ValidClassName.StartWithCapital
	class WP_Post {
		/**
		 * Post ID.
		 *
		 * @var int
		 */
		public int $ID = 0;

		/**
		 * Post type.
		 *
		 * @var string
		 */
		public string $post_type = 'post';

		/**
		 * Post status.
		 *
		 * @var string
		 */
		public string $post_status = 'publish';

		/**
		 * Post title.
		 *
		 * @var string
		 */
		public string $post_title = '';

		/**
		 * Post content.
		 *
		 * @var string
		 */
		public string $post_content = '';

		/**
		 * Post author.
		 *
		 * @var int
		 */
		public int $post_author = 1;
	}
}

if ( ! class_exists( 'WP_Term' ) ) {
	/**
	 * Minimal stub for WP_Term class.
	 */
	// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound,PEAR.NamingConventions.ValidClassName.StartWithCapital
	class WP_Term {
		/**
		 * Term ID.
		 *
		 * @var int
		 */
		public int $term_id = 0;

		/**
		 * Taxonomy name.
		 *
		 * @var string
		 */
		public string $taxonomy = 'category';

		/**
		 * Term name.
		 *
		 * @var string
		 */
		public string $name = '';

		/**
		 * Term slug.
		 *
		 * @var string
		 */
		public string $slug = '';

		/**
		 * Term parent ID.
		 *
		 * @var int
		 */
		public int $parent = 0;
	}
}

if ( ! function_exists( '__' ) ) {
	/**
	 * Stub for __.
	 *
	 * @param string $text   Text.
	 * @param string $domain Domain.
	 * @return string
	 */
	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
}

if ( ! function_exists( 'esc_attr__' ) ) {
	/**
	 * Stub for esc_attr__.
	 *
	 * @param string $text   Text.
	 * @param string $domain Domain.
	 * @return string
	 */
	function esc_attr__( string $text, string $domain = 'default' ): string {
		return $text;
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	/**
	 * Stub for esc_html__.
	 *
	 * @param string $text   Text.
	 * @param string $domain Domain.
	 * @return string
	 */
	function esc_html__( string $text, string $domain = 'default' ): string {
		return $text;
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	/**
	 * Stub for esc_attr.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	function esc_attr( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES );
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	/**
	 * Stub for esc_html.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	function esc_html( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES );
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	/**
	 * Stub for esc_url.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	function esc_url( string $url ): string {
		return $url;
	}
}

if ( ! function_exists( 'admin_url' ) ) {
	/**
	 * Stub for admin_url.
	 *
	 * @param string $path   Path.
	 * @param string $scheme Scheme.
	 * @return string
	 */
	function admin_url( string $path = '', string $scheme = 'admin' ): string {
		return 'https://example.com/wp-admin/' . ltrim( $path, '/' );
	}
}

if ( ! function_exists( 'esc_js' ) ) {
	/**
	 * Stub for esc_js.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	function esc_js( string $text ): string {
		return addslashes( $text );
	}
}
