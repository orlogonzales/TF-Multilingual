<?php
/**
 * Post Editorial UI Component.
 *
 * @package TF\Multilingual\Admin
 */

declare( strict_types=1 );

namespace TF\Multilingual\Admin;

use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Domain\Translation\TranslationStatus;
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
	 * Nonce action prefix for linking an existing translation.
	 */
	public const NONCE_ACTION_LINK = 'tfml_link_post_translation_';

	/**
	 * Nonce action prefix for unlinking an existing translation.
	 */
	public const NONCE_ACTION_UNLINK = 'tfml_unlink_post_translation_';

	/**
	 * Nonce action for editorial AJAX candidate search.
	 */
	public const NONCE_ACTION_AJAX = 'tfml_editorial_ajax';

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
		add_action( 'admin_post_tfml_link_post_translation', array( $this, 'handle_link_translation' ) );
		add_action( 'admin_post_tfml_unlink_post_translation', array( $this, 'handle_unlink_translation' ) );
		add_action( 'wp_ajax_tfml_search_candidates', array( $this, 'handle_ajax_search_candidates' ) );
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
		wp_nonce_field( self::NONCE_ACTION_AJAX, 'tfml_editorial_ajax_nonce', false );

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
	 * Renders UI for a managed post (showing current language, translations, and create/link tools).
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
					$status    = $tinfo['status'] ?? TranslationStatus::UPDATED;
					$is_review = ( TranslationStatus::REVIEW === $status );
					?>
					<li style="margin-bottom: 8px; display: flex; justify-content: space-between; align-items: center;">
						<span>
							<?php if ( $tinfo['is_current'] ) : ?>
								<strong><?php echo esc_html( $tinfo['language_name'] ); ?></strong>
								<?php if ( $is_review ) : ?>
									<span class="dashicons dashicons-warning" style="color: #dba617;" title="<?php esc_attr_e( 'Requiere revisión', 'tf-multilingual' ); ?>"></span>
								<?php else : ?>
									<span class="dashicons dashicons-yes" style="color: #46b450;" title="<?php esc_attr_e( 'Actualizada', 'tf-multilingual' ); ?>"></span>
								<?php endif; ?>
							<?php else : ?>
								<?php echo esc_html( $tinfo['language_name'] ); ?>
								<?php if ( $is_review ) : ?>
									<span class="dashicons dashicons-warning" style="color: #dba617;" title="<?php esc_attr_e( 'Requiere revisión', 'tf-multilingual' ); ?>"></span>
								<?php else : ?>
									<span class="dashicons dashicons-yes" style="color: #46b450;" title="<?php esc_attr_e( 'Actualizada', 'tf-multilingual' ); ?>"></span>
								<?php endif; ?>
							<?php endif; ?>
						</span>
						<span style="display: inline-flex; gap: 4px; align-items: center;">
							<?php if ( ! $tinfo['is_current'] && ! empty( $tinfo['edit_url'] ) ) : ?>
								<a href="<?php echo esc_url( $tinfo['edit_url'] ); ?>" class="button button-small">
									<?php esc_html_e( 'Edit', 'tf-multilingual' ); ?>
								</a>
								<?php
								$unlink_elem_id = $tinfo['element_id'] ?? 0;
								if ( $unlink_elem_id > 0 && function_exists( 'wp_nonce_url' ) ) :
									$unlink_url = wp_nonce_url(
										admin_url( 'admin-post.php?action=tfml_unlink_post_translation&element_id=' . $unlink_elem_id . '&source_post_id=' . $post->ID ),
										self::NONCE_ACTION_UNLINK . $unlink_elem_id,
										'tfml_unlink_nonce'
									);
									?>
									<a href="<?php echo esc_url( $unlink_url ); ?>"
										class="button button-small tfml-unlink-btn"
										style="color: #b32d2e; border-color: #b32d2e; padding: 0 4px;"
										onclick="return confirm('<?php echo esc_js( __( '¿Está seguro de que desea desvincular esta traducción? El contenido original no será eliminado.', 'tf-multilingual' ) ); ?>');"
										title="<?php esc_attr_e( 'Desvincular traducción', 'tf-multilingual' ); ?>">
										<span class="dashicons dashicons-editor-unlink" style="font-size: 14px; line-height: 20px; width: 14px; height: 14px; vertical-align: middle;"></span>
									</a>
								<?php endif; ?>
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
				<p><strong><?php esc_html_e( 'Traducciones Disponibles:', 'tf-multilingual' ); ?></strong></p>
				<div class="tfml-add-translations" style="display: flex; flex-direction: column; gap: 10px;">
					<?php foreach ( $missing as $code => $minfo ) : ?>
						<div class="tfml-missing-lang-card" style="border: 1px solid #c3c4c7; border-radius: 4px; padding: 10px; background: #fafafa;">
							<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
								<span style="font-weight: 600; font-size: 13px; color: #1d2327;">
									<?php echo esc_html( $minfo['language_name'] . ' (' . strtoupper( $code ) . ')' ); ?>
								</span>
								<span style="font-size: 11px; color: #646970; background: #e7e7e7; padding: 1px 6px; border-radius: 3px;">
									<?php esc_html_e( 'Sin vincular', 'tf-multilingual' ); ?>
								</span>
							</div>

							<!-- Action tabs toggle -->
							<div class="tfml-action-tabs" style="display: flex; gap: 6px; margin-bottom: 8px;">
								<button type="button" class="button button-small tfml-tab-btn tfml-tab-create active" data-target="tfml-create-panel-<?php echo esc_attr( $code ); ?>" style="font-size: 11px; font-weight: 600;">
									<span class="dashicons dashicons-plus-alt2" style="font-size: 13px; line-height: 20px; width: 13px; height: 13px; vertical-align: middle; margin-right: 2px;"></span>
									<?php esc_html_e( 'Crear nueva', 'tf-multilingual' ); ?>
								</button>
								<button type="button" class="button button-small tfml-tab-btn tfml-tab-link" data-target="tfml-link-panel-<?php echo esc_attr( $code ); ?>" style="font-size: 11px;">
									<span class="dashicons dashicons-admin-links" style="font-size: 13px; line-height: 20px; width: 13px; height: 13px; vertical-align: middle; margin-right: 2px;"></span>
									<?php esc_html_e( 'Vincular existente', 'tf-multilingual' ); ?>
								</button>
							</div>

							<!-- Panel 1: Create New Post -->
							<div id="tfml-create-panel-<?php echo esc_attr( $code ); ?>" class="tfml-panel-content tfml-panel-create">
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<input type="hidden" name="action" value="tfml_create_post_translation">
									<input type="hidden" name="source_post_id" value="<?php echo esc_attr( (string) $post->ID ); ?>">
									<input type="hidden" name="target_language" value="<?php echo esc_attr( $code ); ?>">
									<?php wp_nonce_field( self::NONCE_ACTION_CREATE . $post->ID, 'tfml_create_nonce' ); ?>
									<button type="submit" class="button button-secondary button-small" style="width: 100%; text-align: center;">
										<?php
										/* translators: %s: Language name */
										echo esc_html( sprintf( __( 'Crear borrador en %s', 'tf-multilingual' ), $minfo['language_name'] ) );
										?>
									</button>
								</form>
							</div>

							<!-- Panel 2: Link Existing Post -->
							<div id="tfml-link-panel-<?php echo esc_attr( $code ); ?>" class="tfml-panel-content tfml-panel-link" style="display: none; margin-top: 8px;">
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="tfml-link-form">
									<input type="hidden" name="action" value="tfml_link_post_translation">
									<input type="hidden" name="source_post_id" value="<?php echo esc_attr( (string) $post->ID ); ?>">
									<input type="hidden" name="target_language" value="<?php echo esc_attr( $code ); ?>">
									<?php wp_nonce_field( self::NONCE_ACTION_LINK . $post->ID, 'tfml_link_nonce' ); ?>

									<div style="margin-bottom: 6px;">
										<label for="tfml_search_<?php echo esc_attr( $code ); ?>" style="font-size: 11px; font-weight: 600; display: block; margin-bottom: 2px;">
											<?php esc_html_e( 'Buscar por título o ID:', 'tf-multilingual' ); ?>
										</label>
										<input type="text"
											id="tfml_search_<?php echo esc_attr( $code ); ?>"
											class="widefat tfml-candidate-search-input"
											data-lang="<?php echo esc_attr( $code ); ?>"
											data-subtype="<?php echo esc_attr( $post->post_type ); ?>"
											data-exclude="<?php echo esc_attr( (string) $post->ID ); ?>"
											placeholder="<?php esc_attr_e( 'Buscar por título o ID...', 'tf-multilingual' ); ?>"
											autocomplete="off"
											style="font-size: 12px;">
										<div class="tfml-search-spinner" style="display: none; font-size: 11px; color: #646970; margin-top: 3px;">
											<span class="spinner is-active" style="float: none; margin: 0 4px 0 0; vertical-align: middle;"></span>
											<?php esc_html_e( 'Buscando contenidos elegibles...', 'tf-multilingual' ); ?>
										</div>
										<ul class="tfml-candidates-dropdown" style="display: none; margin: 4px 0 0 0; padding: 0; list-style: none; max-height: 160px; overflow-y: auto; border: 1px solid #c3c4c7; background: #fff; border-radius: 3px; box-shadow: 0 2px 4px rgba(0,0,0,0.08);"></ul>
									</div>

									<div style="margin-bottom: 6px;">
										<label for="tfml_target_id_<?php echo esc_attr( $code ); ?>" style="font-size: 11px; color: #50575e; display: block; margin-bottom: 2px;">
											<?php esc_html_e( 'ID del contenido a vincular:', 'tf-multilingual' ); ?>
										</label>
										<input type="number"
											name="target_post_id"
											id="tfml_target_id_<?php echo esc_attr( $code ); ?>"
											class="widefat tfml-target-id-input"
											required
											min="1"
											placeholder="<?php esc_attr_e( 'ID (ej. 8354)', 'tf-multilingual' ); ?>"
											style="font-size: 12px; max-width: 120px;">
									</div>

									<!-- Candidate Preview Card -->
									<div class="tfml-candidate-preview-box" style="display: none; background: #f0f6fc; border: 1px solid #cce5ff; border-radius: 4px; padding: 8px; margin-bottom: 8px; font-size: 11px; line-height: 1.4;">
										<strong class="tfml-preview-title">...</strong><br>
										<span style="color: #50575e;">
											ID: #<span class="tfml-preview-id">...</span> |
											Slug: /<span class="tfml-preview-slug">...</span>/ |
											Estado: <span class="tfml-preview-status">...</span>
										</span>
									</div>

									<button type="submit" class="button button-secondary button-small tfml-link-submit-btn" style="width: 100%;" disabled>
										<span class="dashicons dashicons-admin-links" style="vertical-align: middle; margin-right: 4px;"></span>
										<?php
										/* translators: %s: Language name */
										echo esc_html( sprintf( __( 'Vincular como traducción en %s', 'tf-multilingual' ), $minfo['language_name'] ) );
										?>
									</button>
								</form>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<script>
			(function() {
				// 1. Tab switching between Create New and Link Existing
				document.querySelectorAll('.tfml-editorial-container .tfml-tab-btn').forEach(function(btn) {
					btn.addEventListener('click', function(e) {
						e.preventDefault();
						var card = this.closest('.tfml-missing-lang-card');
						if (!card) return;

						card.querySelectorAll('.tfml-tab-btn').forEach(function(b) {
							b.classList.remove('active');
							b.style.fontWeight = 'normal';
						});
						this.classList.add('active');
						this.style.fontWeight = '600';

						var targetId = this.getAttribute('data-target');
						card.querySelectorAll('.tfml-panel-content').forEach(function(p) {
							p.style.display = 'none';
						});
						var targetPanel = document.getElementById(targetId);
						if (targetPanel) {
							targetPanel.style.display = 'block';
						}
					});
				});

				// 2. Candidate live search with debounce
				var searchTimeout = null;
				document.querySelectorAll('.tfml-candidate-search-input').forEach(function(input) {
					var card = input.closest('.tfml-missing-lang-card');
					if (!card) return;

					var dropdown    = card.querySelector('.tfml-candidates-dropdown');
					var spinner     = card.querySelector('.tfml-search-spinner');
					var targetInput = card.querySelector('.tfml-target-id-input');
					var previewBox  = card.querySelector('.tfml-candidate-preview-box');
					var submitBtn   = card.querySelector('.tfml-link-submit-btn');

					targetInput.addEventListener('input', function() {
						if (this.value && parseInt(this.value, 10) > 0) {
							submitBtn.removeAttribute('disabled');
						} else {
							submitBtn.setAttribute('disabled', 'disabled');
						}
					});

					input.addEventListener('input', function() {
						var val = this.value.trim();
						clearTimeout(searchTimeout);

						if (val.length < 1) {
							if (dropdown) {
								dropdown.style.display = 'none';
								dropdown.innerHTML = '';
							}
							return;
						}

						if (spinner) spinner.style.display = 'block';
						if (dropdown) dropdown.style.display = 'none';

						searchTimeout = setTimeout(function() {
							var lang      = input.getAttribute('data-lang') || '';
							var subtype   = input.getAttribute('data-subtype') || 'post';
							var exclude   = input.getAttribute('data-exclude') || '0';
							var ajaxUrl   = (typeof ajaxurl !== 'undefined') ? ajaxurl : '/wp-admin/admin-ajax.php';
							var nonceEl   = document.getElementById('tfml_editorial_ajax_nonce');
							var nonce     = nonceEl ? nonceEl.value : '';

							var url = ajaxUrl + '?action=tfml_search_candidates&subtype=' + encodeURIComponent(subtype)
								+ '&target_language=' + encodeURIComponent(lang)
								+ '&exclude_id=' + encodeURIComponent(exclude)
								+ '&query=' + encodeURIComponent(val)
								+ '&nonce=' + encodeURIComponent(nonce);

							fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
								.then(function(res) { return res.json(); })
								.then(function(data) {
									if (spinner) spinner.style.display = 'none';
									var items = (data && data.success && data.data) ? data.data : (Array.isArray(data) ? data : []);
									if (!dropdown) return;
									dropdown.innerHTML = '';

									if (items.length === 0) {
										var li = document.createElement('li');
										li.style.padding = '8px';
										li.style.color = '#777';
										li.style.fontStyle = 'italic';
										li.textContent = 'No se encontraron candidatos elegibles.';
										dropdown.appendChild(li);
									} else {
										items.forEach(function(item) {
											var li = document.createElement('li');
											li.style.padding = '6px 8px';
											li.style.borderBottom = '1px solid #eee';
											li.style.cursor = 'pointer';
											li.style.fontSize = '12px';

											var titleSafe  = document.createTextNode(item.title);
											var slugSafe   = document.createTextNode(item.slug || '');
											var statusSafe = document.createTextNode(item.status || '');

											var strong = document.createElement('strong');
											strong.appendChild(titleSafe);
											li.appendChild(strong);

											var idSpan = document.createElement('span');
											idSpan.style.color = '#646970';
											idSpan.textContent = ' (#' + item.id + ')';
											li.appendChild(idSpan);

											li.appendChild(document.createElement('br'));

											var subSpan = document.createElement('span');
											subSpan.style.fontSize = '10px';
											subSpan.style.color = '#888';
											subSpan.textContent = 'Slug: /';
											subSpan.appendChild(slugSafe);
											subSpan.appendChild(document.createTextNode('/ ['));
											subSpan.appendChild(statusSafe);
											subSpan.appendChild(document.createTextNode(']'));
											li.appendChild(subSpan);

											li.addEventListener('mouseenter', function() { this.style.background = '#f0f6fc'; });
											li.addEventListener('mouseleave', function() { this.style.background = '#fff'; });

											li.addEventListener('click', function() {
												targetInput.value = item.id;
												input.value       = item.title;
												dropdown.style.display = 'none';

												if (previewBox) {
													var tEl = previewBox.querySelector('.tfml-preview-title');
													var iEl = previewBox.querySelector('.tfml-preview-id');
													var sEl = previewBox.querySelector('.tfml-preview-slug');
													var stEl = previewBox.querySelector('.tfml-preview-status');
													if (tEl) tEl.textContent = item.title;
													if (iEl) iEl.textContent = item.id;
													if (sEl) sEl.textContent = item.slug;
													if (stEl) stEl.textContent = item.status;
													previewBox.style.display = 'block';
												}

												if (submitBtn) {
													submitBtn.removeAttribute('disabled');
												}
											});

											dropdown.appendChild(li);
										});
									}
									dropdown.style.display = 'block';
								})
								.catch(function() {
									if (spinner) spinner.style.display = 'none';
								});
						}, 250);
					});
				});
			})();
			</script>
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
	 * Handles POST action to link an existing post as a translation.
	 *
	 * @return void
	 */
	public function handle_link_translation(): void {
		$source_id   = isset( $_POST['source_post_id'] ) ? absint( $_POST['source_post_id'] ) : 0;
		$target_id   = isset( $_POST['target_post_id'] ) ? absint( $_POST['target_post_id'] ) : 0;
		$target_lang = isset( $_POST['target_language'] ) ? sanitize_text_field( wp_unslash( $_POST['target_language'] ) ) : '';

		// Verify nonce.
		$nonce = isset( $_POST['tfml_link_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['tfml_link_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION_LINK . $source_id ) ) {
			wp_die( esc_html__( 'Security check failed. Please refresh and try again.', 'tf-multilingual' ), 403 );
		}

		// Verify capabilities.
		if ( ! current_user_can( 'edit_post', $source_id ) || ! current_user_can( 'edit_post', $target_id ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to link these posts.', 'tf-multilingual' ), 403 );
		}

		try {
			$this->editorial_service->link_translation( 'post', $source_id, $target_id, $target_lang );

			$redirect = add_query_arg(
				array(
					'post'         => $source_id,
					'action'       => 'edit',
					'tfml_success' => rawurlencode(
						sprintf(
							/* translators: %s: Target language code */
							__( 'Traducción (%s) vinculada exitosamente.', 'tf-multilingual' ),
							strtoupper( $target_lang )
						)
					),
				),
				admin_url( 'post.php' )
			);
			wp_safe_redirect( $redirect );
			exit;
		} catch ( \Throwable $e ) {
			$return_url = get_edit_post_link( $source_id, 'raw' );
			if ( null !== $return_url ) {
				$error_url = add_query_arg( 'tfml_error', rawurlencode( $e->getMessage() ), $return_url );
				wp_safe_redirect( $error_url );
				exit;
			}
			wp_die( esc_html( $e->getMessage() ), 400 );
		}
	}

	/**
	 * Handles action to unlink an element from its translation group.
	 *
	 * @return void
	 */
	public function handle_unlink_translation(): void {
		$element_id = isset( $_REQUEST['element_id'] ) ? absint( $_REQUEST['element_id'] ) : 0;
		$source_id  = isset( $_REQUEST['source_post_id'] ) ? absint( $_REQUEST['source_post_id'] ) : $element_id;

		// Verify nonce.
		$nonce = isset( $_REQUEST['tfml_unlink_nonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['tfml_unlink_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION_UNLINK . $element_id ) ) {
			wp_die( esc_html__( 'Security check failed. Please refresh and try again.', 'tf-multilingual' ), 403 );
		}

		// Verify capability.
		if ( ! current_user_can( 'edit_post', $element_id ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to unlink this post.', 'tf-multilingual' ), 403 );
		}

		try {
			$this->editorial_service->unlink_translation( 'post', $element_id );

			$return_url = get_edit_post_link( $source_id, 'raw' ) ?? admin_url( 'edit.php' );
			$redirect   = add_query_arg(
				array(
					'tfml_success' => rawurlencode( __( 'Traducción desvinculada exitosamente.', 'tf-multilingual' ) ),
				),
				$return_url
			);
			wp_safe_redirect( $redirect );
			exit;
		} catch ( \Throwable $e ) {
			$return_url = get_edit_post_link( $source_id, 'raw' );
			if ( null !== $return_url ) {
				$error_url = add_query_arg( 'tfml_error', rawurlencode( $e->getMessage() ), $return_url );
				wp_safe_redirect( $error_url );
				exit;
			}
			wp_die( esc_html( $e->getMessage() ), 400 );
		}
	}

	/**
	 * Handles AJAX request to search candidate elements for linking.
	 *
	 * @return void
	 */
	public function handle_ajax_search_candidates(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'No autorizado.', 'tf-multilingual' ) ), 403 );
		}

		$nonce = isset( $_REQUEST['nonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION_AJAX ) && ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			wp_send_json_error( array( 'message' => __( 'Verificación de seguridad fallida.', 'tf-multilingual' ) ), 403 );
		}

		$element_type    = isset( $_REQUEST['element_type'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['element_type'] ) ) : 'post';
		$subtype         = isset( $_REQUEST['subtype'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['subtype'] ) ) : 'post';
		$target_language = isset( $_REQUEST['target_language'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['target_language'] ) ) : '';
		$query           = isset( $_REQUEST['query'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['query'] ) ) : '';
		$exclude_id      = isset( $_REQUEST['exclude_id'] ) ? absint( $_REQUEST['exclude_id'] ) : 0;

		$candidates = $this->editorial_service->search_link_candidates(
			$element_type,
			$subtype,
			$target_language,
			$query,
			20,
			$exclude_id
		);

		wp_send_json_success( $candidates );
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

		if ( ! empty( $_GET['tfml_success'] ) ) {
			$message = sanitize_text_field( wp_unslash( $_GET['tfml_success'] ) );
			?>
			<div class="notice notice-success is-dismissible">
				<p><strong><?php esc_html_e( 'TF Multilingual:', 'tf-multilingual' ); ?></strong> <?php echo esc_html( $message ); ?></p>
			</div>
			<?php
		}
	}
}
