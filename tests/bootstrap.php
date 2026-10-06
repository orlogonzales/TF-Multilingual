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
		return true;
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
