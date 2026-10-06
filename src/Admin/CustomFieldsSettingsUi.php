<?php
/**
 * Custom Fields Settings UI Component.
 *
 * @package TF\Multilingual\Admin
 */

declare( strict_types=1 );

namespace TF\Multilingual\Admin;

use InvalidArgumentException;
use TF\Multilingual\Domain\CustomField\CustomFieldPolicy;
use TF\Multilingual\Domain\CustomField\CustomFieldPolicyRegistry;

/**
 * Class CustomFieldsSettingsUi
 *
 * Provides a minimal, native WordPress settings UI to configure custom field translation policies.
 */
class CustomFieldsSettingsUi {

	/**
	 * Page slug for the options screen.
	 */
	public const PAGE_SLUG = 'tfml-custom-fields';

	/**
	 * Nonce action for policy management.
	 */
	public const NONCE_ACTION = 'tfml_custom_field_policies_action';

	/**
	 * Nonce field name.
	 */
	public const NONCE_NAME = 'tfml_cf_nonce';

	/**
	 * Custom field policy registry.
	 *
	 * @var CustomFieldPolicyRegistry
	 */
	private CustomFieldPolicyRegistry $registry;

	/**
	 * Notice message to display.
	 *
	 * @var array{type: string, message: string}|null
	 */
	private ?array $notice = null;

	/**
	 * Constructor.
	 *
	 * @param CustomFieldPolicyRegistry $registry Policy registry.
	 */
	public function __construct( CustomFieldPolicyRegistry $registry ) {
		$this->registry = $registry;
	}

	/**
	 * Registers WordPress admin hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		if ( function_exists( 'add_action' ) ) {
			add_action( 'admin_menu', array( $this, 'register_menu_page' ) );
			add_action( 'admin_init', array( $this, 'handle_form_submission' ) );
		}
	}

	/**
	 * Registers the options page under Settings.
	 *
	 * @return void
	 */
	public function register_menu_page(): void {
		if ( function_exists( 'add_options_page' ) ) {
			add_options_page(
				__( 'TF Multilingual — Custom Fields', 'tf-multilingual' ),
				__( 'TF Custom Fields', 'tf-multilingual' ),
				'manage_options',
				self::PAGE_SLUG,
				array( $this, 'render_page' )
			);
		}
	}

