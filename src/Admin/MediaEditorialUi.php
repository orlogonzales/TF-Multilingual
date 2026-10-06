<?php
/**
 * Media Editorial UI Component.
 *
 * @package TF\Multilingual\Admin
 */

declare( strict_types=1 );

namespace TF\Multilingual\Admin;

use TF\Multilingual\Domain\Language\Language;
use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Domain\Media\MediaTranslation;
use TF\Multilingual\Domain\Media\MediaTranslationRepository;
use TF\Multilingual\Domain\Media\MediaTranslationResolver;
use WP_Post;

/**
 * Class MediaEditorialUi
 *
 * Provides native WordPress admin integration for editing multilingual media metadata.
 * Adds a dedicated meta box on the attachment edit screen with clean per-language panels.
 */
class MediaEditorialUi {

	/**
	 * Nonce action name for media translations.
	 */
	public const NONCE_ACTION = 'tfml_media_editorial_action';

	/**
	 * Nonce field name.
	 */
	public const NONCE_NAME = 'tfml_media_nonce';

	/**
	 * Media translation repository.
	 *
	 * @var MediaTranslationRepository
	 */
	private MediaTranslationRepository $repository;

	/**
	 * Media translation resolver.
	 *
	 * @var MediaTranslationResolver
	 */
	private MediaTranslationResolver $resolver;

	/**
	 * Language registry.
	 *
	 * @var LanguageRegistry
	 */
	private LanguageRegistry $language_registry;

	/**
	 * Constructor.
	 *
	 * @param MediaTranslationRepository $repository        Repository instance.
	 * @param MediaTranslationResolver   $resolver          Resolver instance.
	 * @param LanguageRegistry           $language_registry Language registry.
	 */
	public function __construct(
		MediaTranslationRepository $repository,
		MediaTranslationResolver $resolver,
		LanguageRegistry $language_registry
	) {
		$this->repository        = $repository;
		$this->resolver          = $resolver;
		$this->language_registry = $language_registry;
	}

	/**
	 * Registers WordPress admin hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		if ( function_exists( 'add_action' ) ) {
			add_action( 'add_meta_boxes_attachment', array( $this, 'register_meta_box' ) );
			add_action( 'edit_attachment', array( $this, 'save_translations' ), 10, 1 );
		}
	}

	/**
	 * Registers the meta box on the attachment edit screen.
	 *
	 * @param WP_Post|null $post Attachment post object.
	 * @return void
	 */
	public function register_meta_box( ?WP_Post $post = null ): void {
		if ( function_exists( 'add_meta_box' ) ) {
			add_meta_box(
				'tfml_media_translations_meta_box',
				__( 'TF Multilingual — Traducciones de Medios', 'tf-multilingual' ),
				array( $this, 'render_meta_box' ),
				'attachment',
				'normal',
				'high'
			);
		}
	}

	/**
	 * Renders the meta box HTML content on the attachment edit screen.
	 *
	 * @param WP_Post $post Attachment post object.
	 * @return void
	 */
	public function render_meta_box( WP_Post $post ): void {
		if ( ! function_exists( 'wp_nonce_field' ) ) {
			return;
		}

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );

		$attachment_id    = (int) $post->ID;
		$active_languages = $this->language_registry->active();
		$translations     = $this->repository->find_by_attachment( $attachment_id );
		$default_lang     = $this->language_registry->get_default_code();

		?>
		<div class="tfml-media-editorial-wrap" style="padding: 10px 0;">
			<p class="description" style="margin-bottom: 15px;">
				<?php echo esc_html__( 'Gestiona los textos editoriales (ALT, Título, Leyenda y Descripción) de este archivo para cada idioma activo. El archivo físico se comparte sin duplicación.', 'tf-multilingual' ); ?>
			</p>

