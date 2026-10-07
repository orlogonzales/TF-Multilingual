<?php
/**
 * Admin List Columns UI Service.
 *
 * @package TF\Multilingual\Admin
 */

declare( strict_types=1 );

namespace TF\Multilingual\Admin;

use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Domain\Translation\TranslationStatus;
use TF\Multilingual\Editorial\TranslationEditorialService;
use WP_Post;
use WP_Query;
use WP_Term;

/**
 * Class AdminListColumnsUi
 *
 * Manages translation status columns in WordPress admin list tables (edit.php, edit-tags.php).
 * Displays current language badge, existing translation links, missing translation creation actions,
 * and unmanaged indicators while enforcing zero N+1 queries via batch pre-fetching.
 */
class AdminListColumnsUi {

	/**
	 * Identifier key for the multilingual column.
	 */
	public const COLUMN_KEY = 'tfml_languages';

	/**
	 * Language registry domain service.
	 *
	 * @var LanguageRegistry
	 */
	private LanguageRegistry $language_registry;

	/**
	 * Translation editorial application service.
	 *
	 * @var TranslationEditorialService
	 */
	private TranslationEditorialService $editorial_service;

	/**
	 * Track taxonomies prefetched in current request to prevent redundant batching.
	 *
	 * @var array<string, bool>
	 */
	private array $prefetched_taxonomies = array();

	/**
	 * Constructor.
	 *
	 * @param LanguageRegistry|null           $language_registry Language registry service.
	 * @param TranslationEditorialService|null $editorial_service Translation editorial service.
	 */
	public function __construct(
		?LanguageRegistry $language_registry = null,
		?TranslationEditorialService $editorial_service = null
	) {
		$this->language_registry = null !== $language_registry ? $language_registry : new LanguageRegistry();
		$this->editorial_service = null !== $editorial_service ? $editorial_service : new TranslationEditorialService( $this->language_registry );
	}

	/**
	 * Registers WordPress admin hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'admin_init', array( $this, 'register_columns' ) );
		add_filter( 'the_posts', array( $this, 'prefetch_posts' ), 10, 2 );
		add_action( 'admin_head', array( $this, 'render_admin_styles' ) );
	}

	/**
	 * Registers list table columns and rendering callbacks for supported post types and taxonomies.
	 *
	 * @return void
	 */
	public function register_columns(): void {
		if ( ! $this->language_registry->is_configured() ) {
			return;
		}

		// Built-in post types.
		add_filter( 'manage_posts_columns', array( $this, 'add_posts_columns' ) );
		add_filter( 'manage_pages_columns', array( $this, 'add_posts_columns' ) );
		add_action( 'manage_posts_custom_column', array( $this, 'render_posts_column' ), 10, 2 );
		add_action( 'manage_pages_custom_column', array( $this, 'render_posts_column' ), 10, 2 );

		// Public custom post types.
		if ( function_exists( 'get_post_types' ) ) {
			$post_types = get_post_types( array( 'public' => true ), 'names' );
			foreach ( $post_types as $pt ) {
				if ( ! in_array( $pt, array( 'post', 'page' ), true ) && $this->editorial_service->is_supported_post_type( $pt ) ) {
					add_filter( "manage_{$pt}_posts_columns", array( $this, 'add_posts_columns' ) );
					add_action( "manage_{$pt}_posts_custom_column", array( $this, 'render_posts_column' ), 10, 2 );
				}
			}
		}

		// Public taxonomies.
		if ( function_exists( 'get_taxonomies' ) ) {
			$taxonomies = get_taxonomies( array( 'public' => true ), 'names' );
			foreach ( $taxonomies as $tax ) {
				if ( $this->editorial_service->is_supported_taxonomy( $tax ) ) {
					add_filter( "manage_edit-{$tax}_columns", array( $this, 'add_terms_columns' ) );
					add_filter( "manage_{$tax}_custom_column", array( $this, 'render_terms_column' ), 10, 3 );
				}
			}
		} else {
			// Fallback for test environments without get_taxonomies.
			foreach ( array( 'category', 'post_tag' ) as $tax ) {
				add_filter( "manage_edit-{$tax}_columns", array( $this, 'add_terms_columns' ) );
				add_filter( "manage_{$tax}_custom_column", array( $this, 'render_terms_column' ), 10, 3 );
			}
		}
	}

