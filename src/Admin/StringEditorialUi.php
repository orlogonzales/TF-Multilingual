<?php
/**
 * String Editorial Admin UI Component.
 *
 * @package TF\Multilingual\Admin
 */

declare( strict_types=1 );

namespace TF\Multilingual\Admin;

use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Domain\Strings\StringRepository;
use TF\Multilingual\Domain\Strings\StringStatus;
use TF\Multilingual\Domain\Strings\TranslatableString;

/**
 * Class StringEditorialUi
 *
 * Provides a native WordPress administration UI under "TF Multilingual > Cadenas de texto"
 * for searching, filtering, registering, reviewing and translating interface strings.
 */
class StringEditorialUi {

	/**
	 * Menu page slug.
	 */
	public const PAGE_SLUG = 'tfml-strings';

	/**
	 * Top-level menu slug.
	 */
	public const PARENT_SLUG = 'tf-multilingual';

	/**
	 * Nonce action base for saving translations.
	 */
	public const NONCE_ACTION_SAVE = 'tfml_save_string_translation_action';

	/**
	 * Nonce action base for registering strings.
	 */
	public const NONCE_ACTION_REGISTER = 'tfml_register_string_action';

	/**
	 * Nonce action base for deleting strings.
	 */
	public const NONCE_ACTION_DELETE = 'tfml_delete_string_action';

	/**
	 * Nonce field name.
	 */
	public const NONCE_NAME = 'tfml_string_nonce';

	/**
	 * String repository.
	 *
	 * @var StringRepository
	 */
	private StringRepository $repository;

	/**
	 * Language registry.
	 *
	 * @var LanguageRegistry
	 */
	private LanguageRegistry $language_registry;

	/**
	 * Admin notice message.
	 *
	 * @var array{type: string, message: string}|null
	 */
	private ?array $notice = null;

	/**
	 * Constructor.
	 *
	 * @param StringRepository $repository        String repository.
	 * @param LanguageRegistry $language_registry Language registry.
	 */
	public function __construct( StringRepository $repository, LanguageRegistry $language_registry ) {
		$this->repository        = $repository;
		$this->language_registry = $language_registry;
	}

	/**
	 * Registers WordPress admin hooks.
	 *
	 * @return void
	 */
	public function init_hooks(): void {
		if ( function_exists( 'add_action' ) ) {
			add_action( 'admin_menu', array( $this, 'register_menu_page' ) );
			add_action( 'admin_init', array( $this, 'handle_form_submission' ) );
		}
	}

	/**
	 * Gets the required capability to manage strings.
	 *
	 * @return string
	 */
	public function get_capability(): string {
		$capability = 'manage_options';
		if ( function_exists( 'apply_filters' ) ) {
			return (string) apply_filters( 'tfml_manage_strings_capability', $capability );
		}

		return $capability;
	}

	/**
	 * Registers top-level and submenu pages.
	 *
	 * @return void
	 */
	public function register_menu_page(): void {
		if ( ! function_exists( 'add_menu_page' ) || ! function_exists( 'add_submenu_page' ) ) {
			return;
		}

		$cap = $this->get_capability();

		// Register parent menu if not already registered.
		global $admin_page_hooks;
		if ( ! isset( $admin_page_hooks[ self::PARENT_SLUG ] ) ) {
			add_menu_page(
				__( 'TF Multilingual', 'tf-multilingual' ),
				__( 'TF Multilingual', 'tf-multilingual' ),
				$cap,
				self::PARENT_SLUG,
				array( $this, 'render_page' ),
				'dashicons-translation',
				58
			);
		}

		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Cadenas de texto — TF Multilingual', 'tf-multilingual' ),
			__( 'Cadenas de texto', 'tf-multilingual' ),
			$cap,
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Handles form actions (save translation, register string, delete string).
	 *
	 * @return void
	 */
	public function handle_form_submission(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['page'] ) || self::PAGE_SLUG !== $_GET['page'] ) {
			return;
		}

		if ( function_exists( 'current_user_can' ) && ! current_user_can( $this->get_capability() ) ) {
			return;
		}

		// Handle Save Translation Action.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( isset( $_POST['tfml_action'] ) && 'save_translation' === $_POST['tfml_action'] ) {
			$string_id = isset( $_POST['string_id'] ) ? (int) $_POST['string_id'] : 0;
			if ( function_exists( 'check_admin_referer' ) ) {
				check_admin_referer( self::NONCE_ACTION_SAVE . '_' . $string_id, self::NONCE_NAME );
			}

