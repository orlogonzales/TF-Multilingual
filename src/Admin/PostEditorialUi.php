<?php
/**
 * Post Editorial UI Component.
 *
 * @package TF\Multilingual\Admin
 */

declare( strict_types=1 );

namespace TF\Multilingual\Admin;

use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Editorial\Exceptions\EditorialException;
use TF\Multilingual\Editorial\TranslationEditorialService;
use WP_Post;

/**
 * Class PostEditorialUi
 *
 * Handles WordPress Admin integration for Posts, Pages, and CPTs.
 * Renders native meta boxes and processes language assignment and translation creation.
 */
class PostEditorialUi {

	/**
	 * Nonce action for saving post editorial data.
	 */
	public const NONCE_ACTION_SAVE = 'tfml_save_post_editorial';

	/**
	 * Nonce action prefix for creating a translation.
	 */
	public const NONCE_ACTION_CREATE = 'tfml_create_post_translation_';

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
		add_action( 'add_meta_boxes', array( $this, 'register_meta_box' ), 10, 2 );
		add_action( 'save_post', array( $this, 'handle_save_post' ), 10, 3 );
		add_action( 'admin_post_tfml_create_post_translation', array( $this, 'handle_create_translation' ) );
		add_action( 'admin_notices', array( $this, 'render_admin_notices' ) );
	}

	/**
	 * Registers native WordPress meta box for eligible post types.
	 *
	 * @param string       $post_type Post type.
	 * @param WP_Post|null $post      Current post object.
	 * @return void
	 */
	public function register_meta_box( string $post_type, ?WP_Post $post = null ): void {
		if ( ! $this->language_registry->is_configured() ) {
			return;
		}

		if ( ! $this->editorial_service->is_supported_post_type( $post_type ) ) {
			return;
		}

		add_meta_box(
			'tfml_editorial_box',
			__( 'Language & Translations (TFML)', 'tf-multilingual' ),
			array( $this, 'render_meta_box' ),
			$post_type,
			'side',
			'high'
		);
	}

	/**
	 * Renders the native meta box content.
	 *
	 * @param WP_Post $post Current post.
	 * @return void
	 */
	public function render_meta_box( WP_Post $post ): void {
		$data = $this->editorial_service->get_editorial_data( 'post', $post->ID, $post->post_type );

		wp_nonce_field( self::NONCE_ACTION_SAVE, 'tfml_editorial_nonce' );

		if ( ! $data['is_managed'] ) {
			$this->render_unmanaged_ui( $post, $data );
		} else {
			$this->render_managed_ui( $post, $data );
		}
	}

	/**
	 * Renders UI for an unmanaged post (allowing explicit language assignment).
	 *
	 * @param WP_Post              $post Post object.
	 * @param array<string, mixed> $data Editorial data.
	 * @return void
	 */
	private function render_unmanaged_ui( WP_Post $post, array $data ): void {
		$active_langs = $data['active_languages'];
		$default_lang = $this->language_registry->get_default();
		$default_code = null !== $default_lang ? $default_lang->get_code() : '';

		?>
		<div class="tfml-editorial-container">
			<p>
				<em><?php esc_html_e( 'This content is not yet managed by TF Multilingual.', 'tf-multilingual' ); ?></em>
			</p>
			<p>
				<label for="tfml_assign_language"><strong><?php esc_html_e( 'Assign Language:', 'tf-multilingual' ); ?></strong></label><br>
				<select name="tfml_assign_language" id="tfml_assign_language" class="widefat" style="margin-top: 4px;">
					<option value=""><?php esc_html_e( '— Select language —', 'tf-multilingual' ); ?></option>
					<?php foreach ( $active_langs as $code => $lang ) : ?>
						<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $code, $default_code ); ?>>
							<?php echo esc_html( $lang->get_name() . ' (' . $code . ')' ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</p>
			<p class="description">
				<?php esc_html_e( 'Select a language and update/save the post to establish multilingual tracking.', 'tf-multilingual' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Renders UI for a managed post (showing current language, translations, and create buttons).
	 *
	 * @param WP_Post              $post Post object.
	 * @param array<string, mixed> $data Editorial data.
	 * @return void
	 */
	private function render_managed_ui( WP_Post $post, array $data ): void {
		$current_code = $data['current_language'];
		$current_name = $data['current_language_name'];
		$translations = $data['translations'];
		$missing      = $data['missing_languages'];
		$inactive     = $data['inactive_translations'];
		?>
		<div class="tfml-editorial-container">
			<p>
				<strong><?php esc_html_e( 'Current Language:', 'tf-multilingual' ); ?></strong><br>
				<span class="tfml-current-lang" style="font-size: 1.1em; color: #1d2327;">
					<?php echo esc_html( $current_name . ' (' . $current_code . ')' ); ?>
				</span>
			</p>

			<hr style="margin: 12px 0; border: 0; border-top: 1px solid #dcdcde;">

			<p><strong><?php esc_html_e( 'Translations:', 'tf-multilingual' ); ?></strong></p>
			<ul class="tfml-translations-list" style="margin: 0; padding: 0; list-style: none;">
				<?php
				// 1. Existing active translations.
				foreach ( $translations as $code => $tinfo ) :
					?>
					<li style="margin-bottom: 8px; display: flex; justify-content: space-between; align-items: center;">
						<span>
							<?php if ( $tinfo['is_current'] ) : ?>
								<strong><?php echo esc_html( $tinfo['language_name'] ); ?></strong>
								<span class="dashicons dashicons-yes" style="color: #46b450;" title="<?php esc_attr_e( 'Current', 'tf-multilingual' ); ?>"></span>
							<?php else : ?>
								<?php echo esc_html( $tinfo['language_name'] ); ?>
							<?php endif; ?>
						</span>
						<span>
							<?php if ( ! $tinfo['is_current'] && ! empty( $tinfo['edit_url'] ) ) : ?>
								<a href="<?php echo esc_url( $tinfo['edit_url'] ); ?>" class="button button-small">
									<?php esc_html_e( 'Edit', 'tf-multilingual' ); ?>
								</a>
							<?php endif; ?>
						</span>
					</li>
				<?php endforeach; ?>

				<?php
				// 2. Existing inactive translations.
				foreach ( $inactive as $code => $tinfo ) :
					?>
					<li style="margin-bottom: 8px; display: flex; justify-content: space-between; align-items: center; color: #8c8f94;">
						<span>
							<?php echo esc_html( $tinfo['language_name'] . ' (' . __( 'Inactive', 'tf-multilingual' ) . ')' ); ?>
						</span>
						<span>
							<?php if ( ! empty( $tinfo['edit_url'] ) ) : ?>
								<a href="<?php echo esc_url( $tinfo['edit_url'] ); ?>" class="button button-small">
									<?php esc_html_e( 'Edit', 'tf-multilingual' ); ?>
								</a>
							<?php endif; ?>
						</span>
					</li>
				<?php endforeach; ?>
			</ul>

			<?php if ( ! empty( $missing ) ) : ?>
				<hr style="margin: 12px 0; border: 0; border-top: 1px solid #dcdcde;">
				<p><strong><?php esc_html_e( 'Add Translation:', 'tf-multilingual' ); ?></strong></p>
				<div class="tfml-add-translations" style="display: flex; flex-direction: column; gap: 6px;">
					<?php foreach ( $missing as $code => $minfo ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="tfml_create_post_translation">
							<input type="hidden" name="source_post_id" value="<?php echo esc_attr( (string) $post->ID ); ?>">
							<input type="hidden" name="target_language" value="<?php echo esc_attr( $code ); ?>">
							<?php wp_nonce_field( self::NONCE_ACTION_CREATE . $post->ID, 'tfml_create_nonce' ); ?>
							<button type="submit" class="button button-secondary button-small" style="width: 100%; text-align: left;">
								<span class="dashicons dashicons-plus-alt2" style="vertical-align: middle; margin-right: 4px;"></span>
								<?php
								/* translators: %s: Language name */
								echo esc_html( sprintf( __( 'Create %s translation', 'tf-multilingual' ), $minfo['language_name'] ) );
								?>
							</button>
						</form>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Handles post saving to assign initial language if requested.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @param bool    $update  Whether this is an existing post update.
	 * @return void
	 */
	public function handle_save_post( int $post_id, WP_Post $post, bool $update ): void {
		// Nonce check.
		if ( ! isset( $_POST['tfml_editorial_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['tfml_editorial_nonce'] ) ), self::NONCE_ACTION_SAVE ) ) {
			return;
		}

		// Skip autosaves and revisions.
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		// Capability check.
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Check for language assignment on unmanaged posts.
		if ( ! empty( $_POST['tfml_assign_language'] ) ) {
			$language_code = sanitize_text_field( wp_unslash( $_POST['tfml_assign_language'] ) );

			try {
				$this->editorial_service->assign_initial_language( 'post', $post_id, $post->post_type, $language_code );
			} catch ( EditorialException $e ) {
				// Silently fail or log in save_post context.
			}
		}
	}

	/**
	 * Handles POST action to create a new translation post.
	 *
	 * @return void
	 */
	public function handle_create_translation(): void {
		$source_id   = isset( $_POST['source_post_id'] ) ? absint( $_POST['source_post_id'] ) : 0;
		$target_lang = isset( $_POST['target_language'] ) ? sanitize_text_field( wp_unslash( $_POST['target_language'] ) ) : '';

		// Verify nonce.
		$nonce = isset( $_POST['tfml_create_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['tfml_create_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION_CREATE . $source_id ) ) {
			wp_die( esc_html__( 'Security check failed. Please refresh and try again.', 'tf-multilingual' ), 403 );
		}

		// Verify capability.
		if ( ! current_user_can( 'edit_post', $source_id ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to create translations for this post.', 'tf-multilingual' ), 403 );
		}

		try {
			$new_post_id = $this->editorial_service->create_post_translation( $source_id, $target_lang );
			$redirect    = get_edit_post_link( $new_post_id, 'raw' );

			if ( null !== $redirect ) {
				wp_safe_redirect( $redirect );
				exit;
			}
		} catch ( EditorialException $e ) {
			$return_url = get_edit_post_link( $source_id, 'raw' );
			if ( null !== $return_url ) {
				$error_url = add_query_arg( 'tfml_error', rawurlencode( $e->getMessage() ), $return_url );
				wp_safe_redirect( $error_url );
				exit;
			}
			wp_die( esc_html( $e->getMessage() ), 400 );
		}

		wp_safe_redirect( admin_url( 'edit.php' ) );
		exit;
	}

	/**
	 * Renders administrative notices for editorial operations.
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