			<div class="tfml-media-tabs" style="border-bottom: 1px solid #c3c4c7; margin-bottom: 20px;">
				<?php foreach ( $active_languages as $index => $lang ) : ?>
					<?php
					$code      = $lang->get_code();
					$has_trans = isset( $translations[ $code ] );
					$is_def    = ( $code === $default_lang );
					?>
					<span class="tfml-tab-button button <?php echo 0 === $index ? 'button-primary' : ''; ?>"
						data-target="tfml-lang-panel-<?php echo esc_attr( $code ); ?>"
						style="margin-right: 5px; margin-bottom: -1px; cursor: pointer;">
						<strong><?php echo esc_html( strtoupper( $code ) ); ?></strong> — <?php echo esc_html( $lang->get_name() ); ?>
						<?php if ( $has_trans ) : ?>
							<span class="dashicons dashicons-yes" style="font-size: 16px; line-height: 22px; color: #46b450;" title="<?php echo esc_attr__( 'Traducido en TFML', 'tf-multilingual' ); ?>"></span>
						<?php else : ?>
							<span class="dashicons dashicons-minus" style="font-size: 16px; line-height: 22px; color: #999;" title="<?php echo esc_attr__( 'Usa metadatos Core', 'tf-multilingual' ); ?>"></span>
						<?php endif; ?>
					</span>
				<?php endforeach; ?>
			</div>

			<?php foreach ( $active_languages as $index => $lang ) : ?>
				<?php
				$code        = $lang->get_code();
				$translation = $translations[ $code ] ?? null;
				$resolved    = ( null !== $translation ) ? $translation : $this->resolver->resolve( $attachment_id, $code );
				$is_fallback = $resolved->is_fallback();
				?>
				<div id="tfml-lang-panel-<?php echo esc_attr( $code ); ?>" class="tfml-lang-panel" style="<?php echo 0 === $index ? 'display: block;' : 'display: none;'; ?> background: #fff; border: 1px solid #ccd0d4; padding: 15px; margin-bottom: 15px;">
					<div style="margin-bottom: 12px; display: flex; align-items: center; justify-content: space-between;">
						<h3 style="margin: 0; font-size: 14px;">
							<?php echo esc_html( $lang->get_name() ); ?> (<code><?php echo esc_html( $code ); ?></code>)
						</h3>
						<?php if ( $is_fallback ) : ?>
							<span style="background: #e7f3fe; color: #1e73be; padding: 3px 8px; border-radius: 3px; font-size: 12px;">
								<?php echo esc_html__( 'Sin traducción explícita — Usa metadatos de WordPress Core', 'tf-multilingual' ); ?>
							</span>
						<?php else : ?>
							<span style="background: #e7f8e7; color: #2e7d32; padding: 3px 8px; border-radius: 3px; font-size: 12px;">
								<?php echo esc_html__( 'Traducido en TFML', 'tf-multilingual' ); ?>
							</span>
						<?php endif; ?>
					</div>

					<input type="hidden" name="tfml_media[<?php echo esc_attr( $code ); ?>][active]" value="1" />