	/**
	 * Appends the languages column to posts list table.
	 *
	 * @param array<string, string> $columns Existing column definitions.
	 * @return array<string, string> Modified column definitions.
	 */
	public function add_posts_columns( array $columns ): array {
		return $this->insert_languages_column( $columns, 'date' );
	}

	/**
	 * Appends the languages column to terms list table.
	 *
	 * @param array<string, string> $columns Existing column definitions.
	 * @return array<string, string> Modified column definitions.
	 */
	public function add_terms_columns( array $columns ): array {
		return $this->insert_languages_column( $columns, 'posts' );
	}

	/**
	 * Inserts the languages column immediately before a target column key, or appends it.
	 *
	 * @param array<string, string> $columns    Columns array.
	 * @param string                $before_key Preferred column key to precede.
	 * @return array<string, string>
	 */
	private function insert_languages_column( array $columns, string $before_key ): array {
		$new_columns = array();
		$inserted    = false;

		foreach ( $columns as $key => $title ) {
			if ( $key === $before_key && ! $inserted ) {
				$new_columns[ self::COLUMN_KEY ] = __( 'Idiomas', 'tf-multilingual' );
				$inserted                        = true;
			}
			$new_columns[ $key ] = $title;
		}

		if ( ! $inserted ) {
			$new_columns[ self::COLUMN_KEY ] = __( 'Idiomas', 'tf-multilingual' );
		}

		return $new_columns;
	}

	/**
	 * Pre-fetches multilingual editorial data in batch when posts query resolves.
	 *
	 * Guarantees zero N+1 queries during post row rendering in edit.php.
	 *
	 * @param array<WP_Post|mixed> $posts Query posts.
	 * @param WP_Query|null        $query Query object.
	 * @return array<WP_Post|mixed> Unmodified posts.
	 */
	public function prefetch_posts( array $posts, ?WP_Query $query = null ): array {
		if ( function_exists( 'is_admin' ) && ! is_admin() ) {
			return $posts;
		}

		if ( empty( $posts ) || ! $this->language_registry->is_configured() ) {
			return $posts;
		}

		$ids_by_type = array();
		foreach ( $posts as $post ) {
			if ( $post instanceof WP_Post && $this->editorial_service->is_supported_post_type( $post->post_type ) ) {
				$ids_by_type[ $post->post_type ][] = (int) $post->ID;
			}
		}

		foreach ( $ids_by_type as $post_type => $ids ) {
			$this->editorial_service->get_editorial_data_for_elements( 'post', $ids, $post_type );
		}

		return $posts;
	}

