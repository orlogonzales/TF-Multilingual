<?php
/**
 * Term Editorial UI Component.
 *
 * @package TF\Multilingual\Admin
 */

declare( strict_types=1 );

namespace TF\Multilingual\Admin;

use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Editorial\Exceptions\EditorialException;
use TF\Multilingual\Editorial\TranslationEditorialService;
use WP_Term;

/**
 * Class TermEditorialUi
 *
 * Handles WordPress Admin integration for taxonomies and terms (term.php, edit-tags.php).
 * Renders language selector and translation links, and handles term translation creation.
 */
class TermEditorialUi {

	/**
	 * Nonce action for saving term editorial data.
	 */
	public const NONCE_ACTION_SAVE = 'tfml_save_term_editorial';

	/**
	 * Nonce action prefix for creating a term translation.
	 */
	public const NONCE_ACTION_CREATE = 'tfml_create_term_translation_';

	/**
	 * Translation editorial service.
	 *
	 * @var TranslationEditorialService
	 */
	private TranslationEditorialService $editorial_service;

	/**
	 * Language registry.
	 *
	 * @var LanguageRegistry
	 */
	private LanguageRegistry $language_registry;

	/**
	 * Constructor.
	 *
	 * @param TranslationEditorialService $editorial_service Editorial service.
	 * @param LanguageRegistry            $language_registry Language registry.
	 */
	public function __construct(
		TranslationEditorialService $editorial_service,
		LanguageRegistry $language_registry
	) {
		$this->editorial_service = $editorial_service;
		$this->language_registry = $language_registry;
	}

	/**
	 * Registers WordPress admin hooks.
	 *
	 * @return void
	 */
	public function init_hooks(): void {
		add_action( 'admin_init', array( $this, 'register_taxonomy_hooks' ) );
		add_action( 'admin_post_tfml_create_term_translation', array( $this, 'handle_create_term_translation' ) );
		add_action( 'admin_notices', array( $this, 'render_admin_notices' ) );
	}

	/**
	 * Dynamically registers form hooks for all eligible taxonomies.
	 *
	 * @return void
	 */
	public function register_taxonomy_hooks(): void {
		if ( ! $this->language_registry->is_configured() ) {
			return;
		}

		$taxonomies = get_taxonomies( array( 'show_ui' => true ), 'names' );
		foreach ( $taxonomies as $taxonomy ) {
			if ( ! $this->editorial_service->is_supported_taxonomy( $taxonomy ) ) {
				continue;
			}

			add_action( "{$taxonomy}_edit_form_fields", array( $this, 'render_edit_form_fields' ), 10, 2 );
			add_action( "{$taxonomy}_add_form_fields", array( $this, 'render_add_form_fields' ), 10, 1 );
			add_action( "created_{$taxonomy}", array( $this, 'handle_save_term' ), 10, 2 );
			add_action( "edited_{$taxonomy}", array( $this, 'handle_save_term' ), 10, 2 );
		}
	}