					<table class="form-table" role="presentation" style="margin-top: 0;">
						<tbody>
							<tr>
								<th scope="row" style="width: 150px;">
									<label for="tfml_media_<?php echo esc_attr( $code ); ?>_alt"><?php echo esc_html__( 'Texto Alternativo (ALT)', 'tf-multilingual' ); ?></label>
								</th>
								<td>
									<input type="text"
										id="tfml_media_<?php echo esc_attr( $code ); ?>_alt"
										name="tfml_media[<?php echo esc_attr( $code ); ?>][alt_text]"
										value="<?php echo esc_attr( $resolved->get_alt_text() ); ?>"
										class="large-text" />
									<p class="description"><?php echo esc_html__( 'Texto para accesibilidad y SEO.', 'tf-multilingual' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="tfml_media_<?php echo esc_attr( $code ); ?>_title"><?php echo esc_html__( 'Título', 'tf-multilingual' ); ?></label>
								</th>
								<td>
									<input type="text"
										id="tfml_media_<?php echo esc_attr( $code ); ?>_title"
										name="tfml_media[<?php echo esc_attr( $code ); ?>][title]"
										value="<?php echo esc_attr( $resolved->get_title() ); ?>"
										class="large-text" />
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="tfml_media_<?php echo esc_attr( $code ); ?>_caption"><?php echo esc_html__( 'Leyenda (Caption)', 'tf-multilingual' ); ?></label>
								</th>
								<td>
									<textarea
										id="tfml_media_<?php echo esc_attr( $code ); ?>_caption"
										name="tfml_media[<?php echo esc_attr( $code ); ?>][caption]"
										rows="3"
										class="large-text"><?php echo esc_textarea( $resolved->get_caption() ); ?></textarea>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="tfml_media_<?php echo esc_attr( $code ); ?>_description"><?php echo esc_html__( 'Descripción', 'tf-multilingual' ); ?></label>
								</th>
								<td>
									<textarea
										id="tfml_media_<?php echo esc_attr( $code ); ?>_description"
										name="tfml_media[<?php echo esc_attr( $code ); ?>][description]"
										rows="4"
										class="large-text"><?php echo esc_textarea( $resolved->get_description() ); ?></textarea>
								</td>
							</tr>
						</tbody>
					</table>
				</div>
			<?php endforeach; ?>
		</div>

		<script>
		document.addEventListener('DOMContentLoaded', function() {
			var buttons = document.querySelectorAll('.tfml-tab-button');
			var panels = document.querySelectorAll('.tfml-lang-panel');

			buttons.forEach(function(btn) {
				btn.addEventListener('click', function(e) {
					e.preventDefault();
					var targetId = this.getAttribute('data-target');

					buttons.forEach(function(b) { b.classList.remove('button-primary'); });
					panels.forEach(function(p) { p.style.display = 'none'; });

					this.classList.add('button-primary');
					var activePanel = document.getElementById(targetId);
					if (activePanel) {
						activePanel.style.display = 'block';
					}
				});
			});
		});
		</script>
		<?php
	}

	/**
	 * Saves media translations submitted from the attachment edit screen.
	 *
	 * @param int $attachment_id Attachment post ID.
	 * @return void
	 */
	public function save_translations( int $attachment_id ): void {
		if ( $attachment_id <= 0 ) {
			return;
		}

		// Security: Nonce verification
		if ( ! isset( $_POST[ self::NONCE_NAME ] ) || ! function_exists( 'check_admin_referer' ) ) {
			return;
		}

		if ( ! check_admin_referer( self::NONCE_ACTION, self::NONCE_NAME ) ) {
			return;
		}

		// Security: Permission check
		if ( function_exists( 'current_user_can' ) && ! current_user_can( 'edit_post', $attachment_id ) ) {
			return;
		}

		if ( ! isset( $_POST['tfml_media'] ) || ! is_array( $_POST['tfml_media'] ) ) {
			return;
		}

		$submissions = $_POST['tfml_media'];
		foreach ( $submissions as $lang_code => $fields ) {
			if ( ! is_array( $fields ) || empty( $fields['active'] ) ) {
				continue;
			}

			$canonical_lang = Language::normalize_code( (string) $lang_code );
			if ( ! $this->language_registry->has( $canonical_lang ) || ! $this->language_registry->is_active( $canonical_lang ) ) {
				continue;
			}

			// Contextual sanitization (Section 26)
			$alt         = isset( $fields['alt_text'] ) ? sanitize_text_field( wp_unslash( (string) $fields['alt_text'] ) ) : '';
			$title       = isset( $fields['title'] ) ? sanitize_text_field( wp_unslash( (string) $fields['title'] ) ) : '';
			$caption     = isset( $fields['caption'] ) ? sanitize_textarea_field( wp_unslash( (string) $fields['caption'] ) ) : '';
			$description = isset( $fields['description'] ) ? wp_kses_post( wp_unslash( (string) $fields['description'] ) ) : '';

			$translation = MediaTranslation::create(
				$attachment_id,
				$canonical_lang,
				$alt,
				$title,
				$caption,
				$description
			);

			$this->repository->save( $translation );
		}

		$this->resolver->flush_cache();
	}
}