	/**
	 * Renders custom column content for posts list tables.
	 *
	 * @param string $column_name Column key.
	 * @param int    $post_id     Post ID.
	 * @return void
	 */
	public function render_posts_column( string $column_name, int $post_id ): void {
		if ( self::COLUMN_KEY !== $column_name ) {
			return;
		}

		$post = $this->get_post( $post_id );
		if ( ! $post instanceof WP_Post || ! $this->editorial_service->is_supported_post_type( $post->post_type ) ) {
			return;
		}

		echo $this->render_post_languages_html( $post ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Renders custom column content for terms list tables.
	 *
	 * @param string $content     Existing column content.
	 * @param string $column_name Column key.
	 * @param int    $term_id     Term ID.
	 * @return string Rendered column HTML.
	 */
	public function render_terms_column( string $content, string $column_name, int $term_id ): string {
		if ( self::COLUMN_KEY !== $column_name ) {
			return $content;
		}

		$term = $this->get_term( $term_id );
		if ( ! $term instanceof WP_Term || ! $this->editorial_service->is_supported_taxonomy( $term->taxonomy ) ) {
			return $content;
		}

		// Pre-fetch all visible terms for this taxonomy once if not yet loaded.
		$this->maybe_prefetch_terms( $term->taxonomy, $term_id );

		return $content . $this->render_term_languages_html( $term );
	}

	/**
	 * Pre-fetches visible term items from the global WP_List_Table if available.
	 *
	 * @param string $taxonomy Taxonomy slug.
	 * @param int    $fallback_term_id Fallback term ID to include.
	 * @return void
	 */
	private function maybe_prefetch_terms( string $taxonomy, int $fallback_term_id ): void {
		if ( isset( $this->prefetched_taxonomies[ $taxonomy ] ) ) {
			return;
		}

		$this->prefetched_taxonomies[ $taxonomy ] = true;

		$term_ids = array();
		if ( isset( $GLOBALS['wp_list_table']->items ) && is_array( $GLOBALS['wp_list_table']->items ) ) {
			foreach ( $GLOBALS['wp_list_table']->items as $item ) {
				if ( $item instanceof WP_Term ) {
					$term_ids[] = (int) $item->term_id;
				} elseif ( is_numeric( $item ) ) {
					$term_ids[] = (int) $item;
				}
			}
		}

		if ( ! in_array( $fallback_term_id, $term_ids, true ) ) {
			$term_ids[] = $fallback_term_id;
		}

		$this->editorial_service->get_editorial_data_for_elements( 'term', $term_ids, $taxonomy );
	}

	/**
	 * Generates HTML for a post's language status column.
	 *
	 * @param WP_Post $post Post object.
	 * @return string HTML output.
	 */
	public function render_post_languages_html( WP_Post $post ): string {
		$data = $this->editorial_service->get_editorial_data( 'post', $post->ID, $post->post_type );

		return $this->build_languages_html( 'post', $post->ID, $post->post_type, $data, $post );
	}

	/**
	 * Generates HTML for a term's language status column.
	 *
	 * @param WP_Term $term Term object.
	 * @return string HTML output.
	 */
	public function render_term_languages_html( WP_Term $term ): string {
		$data = $this->editorial_service->get_editorial_data( 'term', $term->term_id, $term->taxonomy );

		return $this->build_languages_html( 'term', $term->term_id, $term->taxonomy, $data, $term );
	}

	/**
	 * Builds structured, accessible HTML for the languages column.
	 *
	 * @param string               $element_type Element type ('post' or 'term').
	 * @param int                  $element_id   Object ID.
	 * @param string               $subtype      Subtype (post_type or taxonomy).
	 * @param array<string, mixed> $data         Aggregated editorial data.
	 * @param WP_Post|WP_Term|null $wp_object    WordPress object reference.
	 * @return string Rendered HTML.
	 */
	public function build_languages_html(
		string $element_type,
		int $element_id,
		string $subtype,
		array $data,
		WP_Post|WP_Term|null $wp_object = null
	): string {
		if ( ! ( $data['is_configured'] ?? false ) ) {
			return '<span class="tfml-unconfigured">—</span>';
		}

		if ( ! ( $data['is_managed'] ?? false ) ) {
			return sprintf(
				'<span class="tfml-badge tfml-badge--unmanaged" title="%s"><span class="dashicons dashicons-minus" aria-hidden="true"></span> <span class="screen-reader-text">%s: </span>%s</span>',
				esc_attr__( 'Sin idioma asignado', 'tf-multilingual' ),
				esc_html__( 'Estado', 'tf-multilingual' ),
				esc_html__( 'Sin idioma', 'tf-multilingual' )
			);
		}

		$current_code = $data['current_language'] ?? '';
		$active_langs = $data['active_languages'] ?? array();
		$translations = $data['translations'] ?? array();
		$missing      = $data['missing_languages'] ?? array();
		$inactive     = $data['inactive_translations'] ?? array();

		$can_create = ( 'post' === $element_type )
			? $this->can_user_create_post_translation( $element_id, $subtype )
			: $this->can_user_create_term_translation( $subtype );

		$output = '<div class="tfml-lang-group">';

		foreach ( $active_langs as $code => $lang ) {
			$lang_name = $lang->get_name();
			$code_disp = strtoupper( $code );

			if ( $code === $current_code ) {
				// Current object language badge.
				$output .= sprintf(
					'<strong class="tfml-badge tfml-badge--current" title="%s"><span class="screen-reader-text">%s </span>%s</strong>',
					esc_attr( $lang_name ),
					esc_html__( 'Idioma actual:', 'tf-multilingual' ),
					esc_html( $code_disp )
				);
			} elseif ( isset( $translations[ $code ] ) ) {
				// Existing translation with edit link.
				$edit_url = $translations[ $code ]['edit_url'] ?? '';
				$status   = $translations[ $code ]['status'] ?? TranslationStatus::UPDATED;

				if ( TranslationStatus::REVIEW === $status ) {
					/* translators: %s: Language name */
					$title = sprintf( __( 'Editar traducción en %s (requiere revisión)', 'tf-multilingual' ), $lang_name );
					/* translators: 1: Language name, 2: Language code */
					$aria = sprintf( __( 'Editar traducción en %1$s (%2$s) - requiere revisión', 'tf-multilingual' ), $lang_name, $code_disp );

					$output .= sprintf(
						'<a href="%s" class="tfml-link tfml-link--review" title="%s" aria-label="%s"><span class="dashicons dashicons-warning" aria-hidden="true"></span><span class="tfml-code">%s</span></a>',
						esc_url( $edit_url ),
						esc_attr( $title ),
						esc_attr( $aria ),
						esc_html( $code_disp )
					);
				} else {
					/* translators: %s: Language name */
					$title = sprintf( __( 'Editar traducción en %s', 'tf-multilingual' ), $lang_name );
					/* translators: 1: Language name, 2: Language code */
					$aria = sprintf( __( 'Editar traducción en %1$s (%2$s)', 'tf-multilingual' ), $lang_name, $code_disp );

					$output .= sprintf(
						'<a href="%s" class="tfml-link tfml-link--translated" title="%s" aria-label="%s"><span class="dashicons dashicons-yes" aria-hidden="true"></span><span class="tfml-code">%s</span></a>',
						esc_url( $edit_url ),
						esc_attr( $title ),
						esc_attr( $aria ),
						esc_html( $code_disp )
					);
				}
			} elseif ( isset( $missing[ $code ] ) ) {
				// Missing translation.
				if ( $can_create ) {
					/* translators: %s: Language name */
					$title = sprintf( __( 'Añadir traducción en %s', 'tf-multilingual' ), $lang_name );
					/* translators: 1: Language name, 2: Language code */
					$aria = sprintf( __( 'Añadir traducción en %1$s (%2$s)', 'tf-multilingual' ), $lang_name, $code_disp );

					if ( 'post' === $element_type ) {
						$nonce_value = function_exists( 'wp_create_nonce' )
							? wp_create_nonce( PostEditorialUi::NONCE_ACTION_CREATE . $element_id )
							: 'test_nonce';

						$output .= sprintf(
							'<form method="post" action="%s" class="tfml-inline-form" style="display:inline-block;margin:0;"><input type="hidden" name="action" value="tfml_create_post_translation"><input type="hidden" name="source_post_id" value="%d"><input type="hidden" name="target_language" value="%s"><input type="hidden" name="tfml_create_nonce" value="%s"><button type="submit" class="button-link tfml-link tfml-link--add" title="%s" aria-label="%s"><span class="dashicons dashicons-plus" aria-hidden="true"></span><span class="tfml-code">%s</span></button></form>',
							esc_url( function_exists( 'admin_url' ) ? admin_url( 'admin-post.php' ) : 'admin-post.php' ),
							$element_id,
							esc_attr( $code ),
							esc_attr( $nonce_value ),
							esc_attr( $title ),
							esc_attr( $aria ),
							esc_html( $code_disp )
						);
					} else {
						$nonce_value = function_exists( 'wp_create_nonce' )
							? wp_create_nonce( TermEditorialUi::NONCE_ACTION_CREATE . $element_id )
							: 'test_nonce';

						$term_name = ( $wp_object instanceof WP_Term && ! empty( $wp_object->name ) )
							? $wp_object->name . ' (' . $code . ')'
							: 'Term (' . $code . ')';

						$output .= sprintf(
							'<form method="post" action="%s" class="tfml-inline-form" style="display:inline-block;margin:0;"><input type="hidden" name="action" value="tfml_create_term_translation"><input type="hidden" name="source_term_id" value="%d"><input type="hidden" name="taxonomy" value="%s"><input type="hidden" name="target_language" value="%s"><input type="hidden" name="target_term_name" value="%s"><input type="hidden" name="tfml_create_term_nonce" value="%s"><button type="submit" class="button-link tfml-link tfml-link--add" title="%s" aria-label="%s"><span class="dashicons dashicons-plus" aria-hidden="true"></span><span class="tfml-code">%s</span></button></form>',
							esc_url( function_exists( 'admin_url' ) ? admin_url( 'admin-post.php' ) : 'admin-post.php' ),
							$element_id,
							esc_attr( $subtype ),
							esc_attr( $code ),
							esc_attr( $term_name ),
							esc_attr( $nonce_value ),
							esc_attr( $title ),
							esc_attr( $aria ),
							esc_html( $code_disp )
						);
					}
				} else {
					/* translators: %s: Language name */
					$title = sprintf( __( 'Sin traducción en %s (sin permisos para crear)', 'tf-multilingual' ), $lang_name );
					/* translators: 1: Language name, 2: Language code */
					$aria = sprintf( __( 'Sin traducción en %1$s (%2$s)', 'tf-multilingual' ), $lang_name, $code_disp );

					$output .= sprintf(
						'<span class="tfml-missing tfml-missing--forbidden" title="%s" aria-label="%s"><span class="dashicons dashicons-minus" aria-hidden="true"></span><span class="tfml-code">%s</span></span>',
						esc_attr( $title ),
						esc_attr( $aria ),
						esc_html( $code_disp )
					);
				}
			}
		}

		// Inactive translations display (read-only, no create action).
		foreach ( $inactive as $inact_code => $inact_info ) {
			/* translators: %s: Language name */
			$title = sprintf( __( '%s (inactivo)', 'tf-multilingual' ), $inact_info['language_name'] );
			/* translators: 1: Language name, 2: Language code */
			$aria = sprintf( __( 'Traducción inactiva en %1$s (%2$s)', 'tf-multilingual' ), $inact_info['language_name'], strtoupper( $inact_code ) );

			$output .= sprintf(
				'<span class="tfml-badge tfml-badge--inactive" title="%s" aria-label="%s"><span class="dashicons dashicons-hidden" aria-hidden="true"></span><span class="tfml-code">%s</span></span>',
				esc_attr( $title ),
				esc_attr( $aria ),
				esc_html( strtoupper( $inact_code ) )
			);
		}

		$output .= '</div>';

		return $output;
	}

	/**
	 * Checks user permission to create a translation for a given post.
	 *
	 * @param int    $post_id   Source post ID.
	 * @param string $post_type Post type.
	 * @return bool
	 */
	protected function can_user_create_post_translation( int $post_id, string $post_type ): bool {
		$post_type_obj = function_exists( 'get_post_type_object' ) ? get_post_type_object( $post_type ) : null;
		$create_cap    = ( $post_type_obj && isset( $post_type_obj->cap->create_posts ) ) ? $post_type_obj->cap->create_posts : 'edit_posts';

		if ( isset( $GLOBALS['wp_test_caps'][ $create_cap ] ) ) {
			if ( ! $GLOBALS['wp_test_caps'][ $create_cap ] ) {
				return false;
			}
		} elseif ( function_exists( 'current_user_can' ) && ! current_user_can( $create_cap ) ) {
			return false;
		}

		if ( isset( $GLOBALS['wp_test_caps']['edit_post'] ) ) {
			return (bool) $GLOBALS['wp_test_caps']['edit_post'];
		}

		if ( function_exists( 'current_user_can' ) ) {
			$post = $this->get_post( $post_id );
			if ( $post instanceof WP_Post ) {
				$current_user_id = function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0;
				if ( (int) $post->post_author === $current_user_id ) {
					return true;
				}
				$edit_others = ( $post_type_obj && isset( $post_type_obj->cap->edit_others_posts ) ) ? $post_type_obj->cap->edit_others_posts : 'edit_others_posts';
				return current_user_can( $edit_others );
			}
			return current_user_can( 'edit_post', $post_id );
		}

		return true;
	}

	/**
	 * Checks user permission to create a translation for a given taxonomy.
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @return bool
	 */
	protected function can_user_create_term_translation( string $taxonomy ): bool {
		$tax_obj  = function_exists( 'get_taxonomy' ) ? get_taxonomy( $taxonomy ) : null;
		$edit_cap = ( $tax_obj && isset( $tax_obj->cap->edit_terms ) ) ? $tax_obj->cap->edit_terms : 'edit_terms';

		if ( isset( $GLOBALS['wp_test_caps'][ $edit_cap ] ) ) {
			return (bool) $GLOBALS['wp_test_caps'][ $edit_cap ];
		}

		if ( function_exists( 'current_user_can' ) ) {
			return current_user_can( $edit_cap );
		}

		return true;
	}

	/**
	 * Retrieves post by ID.
	 *
	 * @param int $post_id Post ID.
	 * @return WP_Post|null
	 */
	protected function get_post( int $post_id ): ?WP_Post {
		if ( function_exists( 'get_post' ) ) {
			$post = get_post( $post_id );
			return $post instanceof WP_Post ? $post : null;
		}

		return $GLOBALS['wp_test_posts'][ $post_id ] ?? null;
	}

	/**
	 * Retrieves term by ID.
	 *
	 * @param int $term_id Term ID.
	 * @return WP_Term|null
	 */
	protected function get_term( int $term_id ): ?WP_Term {
		if ( function_exists( 'get_term' ) ) {
			$term = get_term( $term_id );
			return $term instanceof WP_Term ? $term : null;
		}

		return $GLOBALS['wp_test_terms'][ $term_id ] ?? null;
	}

	/**
	 * Renders CSS styles for the language columns in admin list tables.
	 *
	 * @return void
	 */
	public function render_admin_styles(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( null !== $screen && ! in_array( $screen->base, array( 'edit', 'edit-tags' ), true ) ) {
			return;
		}
		?>
		<style>
			.manage-column.column-tfml_languages { width: 140px; }
			.tfml-lang-group { display: inline-flex; align-items: center; gap: 6px; flex-wrap: wrap; }
			.tfml-badge--current { background: #dcdcde; color: #1d2327; padding: 2px 6px; border-radius: 3px; font-size: 11px; line-height: 1.4; display: inline-flex; align-items: center; }
			.tfml-link--translated { text-decoration: none; color: #2271b1; display: inline-flex; align-items: center; font-size: 11px; line-height: 1.4; }
			.tfml-link--translated .dashicons { font-size: 14px; width: 14px; height: 14px; color: #46b450; vertical-align: middle; margin-right: 1px; }
			.tfml-link--review { text-decoration: none; color: #b26b00; display: inline-flex; align-items: center; font-size: 11px; line-height: 1.4; }
			.tfml-link--review .dashicons { font-size: 14px; width: 14px; height: 14px; color: #dba617; vertical-align: middle; margin-right: 1px; }
			.tfml-link--add { text-decoration: none; color: #2271b1; padding: 0; margin: 0; border: none; background: transparent; cursor: pointer; display: inline-flex; align-items: center; font-size: 11px; line-height: 1.4; }
			.tfml-link--add .dashicons { font-size: 14px; width: 14px; height: 14px; vertical-align: middle; margin-right: 1px; }
			.tfml-link--add:hover { color: #135e96; text-decoration: underline; }
			.tfml-badge--unmanaged { color: #8c8f94; font-size: 12px; display: inline-flex; align-items: center; gap: 3px; }
			.tfml-badge--unmanaged .dashicons { font-size: 14px; width: 14px; height: 14px; }
			.tfml-badge--inactive { color: #a7aaad; font-size: 11px; display: inline-flex; align-items: center; gap: 2px; }
			.tfml-badge--inactive .dashicons { font-size: 14px; width: 14px; height: 14px; }
			.tfml-missing--forbidden { color: #c3c4c7; font-size: 11px; display: inline-flex; align-items: center; gap: 2px; cursor: not-allowed; }
			.tfml-missing--forbidden .dashicons { font-size: 14px; width: 14px; height: 14px; }
		</style>
		<?php
	}
}