	/**
	 * Handles form submissions for adding, updating, or deleting a policy.
	 *
	 * @return void
	 */
	public function handle_form_submission(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['page'] ) || self::PAGE_SLUG !== $_GET['page'] ) {
			return;
		}

		if ( function_exists( 'current_user_can' ) && ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Handle Delete Action.
		if ( isset( $_GET['action'], $_GET['meta_key'] ) && 'delete' === $_GET['action'] ) {
			$raw_key = sanitize_text_field( wp_unslash( (string) $_GET['meta_key'] ) );
			if ( function_exists( 'check_admin_referer' ) ) {
				check_admin_referer( self::NONCE_ACTION . '_delete_' . $raw_key, self::NONCE_NAME );
			}

			$this->registry->remove_policy( $raw_key );
			if ( ! str_starts_with( $raw_key, '_' ) ) {
				$this->registry->remove_policy( '_' . $raw_key );
			}
			$this->registry->persist();

			$this->notice = array(
				'type'    => 'success',
				/* translators: %s: Meta key name. */
				'message' => sprintf( __( 'Política para "%s" eliminada. Aplicará la política por defecto (Ignorar).', 'tf-multilingual' ), $raw_key ),
			);
			return;
		}

		// Handle Save / Update Form Submission.
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && isset( $_POST['tfml_action'] ) && 'save_policy' === $_POST['tfml_action'] ) {
			if ( function_exists( 'check_admin_referer' ) ) {
				check_admin_referer( self::NONCE_ACTION, self::NONCE_NAME );
			}

			$meta_key = isset( $_POST['meta_key'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['meta_key'] ) ) : '';
			$policy   = isset( $_POST['policy'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['policy'] ) ) : '';

			if ( '' === trim( $meta_key ) ) {
				$this->notice = array(
					'type'    => 'error',
					'message' => __( 'La clave del campo (meta_key) no puede estar vacía.', 'tf-multilingual' ),
				);
				return;
			}

			try {
				$this->registry->set_policy( $meta_key, $policy );
				if ( ! str_starts_with( $meta_key, '_' ) ) {
					if ( CustomFieldPolicy::SHARE === $policy ) {
						$this->registry->set_policy( '_' . $meta_key, CustomFieldPolicy::SHARE );
					} else {
						$this->registry->remove_policy( '_' . $meta_key );
					}
				}
				$this->registry->persist();

				$this->notice = array(
					'type'    => 'success',
					/* translators: %s: Meta key name. */
					'message' => sprintf( __( 'Política para "%s" guardada correctamente.', 'tf-multilingual' ), $meta_key ),
				);
			} catch ( InvalidArgumentException $e ) {
				$this->notice = array(
					'type'    => 'error',
					'message' => $e->getMessage(),
				);
			}
		}
	}

	/**
	 * Renders the custom field policies settings page.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( function_exists( 'current_user_can' ) && ! current_user_can( 'manage_options' ) ) {
			if ( function_exists( 'wp_die' ) ) {
				wp_die( esc_html__( 'No tienes permisos suficientes para acceder a esta página.', 'tf-multilingual' ) );
			}
			return;
		}

		$policies         = $this->registry->get_all_policies();
		$display_policies = array();
		foreach ( $policies as $key => $pol ) {
			// Encapsulate ACF internal reference keys (e.g. _field_name).
			if ( str_starts_with( $key, '_' ) && isset( $policies[ substr( $key, 1 ) ] ) ) {
				continue;
			}
			$display_policies[ $key ] = $pol;
		}

		$policy_names = array(
			CustomFieldPolicy::TRANSLATE => __( 'Traducir (independiente)', 'tf-multilingual' ),
			CustomFieldPolicy::SHARE     => __( 'Compartir (sincronizar)', 'tf-multilingual' ),
			CustomFieldPolicy::IGNORE    => __( 'Ignorar (sin gestión)', 'tf-multilingual' ),
		);

		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'TF Multilingual — Políticas de Custom Fields', 'tf-multilingual' ); ?></h1>

			<?php if ( null !== $this->notice ) : ?>
				<div class="notice notice-<?php echo esc_attr( $this->notice['type'] ); ?> is-dismissible">
					<p><?php echo esc_html( $this->notice['message'] ); ?></p>
				</div>
			<?php endif; ?>

			<div class="notice notice-info">
				<p>
					<strong><?php echo esc_html__( 'Regla Soberana de Adopción Progresiva:', 'tf-multilingual' ); ?></strong>
					<?php echo esc_html__( 'Cualquier clave no configurada en este listado aplica estrictamente la política por defecto: Ignorar (no se sincroniza ni se clona en traducciones nuevas).', 'tf-multilingual' ); ?>
				</p>
			</div>

			<h2><?php echo esc_html__( 'Añadir o Actualizar Política de Custom Field', 'tf-multilingual' ); ?></h2>
			<form method="post" action="">
				<?php
				if ( function_exists( 'wp_nonce_field' ) ) {
					wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
				}
				?>
				<input type="hidden" name="tfml_action" value="save_policy" />

				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row">
								<label for="tfml_meta_key"><?php echo esc_html__( 'Meta Key (Nombre del campo)', 'tf-multilingual' ); ?></label>
							</th>
							<td>
								<input name="meta_key" type="text" id="tfml_meta_key" value="" class="regular-text" placeholder="ej. _precio, tour_duration" required />
								<p class="description"><?php echo esc_html__( 'El nombre exacto de la clave en wp_postmeta.', 'tf-multilingual' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="tfml_policy"><?php echo esc_html__( 'Política', 'tf-multilingual' ); ?></label>
							</th>
							<td>
								<select name="policy" id="tfml_policy">
									<option value="<?php echo esc_attr( CustomFieldPolicy::TRANSLATE ); ?>"><?php echo esc_html( $policy_names[ CustomFieldPolicy::TRANSLATE ] ); ?></option>
									<option value="<?php echo esc_attr( CustomFieldPolicy::SHARE ); ?>"><?php echo esc_html( $policy_names[ CustomFieldPolicy::SHARE ] ); ?></option>
									<option value="<?php echo esc_attr( CustomFieldPolicy::IGNORE ); ?>"><?php echo esc_html( $policy_names[ CustomFieldPolicy::IGNORE ] ); ?></option>
								</select>
							</td>
						</tr>
					</tbody>
				</table>

				<p class="submit">
					<input type="submit" name="submit" id="submit" class="button button-primary" value="<?php echo esc_attr__( 'Guardar Política', 'tf-multilingual' ); ?>" />
				</p>
			</form>

			<hr />

			<h2><?php echo esc_html__( 'Políticas Configuradas', 'tf-multilingual' ); ?></h2>
			<table class="widefat fixed striped">
				<thead>
					<tr>
						<th scope="col" style="width: 40%;"><?php echo esc_html__( 'Meta Key', 'tf-multilingual' ); ?></th>
						<th scope="col" style="width: 40%;"><?php echo esc_html__( 'Política', 'tf-multilingual' ); ?></th>
						<th scope="col" style="width: 20%;"><?php echo esc_html__( 'Acciones', 'tf-multilingual' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $display_policies ) ) : ?>
						<tr>
							<td colspan="3"><?php echo esc_html__( 'No hay campos personalizados configurados explícitamente. Todos aplican la política por defecto: Ignorar.', 'tf-multilingual' ); ?></td>
						</tr>
					<?php else : ?>
						<?php foreach ( $display_policies as $key => $pol ) : ?>
							<tr>
								<td><code><?php echo esc_html( $key ); ?></code></td>
								<td>
									<strong><?php echo esc_html( $policy_names[ $pol ] ?? $pol ); ?></strong>
								</td>
								<td>
									<?php
									$del_nonce = function_exists( 'wp_create_nonce' ) ? wp_create_nonce( self::NONCE_ACTION . '_delete_' . $key ) : '';
									$del_url   = add_query_arg(
										array(
											'page'     => self::PAGE_SLUG,
											'action'   => 'delete',
											'meta_key' => rawurlencode( $key ),
											self::NONCE_NAME => $del_nonce,
										),
										admin_url( 'options-general.php' )
									);
									?>
									<a href="<?php echo esc_url( $del_url ); ?>" class="button button-link-delete" onclick="return confirm('<?php echo esc_js( __( '¿Eliminar política para esta clave?', 'tf-multilingual' ) ); ?>');">
										<?php echo esc_html__( 'Eliminar', 'tf-multilingual' ); ?>
									</a>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