	/**
	 * Renders term fields on the edit term screen (term.php).
	 *
	 * @param WP_Term $tag      Term being edited.
	 * @param string  $taxonomy Taxonomy slug.
	 * @return void
	 */
	public function render_edit_form_fields( WP_Term $tag, string $taxonomy ): void {
		$data = $this->editorial_service->get_editorial_data( 'term', $tag->term_id, $taxonomy );

		wp_nonce_field( self::NONCE_ACTION_SAVE, 'tfml_term_editorial_nonce' );

		?>
		<tr class="form-field tfml-term-editorial-wrap">
			<th scope="row">
				<label><?php esc_html_e( 'TF Multilingual', 'tf-multilingual' ); ?></label>
			</th>
			<td>
				<?php if ( ! $data['is_managed'] ) : ?>
					<p><em><?php esc_html_e( 'This term is not yet managed by TF Multilingual.', 'tf-multilingual' ); ?></em></p>
					<label for="tfml_assign_term_language"><strong><?php esc_html_e( 'Assign Language:', 'tf-multilingual' ); ?></strong></label><br>
					<select name="tfml_assign_term_language" id="tfml_assign_term_language" style="max-width: 300px; margin-top: 4px;">
						<option value=""><?php esc_html_e( '— Select language —', 'tf-multilingual' ); ?></option>
						<?php foreach ( $data['active_languages'] as $code => $lang ) : ?>
							<option value="<?php echo esc_attr( $code ); ?>">
								<?php echo esc_html( $lang->get_name() . ' (' . $code . ')' ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="description">
						<?php esc_html_e( 'Select a language and click Update to manage this term.', 'tf-multilingual' ); ?>
					</p>
				<?php else : ?>
					<p>
						<strong><?php esc_html_e( 'Current Language:', 'tf-multilingual' ); ?></strong>
						<span style="font-weight: 600; color: #1d2327;">
							<?php echo esc_html( $data['current_language_name'] . ' (' . $data['current_language'] . ')' ); ?>
						</span>
					</p>

					<p><strong><?php esc_html_e( 'Translations:', 'tf-multilingual' ); ?></strong></p>
					<ul style="margin: 0 0 16px 0; padding: 0; list-style: none;">
						<?php foreach ( $data['translations'] as $code => $tinfo ) : ?>
							<li style="margin-bottom: 6px;">
								<?php if ( $tinfo['is_current'] ) : ?>
									<strong><?php echo esc_html( $tinfo['language_name'] ); ?></strong>
									<span class="dashicons dashicons-yes" style="color: #46b450;"></span>
								<?php else : ?>
									<?php echo esc_html( $tinfo['language_name'] ); ?>
									<?php if ( ! empty( $tinfo['edit_url'] ) ) : ?>
										— <a href="<?php echo esc_url( $tinfo['edit_url'] ); ?>"><?php esc_html_e( 'Edit', 'tf-multilingual' ); ?></a>
									<?php endif; ?>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>

					<?php if ( ! empty( $data['missing_languages'] ) ) : ?>
						<p><strong><?php esc_html_e( 'Create Missing Translation:', 'tf-multilingual' ); ?></strong></p>
						<?php foreach ( $data['missing_languages'] as $code => $minfo ) : ?>
							<div style="background: #f0f0f1; padding: 8px 12px; border-radius: 4px; margin-bottom: 8px; max-width: 450px;">
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display: flex; gap: 8px; align-items: center;">
									<input type="hidden" name="action" value="tfml_create_term_translation">
									<input type="hidden" name="source_term_id" value="<?php echo esc_attr( (string) $tag->term_id ); ?>">
									<input type="hidden" name="taxonomy" value="<?php echo esc_attr( $taxonomy ); ?>">
									<input type="hidden" name="target_language" value="<?php echo esc_attr( $code ); ?>">
									<?php wp_nonce_field( self::NONCE_ACTION_CREATE . $tag->term_id, 'tfml_create_term_nonce' ); ?>
									<label style="font-weight: 600; min-width: 70px;"><?php echo esc_html( $minfo['language_name'] ); ?>:</label>
									<input type="text" name="target_term_name" placeholder="<?php esc_attr_e( 'New term name', 'tf-multilingual' ); ?>" required style="flex: 1;">
									<button type="submit" class="button button-secondary button-small">
										<?php esc_html_e( 'Create', 'tf-multilingual' ); ?>
									</button>
								</form>
							</div>
						<?php endforeach; ?>
					<?php endif; ?>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Renders term fields on the add term screen (edit-tags.php).
	 *
	 * @param string $taxonomy Taxonomy slug.
	 * @return void
	 */
	public function render_add_form_fields( string $taxonomy ): void {
		$active_langs = $this->language_registry->get_active();
		$default_lang = $this->language_registry->get_default();
		$default_code = null !== $default_lang ? $default_lang->get_code() : '';

		wp_nonce_field( self::NONCE_ACTION_SAVE, 'tfml_term_editorial_nonce' );
		?>
		<div class="form-field tfml-add-term-wrap">
			<label for="tfml_assign_term_language"><?php esc_html_e( 'Language (TFML)', 'tf-multilingual' ); ?></label>
			<select name="tfml_assign_term_language" id="tfml_assign_term_language">
				<option value=""><?php esc_html_e( '— Select language —', 'tf-multilingual' ); ?></option>
				<?php foreach ( $active_langs as $code => $lang ) : ?>
					<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $code, $default_code ); ?>>
						<?php echo esc_html( $lang->get_name() . ' (' . $code . ')' ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<p><?php esc_html_e( 'Assign an initial language to manage this term in TF Multilingual.', 'tf-multilingual' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Handles term saving to assign initial language.
	 *
	 * @param int $term_id Term ID.
	 * @param int $tt_id   Term taxonomy ID.
	 * @return void
	 */
	public function handle_save_term( int $term_id, int $tt_id ): void {
		if ( ! isset( $_POST['tfml_term_editorial_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['tfml_term_editorial_nonce'] ) ), self::NONCE_ACTION_SAVE ) ) {
			return;
		}

		$taxonomy = isset( $_POST['taxonomy'] ) ? sanitize_text_field( wp_unslash( $_POST['taxonomy'] ) ) : '';
		if ( '' === $taxonomy ) {
			$term = get_term( $term_id );
			if ( $term instanceof WP_Term ) {
				$taxonomy = $term->taxonomy;
			}
		}

		$tax_obj  = get_taxonomy( $taxonomy );
		$edit_cap = $tax_obj ? $tax_obj->cap->edit_terms : 'manage_categories';
		if ( ! current_user_can( $edit_cap ) ) {
			return;
		}

		if ( ! empty( $_POST['tfml_assign_term_language'] ) ) {
			$language_code = sanitize_text_field( wp_unslash( $_POST['tfml_assign_term_language'] ) );

			try {
				$this->editorial_service->assign_initial_language( 'term', $term_id, $taxonomy, $language_code );
			} catch ( EditorialException $e ) {
				// Silently fail in save hook context.
			}
		}
	}

	/**
	 * Handles POST action to create a new translation term.
	 *
	 * @return void
	 */
	public function handle_create_term_translation(): void {
		$source_id   = isset( $_POST['source_term_id'] ) ? absint( $_POST['source_term_id'] ) : 0;
		$taxonomy    = isset( $_POST['taxonomy'] ) ? sanitize_text_field( wp_unslash( $_POST['taxonomy'] ) ) : '';
		$target_lang = isset( $_POST['target_language'] ) ? sanitize_text_field( wp_unslash( $_POST['target_language'] ) ) : '';
		$term_name   = isset( $_POST['target_term_name'] ) ? sanitize_text_field( wp_unslash( $_POST['target_term_name'] ) ) : '';

		// Verify nonce.
		$nonce = isset( $_POST['tfml_create_term_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['tfml_create_term_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION_CREATE . $source_id ) ) {
			wp_die( esc_html__( 'Security check failed. Please refresh and try again.', 'tf-multilingual' ), 403 );
		}

		// Verify capability.
		$tax_obj  = get_taxonomy( $taxonomy );
		$edit_cap = $tax_obj ? $tax_obj->cap->edit_terms : 'manage_categories';
		if ( ! current_user_can( $edit_cap ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to edit terms in this taxonomy.', 'tf-multilingual' ), 403 );
		}

		try {
			$new_term_id = $this->editorial_service->create_term_translation( $source_id, $taxonomy, $target_lang, $term_name );
			$redirect    = get_edit_term_link( $new_term_id, $taxonomy );

			if ( is_string( $redirect ) && '' !== $redirect ) {
				wp_safe_redirect( $redirect );
				exit;
			}
		} catch ( EditorialException $e ) {
			$return_url = get_edit_term_link( $source_id, $taxonomy );
			if ( is_string( $return_url ) && '' !== $return_url ) {
				$error_url = add_query_arg( 'tfml_error', rawurlencode( $e->getMessage() ), $return_url );
				wp_safe_redirect( $error_url );
				exit;
			}
			wp_die( esc_html( $e->getMessage() ), 400 );
		}

		wp_safe_redirect( admin_url( "edit-tags.php?taxonomy={$taxonomy}" ) );
		exit;
	}

	/**
	 * Renders administrative notices for term editorial operations.
	 *
	 * @return void
	 */
	public function render_admin_notices(): void {
		if ( ! empty( $_GET['tfml_error'] ) ) {
			$message = sanitize_text_field( wp_unslash( $_GET['tfml_error'] ) );
			?>
			<div class="notice notice-error is-dismissible">
				<p><strong><?php esc_html_e( 'TF Multilingual Error:', 'tf-multilingual' ); ?></strong> <?php echo esc_html( $message ); ?></p>
			</div>
			<?php
		}
	}
}