			$lang_code = isset( $_POST['language_code'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['language_code'] ) ) : '';
			$trans_val = isset( $_POST['translated_value'] ) ? wp_unslash( (string) $_POST['translated_value'] ) : '';
			$status    = isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['status'] ) ) : StringStatus::DB_UP_TO_DATE;

			try {
				$this->repository->save_translation( $string_id, $lang_code, $trans_val, $status );
				$this->notice = array(
					'type'    => 'success',
					'message' => sprintf( 'Traducción para el idioma "%s" guardada correctamente.', esc_html( $lang_code ) ),
				);
			} catch ( \Throwable $e ) {
				$this->notice = array(
					'type'    => 'error',
					'message' => 'Error al guardar traducción: ' . esc_html( $e->getMessage() ),
				);
			}
			return;
		}

		// Handle Manual String Registration.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( isset( $_POST['tfml_action'] ) && 'register_string' === $_POST['tfml_action'] ) {
			if ( function_exists( 'check_admin_referer' ) ) {
				check_admin_referer( self::NONCE_ACTION_REGISTER, self::NONCE_NAME );
			}

			$domain      = isset( $_POST['domain'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['domain'] ) ) : '';
			$key         = isset( $_POST['string_key'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['string_key'] ) ) : '';
			$context     = isset( $_POST['context'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['context'] ) ) : '';
			$source_val  = isset( $_POST['original_value'] ) ? wp_unslash( (string) $_POST['original_value'] ) : '';
			$source_lang = isset( $_POST['source_language'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['source_language'] ) ) : 'es';

			try {
				$this->repository->register( $domain, $key, $source_val, $context, $source_lang );
				$this->notice = array(
					'type'    => 'success',
					'message' => sprintf( 'Cadena "%s" registrada exitosamente en el dominio "%s".', esc_html( $key ), esc_html( $domain ) ),
				);
			} catch ( \Throwable $e ) {
				$this->notice = array(
					'type'    => 'error',
					'message' => 'Error al registrar cadena: ' . esc_html( $e->getMessage() ),
				);
			}
			return;
		}

		// Handle String Delete Action.
		if ( isset( $_GET['action'], $_GET['string_id'] ) && 'delete' === $_GET['action'] ) {
			$string_id = (int) $_GET['string_id'];
			if ( function_exists( 'check_admin_referer' ) ) {
				check_admin_referer( self::NONCE_ACTION_DELETE . '_' . $string_id, self::NONCE_NAME );
			}

			$deleted = $this->repository->delete_string( $string_id );
			if ( $deleted ) {
				$this->notice = array(
					'type'    => 'success',
					'message' => 'Cadena eliminada exitosamente.',
				);
			} else {
				$this->notice = array(
					'type'    => 'error',
					'message' => 'No se pudo eliminar la cadena indicada.',
				);
			}
		}
	}

	/**
	 * Renders the Strings management admin page.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( $this->get_capability() ) ) {
			wp_die( esc_html__( 'No tienes permisos suficientes para acceder a esta página.', 'tf-multilingual' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$filter_domain = isset( $_GET['filter_domain'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['filter_domain'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$filter_search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['s'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current_page = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		$per_page     = 20;
		$offset       = ( $current_page - 1 ) * $per_page;

		$query_args = array(
			'domain'  => $filter_domain,
			'search'  => $filter_search,
			'limit'   => $per_page,
			'offset'  => $offset,
			'orderby' => 'id',
			'order'   => 'DESC',
		);

		$strings       = $this->repository->find_all( $query_args );
		$total_strings = $this->repository->count_all(
			array(
				'domain' => $filter_domain,
				'search' => $filter_search,
			)
		);
		$total_pages   = (int) ceil( $total_strings / $per_page );

		$active_languages = $this->language_registry->active();
		$default_lang     = $this->language_registry->get_default();
		$default_code     = null !== $default_lang ? $default_lang->get_code() : 'es';

		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Cadenas de texto — TF Multilingual', 'tf-multilingual' ); ?></h1>
			<hr class="wp-header-end" />

			<?php if ( null !== $this->notice ) : ?>
				<div class="notice notice-<?php echo esc_attr( $this->notice['type'] ); ?> is-dismissible">
					<p><?php echo esc_html( $this->notice['message'] ); ?></p>
				</div>
			<?php endif; ?>

			<!-- Add String Metabox -->
			<div class="postbox" style="margin-top: 15px;">
				<div class="postbox-header"><h2 class="hndle"><?php esc_html_e( 'Registrar Nueva Cadena de Texto', 'tf-multilingual' ); ?></h2></div>
				<div class="inside">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ); ?>">
						<?php wp_nonce_field( self::NONCE_ACTION_REGISTER, self::NONCE_NAME ); ?>
						<input type="hidden" name="tfml_action" value="register_string" />
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><label for="tfml_domain"><?php esc_html_e( 'Dominio', 'tf-multilingual' ); ?></label></th>
								<td>
									<input type="text" id="tfml_domain" name="domain" class="regular-text" required placeholder="ej. travel-flow, theme, default" />
									<p class="description"><?php esc_html_e( 'Espacio de nombres lógico para agrupar cadenas.', 'tf-multilingual' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="tfml_string_key"><?php esc_html_e( 'Clave Semántica', 'tf-multilingual' ); ?></label></th>
								<td>
									<input type="text" id="tfml_string_key" name="string_key" class="regular-text" required placeholder="ej. booking.button.confirm" />
									<p class="description"><?php esc_html_e( 'Identificador inmutable para la cadena (ADR-013).', 'tf-multilingual' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="tfml_context"><?php esc_html_e( 'Contexto (opcional)', 'tf-multilingual' ); ?></label></th>
								<td>
									<input type="text" id="tfml_context" name="context" class="regular-text" placeholder="ej. verb, noun, button" />
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="tfml_original_value"><?php esc_html_e( 'Texto Fuente', 'tf-multilingual' ); ?></label></th>
								<td>
									<textarea id="tfml_original_value" name="original_value" rows="3" class="large-text" required placeholder="Texto original en el idioma predeterminado"></textarea>
								</td>
							</tr>
						</table>
						<p class="submit">
							<input type="submit" class="button button-primary" value="<?php esc_attr_e( 'Registrar Cadena', 'tf-multilingual' ); ?>" />
						</p>
					</form>
				</div>
			</div>

			<!-- Filter Bar -->
			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="margin-bottom: 12px; display: flex; gap: 10px; align-items: center;">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
				<input type="text" name="filter_domain" value="<?php echo esc_attr( $filter_domain ); ?>" placeholder="<?php esc_attr_e( 'Filtrar por dominio...', 'tf-multilingual' ); ?>" class="regular-text" />
				<input type="search" name="s" value="<?php echo esc_attr( $filter_search ); ?>" placeholder="<?php esc_attr_e( 'Buscar clave o texto...', 'tf-multilingual' ); ?>" class="regular-text" />
				<input type="submit" class="button" value="<?php esc_attr_e( 'Filtrar', 'tf-multilingual' ); ?>" />
				<?php if ( '' !== $filter_domain || '' !== $filter_search ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ); ?>" class="button"><?php esc_html_e( 'Restablecer', 'tf-multilingual' ); ?></a>
				<?php endif; ?>
			</form>

			<!-- Strings List Table -->
			<table class="wp-list-table widefat fixed striped table-view-list">
				<thead>
					<tr>
						<th scope="col" style="width: 50px;">ID</th>
						<th scope="col" style="width: 120px;">Dominio</th>
						<th scope="col" style="width: 180px;">Clave</th>
						<th scope="col" style="width: 100px;">Contexto</th>
						<th scope="col">Texto Fuente (<?php echo esc_html( strtoupper( $default_code ) ); ?>)</th>
						<th scope="col" style="width: 60px;">Versión</th>
						<th scope="col" style="width: 70px;">Conflicto</th>
						<th scope="col" style="width: 120px;">Última Vista</th>
						<th scope="col">Traducciones</th>
						<th scope="col" style="width: 80px;">Acciones</th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $strings ) ) : ?>
						<tr>
							<td colspan="10"><?php esc_html_e( 'No se encontraron cadenas registradas.', 'tf-multilingual' ); ?></td>
						</tr>
					<?php else : ?>
						<?php foreach ( $strings as $string ) : ?>
							<?php
							$string_id    = $string->get_id();
							$translations = $this->repository->get_translations( $string_id );
							$delete_url   = wp_nonce_url(
								admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&action=delete&string_id=' . $string_id ),
								self::NONCE_ACTION_DELETE . '_' . $string_id,
								self::NONCE_NAME
							);
							?>
							<tr>
								<td><?php echo esc_html( (string) $string_id ); ?></td>
								<td><code><?php echo esc_html( $string->get_domain() ); ?></code></td>
								<td><strong><?php echo esc_html( $string->get_string_key() ); ?></strong></td>
								<td><?php echo esc_html( '' !== $string->get_context() ? $string->get_context() : '—' ); ?></td>
								<td><?php echo esc_html( $string->get_original_value() ); ?></td>
								<td><span class="badge">v<?php echo esc_html( (string) $string->get_string_version() ); ?></span></td>
								<td>
									<?php if ( $string->has_conflict() ) : ?>
										<span class="dashicons dashicons-warning" style="color: #d63638;" title="<?php esc_attr_e( 'Conflicto de registro detectado', 'tf-multilingual' ); ?>"></span>
									<?php else : ?>
										<span class="dashicons dashicons-yes" style="color: #46b450;"></span>
									<?php endif; ?>
								</td>
								<td><small><?php echo esc_html( '' !== $string->get_last_seen_at() ? $string->get_last_seen_at() : '—' ); ?></small></td>
								<td>
									<?php foreach ( $active_languages as $lang ) : ?>
										<?php
										$lang_code = $lang->get_code();
										if ( $lang_code === $default_code ) {
											continue;
										}
										$trans  = $translations[ $lang_code ] ?? null;
										$status = null !== $trans ? $trans->get_status() : StringStatus::DB_UNTRANSLATED;
										$val    = null !== $trans ? ( $trans->get_translated_value() ?? '' ) : '';
										?>
										<div style="margin-bottom: 8px; border-bottom: 1px solid #f0f0f1; padding-bottom: 6px;">
											<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ); ?>" style="display: flex; gap: 6px; align-items: center;">
												<?php wp_nonce_field( self::NONCE_ACTION_SAVE . '_' . $string_id, self::NONCE_NAME ); ?>
												<input type="hidden" name="tfml_action" value="save_translation" />
												<input type="hidden" name="string_id" value="<?php echo esc_attr( (string) $string_id ); ?>" />
												<input type="hidden" name="language_code" value="<?php echo esc_attr( $lang_code ); ?>" />
												
												<strong><?php echo esc_html( strtoupper( $lang_code ) ); ?>:</strong>
												
												<?php if ( StringStatus::DB_UP_TO_DATE === $status ) : ?>
													<span class="dashicons dashicons-yes-alt" style="color: #46b450;" title="UPDATED"></span>
												<?php elseif ( StringStatus::DB_NEEDS_REVIEW === $status ) : ?>
													<span class="dashicons dashicons-warning" style="color: #dba617;" title="REVIEW (Fuente actualizada)"></span>
												<?php else : ?>
													<span class="dashicons dashicons-plus-alt" style="color: #8c8f94;" title="UNTRANSLATED"></span>
												<?php endif; ?>

												<input type="text" name="translated_value" value="<?php echo esc_attr( $val ); ?>" class="regular-text" style="width: 200px;" placeholder="<?php esc_attr_e( 'Traducción...', 'tf-multilingual' ); ?>" />
												
												<select name="status" style="width: 110px;">
													<option value="up_to_date" <?php selected( $status, 'up_to_date' ); ?>>UPDATED</option>
													<option value="needs_review" <?php selected( $status, 'needs_review' ); ?>>REVIEW</option>
												</select>

												<input type="submit" class="button button-small" value="<?php esc_attr_e( 'Guardar', 'tf-multilingual' ); ?>" />
											</form>
										</div>
									<?php endforeach; ?>
								</td>
								<td>
									<a href="<?php echo esc_url( $delete_url ); ?>" class="button-link delete" onclick="return confirm('¿Eliminar esta cadena y todas sus traducciones?');" style="color: #b32d2e;">
										<?php esc_html_e( 'Eliminar', 'tf-multilingual' ); ?>
									</a>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<!-- Pagination -->
			<?php if ( $total_pages > 1 ) : ?>
				<div class="tablenav bottom">
					<div class="tablenav-pages">
						<span class="displaying-num"><?php echo esc_html( sprintf( '%d cadenas', $total_strings ) ); ?></span>
						<?php
						echo wp_kses_post(
							paginate_links(
								array(
									'base'      => add_query_arg( 'paged', '%#%' ),
									'format'    => '',
									'prev_text' => '&laquo;',
									'next_text' => '&raquo;',
									'total'     => $total_pages,
									'current'   => $current_page,
								)
							)
						);
						?>
					</div>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}
}
