<?php
/**
 * Language Editorial Admin UI Component.
 *
 * @package TF\Multilingual\Admin
 */

declare( strict_types=1 );

namespace TF\Multilingual\Admin;

use TF\Multilingual\Domain\Language\Exceptions\DefaultLanguageException;
use TF\Multilingual\Domain\Language\Exceptions\DuplicateLanguageException;
use TF\Multilingual\Domain\Language\Exceptions\InvalidLanguageException;
use TF\Multilingual\Domain\Language\Exceptions\LanguageNotFoundException;
use TF\Multilingual\Domain\Language\Language;
use TF\Multilingual\Domain\Language\LanguageFlagRenderer;
use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Migration\RetainedTranslationRegistry;
use Throwable;

/**
 * Class LanguageEditorialUi
 *
 * Provides a sovereign WordPress administration UI under "TF Multilingual > Idiomas"
 * for managing configured languages, active states, and designating the sovereign default language.
 */
class LanguageEditorialUi {

	/**
	 * Top-level menu page slug.
	 */
	public const PARENT_SLUG = 'tf-multilingual';

	/**
	 * Menu page slug.
	 */
	public const PAGE_SLUG = 'tf-multilingual';

	/**
	 * Submenu alias slug for direct linking.
	 */
	public const ALIAS_SLUG = 'tfml-languages';

	/**
	 * Nonce action for adding a new language.
	 */
	public const NONCE_ACTION_ADD = 'tfml_add_language_action';

	/**
	 * Nonce action for toggling active state.
	 */
	public const NONCE_ACTION_TOGGLE = 'tfml_toggle_language_action';

	/**
	 * Nonce action for designating default language.
	 */
	public const NONCE_ACTION_DEFAULT = 'tfml_set_default_language_action';

	/**
	 * Nonce action for deleting a language.
	 */
	public const NONCE_ACTION_DELETE = 'tfml_delete_language_action';

	/**
	 * Nonce action for updating language properties.
	 */
	public const NONCE_ACTION_UPDATE = 'tfml_update_language_action';

	/**
	 * Nonce field name.
	 */
	public const NONCE_NAME = 'tfml_language_nonce';

	/**
	 * Language registry domain service.
	 *
	 * @var LanguageRegistry
	 */
	private LanguageRegistry $registry;

	/**
	 * Optional retained translations registry.
	 *
	 * @var RetainedTranslationRegistry|null
	 */
	private ?RetainedTranslationRegistry $retained_registry;

	/**
	 * Admin notice message.
	 *
	 * @var array{type: string, message: string}|null
	 */
	private ?array $notice = null;

	/**
	 * Constructor.
	 *
	 * @param LanguageRegistry                 $registry          Language registry service.
	 * @param RetainedTranslationRegistry|null $retained_registry Optional retained translations registry.
	 */
	public function __construct(
		LanguageRegistry $registry,
		?RetainedTranslationRegistry $retained_registry = null
	) {
		$this->registry          = $registry;
		$this->retained_registry = $retained_registry;
	}

	/**
	 * Registers WordPress admin hooks.
	 *
	 * @return void
	 */
	public function init_hooks(): void {
		if ( function_exists( 'add_action' ) ) {
			add_action( 'admin_menu', array( $this, 'register_menu_page' ), 9 );
			add_action( 'admin_init', array( $this, 'handle_form_submission' ) );
		}
	}

	/**
	 * Gets the required capability to manage languages.
	 *
	 * @return string
	 */
	public function get_capability(): string {
		$capability = 'manage_options';
		if ( function_exists( 'apply_filters' ) ) {
			return (string) apply_filters( 'tfml_manage_languages_capability', $capability );
		}

		return $capability;
	}

	/**
	 * Registers top-level and submenu pages in WordPress admin.
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

			// Customize first submenu item to display as "Idiomas" instead of duplicating "TF Multilingual".
			add_submenu_page(
				self::PARENT_SLUG,
				__( 'Idiomas — TF Multilingual', 'tf-multilingual' ),
				__( 'Idiomas', 'tf-multilingual' ),
				$cap,
				self::PARENT_SLUG,
				array( $this, 'render_page' )
			);

			// Also register the explicit alias slug "tfml-languages".
			add_submenu_page(
				null,
				__( 'Idiomas — TF Multilingual', 'tf-multilingual' ),
				__( 'Idiomas', 'tf-multilingual' ),
				$cap,
				self::ALIAS_SLUG,
				array( $this, 'render_page' )
			);
		} else {
			// Parent menu already registered (e.g. by ControlCenterUi); register visible submenu under parent.
			add_submenu_page(
				self::PARENT_SLUG,
				__( 'Idiomas — TF Multilingual', 'tf-multilingual' ),
				__( 'Idiomas', 'tf-multilingual' ),
				$cap,
				self::ALIAS_SLUG,
				array( $this, 'render_page' )
			);
		}
	}

	/**
	 * Handles form actions and security validations.
	 *
	 * @return void
	 */
	public function handle_form_submission(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['page'] ) ) : '';
		if ( self::PARENT_SLUG !== $page && self::ALIAS_SLUG !== $page ) {
			return;
		}

		if ( function_exists( 'current_user_can' ) && ! current_user_can( $this->get_capability() ) ) {
			return;
		}

		// Determine action from POST or GET.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended
		$action = isset( $_POST['tfml_action'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['tfml_action'] ) ) : ( isset( $_GET['tfml_action'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['tfml_action'] ) ) : '' );

		if ( '' === $action ) {
			return;
		}

		switch ( $action ) {
			case 'add_language':
				$this->process_add_language();
				break;

			case 'toggle_active':
				$this->process_toggle_active();
				break;

			case 'set_default':
				$this->process_set_default();
				break;

			case 'delete_language':
				$this->process_delete_language();
				break;

			case 'update_language':
				$this->process_update_language();
				break;
		}
	}

	/**
	 * Processes adding a new language to the catalog.
	 *
	 * @return void
	 */
	private function process_add_language(): void {
		if ( function_exists( 'check_admin_referer' ) ) {
			check_admin_referer( self::NONCE_ACTION_ADD, self::NONCE_NAME );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$code = isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['code'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['name'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$native_name = isset( $_POST['native_name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['native_name'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$locale = isset( $_POST['locale'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['locale'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$order = isset( $_POST['order'] ) ? (int) $_POST['order'] : 10;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$active = ! empty( $_POST['active'] );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$is_default = ! empty( $_POST['is_default'] );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$flag_code = isset( $_POST['flag_code'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['flag_code'] ) ) : null;

		if ( '' === $native_name ) {
			$native_name = $name;
		}

		try {
			$language = Language::create( $code, $locale, $name, $native_name, $active, $order, $flag_code );
			$this->registry->add_language( $language, $is_default );
			$this->registry->persist();

			$msg = sprintf(
				/* translators: 1: language name, 2: language code */
				__( 'Idioma "%1$s" (%2$s) añadido correctamente al catálogo.', 'tf-multilingual' ),
				$name,
				$language->get_code()
			);

			// Check for retained translations.
			if ( null !== $this->retained_registry ) {
				$retained = $this->retained_registry->get_retained_for_language( $language->get_code() );
				if ( ! empty( $retained ) ) {
					$msg .= ' ' . sprintf(
						/* translators: %d: number of retained translations */
						__( 'Se detectaron %d traducciones retenidas para este idioma listas para sincronización futura.', 'tf-multilingual' ),
						count( $retained )
					);
				}
			}

			$this->notice = array(
				'type'    => 'success',
				'message' => $msg,
			);
		} catch ( DuplicateLanguageException $e ) {
			$this->notice = array(
				'type'    => 'error',
				/* translators: %s: language code */
				'message' => sprintf( __( 'El código de idioma "%s" ya se encuentra registrado en el sistema.', 'tf-multilingual' ), esc_html( $code ) ),
			);
		} catch ( DefaultLanguageException $e ) {
			$this->notice = array(
				'type'    => 'error',
				'message' => $e->getMessage(),
			);
		} catch ( InvalidLanguageException $e ) {
			$this->notice = array(
				'type'    => 'error',
				/* translators: %s: error message */
				'message' => sprintf( __( 'Datos de idioma inválidos: %s', 'tf-multilingual' ), $e->getMessage() ),
			);
		} catch ( Throwable $e ) {
			$this->notice = array(
				'type'    => 'error',
				/* translators: %s: error message */
				'message' => sprintf( __( 'Error al guardar el idioma: %s', 'tf-multilingual' ), $e->getMessage() ),
			);
		}
	}

	/**
	 * Processes toggling the active state of a language.
	 *
	 * @return void
	 */
	private function process_toggle_active(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['code'] ) ) : '';
		if ( '' === $code ) {
			return;
		}

		if ( function_exists( 'check_admin_referer' ) ) {
			check_admin_referer( self::NONCE_ACTION_TOGGLE . '_' . $code, self::NONCE_NAME );
		}

		$current = $this->registry->get( $code );
		if ( null === $current ) {
			$this->notice = array(
				'type'    => 'error',
				/* translators: %s: language code */
				'message' => sprintf( __( 'Idioma "%s" no encontrado.', 'tf-multilingual' ), esc_html( $code ) ),
			);
			return;
		}

		try {
			if ( $current->is_active() ) {
				$this->registry->deactivate( $code );
				/* translators: %s: language code */
				$msg = sprintf( __( 'Idioma "%s" desactivado correctamente.', 'tf-multilingual' ), esc_html( $code ) );
			} else {
				$this->registry->activate( $code );
				/* translators: %s: language code */
				$msg = sprintf( __( 'Idioma "%s" activado correctamente.', 'tf-multilingual' ), esc_html( $code ) );
			}

			$this->registry->persist();

			$this->notice = array(
				'type'    => 'success',
				'message' => $msg,
			);
		} catch ( DefaultLanguageException $e ) {
			$this->notice = array(
				'type'    => 'error',
				'message' => __( 'Invariante de seguridad: No se puede desactivar el idioma predeterminado soberano.', 'tf-multilingual' ),
			);
		} catch ( Throwable $e ) {
			$this->notice = array(
				'type'    => 'error',
				'message' => $e->getMessage(),
			);
		}
	}

	/**
	 * Processes designating a language as sovereign default.
	 *
	 * @return void
	 */
	private function process_set_default(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['code'] ) ) : '';
		if ( '' === $code ) {
			return;
		}

		if ( function_exists( 'check_admin_referer' ) ) {
			check_admin_referer( self::NONCE_ACTION_DEFAULT . '_' . $code, self::NONCE_NAME );
		}

		try {
			$this->registry->set_default( $code );
			$this->registry->persist();

			$this->notice = array(
				'type'    => 'success',
				'message' => sprintf(
					/* translators: %s: language code */
					__( 'El idioma "%s" ha sido establecido como el idioma predeterminado soberano.', 'tf-multilingual' ),
					esc_html( strtoupper( $code ) )
				),
			);
		} catch ( DefaultLanguageException $e ) {
			$this->notice = array(
				'type'    => 'error',
				'message' => $e->getMessage(),
			);
		} catch ( LanguageNotFoundException $e ) {
			$this->notice = array(
				'type'    => 'error',
				/* translators: %s: language code */
				'message' => sprintf( __( 'Idioma "%s" no encontrado.', 'tf-multilingual' ), esc_html( $code ) ),
			);
		} catch ( Throwable $e ) {
			$this->notice = array(
				'type'    => 'error',
				'message' => $e->getMessage(),
			);
		}
	}

	/**
	 * Processes deleting a language from the catalog.
	 *
	 * @return void
	 */
	private function process_delete_language(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['code'] ) ) : '';
		if ( '' === $code ) {
			return;
		}

		if ( function_exists( 'check_admin_referer' ) ) {
			check_admin_referer( self::NONCE_ACTION_DELETE . '_' . $code, self::NONCE_NAME );
		}

		try {
			$this->registry->remove_language( $code );
			$this->registry->persist();

			$this->notice = array(
				'type'    => 'success',
				/* translators: %s: language code */
				'message' => sprintf( __( 'Idioma "%s" eliminado correctamente del catálogo.', 'tf-multilingual' ), esc_html( $code ) ),
			);
		} catch ( DefaultLanguageException $e ) {
			$this->notice = array(
				'type'    => 'error',
				'message' => __( 'Invariante de seguridad: No se puede eliminar el idioma predeterminado soberano.', 'tf-multilingual' ),
			);
		} catch ( Throwable $e ) {
			$this->notice = array(
				'type'    => 'error',
				'message' => $e->getMessage(),
			);
		}
	}

	/**
	 * Processes updating an existing language's properties.
	 *
	 * @return void
	 */
	private function process_update_language(): void {
		if ( function_exists( 'check_admin_referer' ) ) {
			check_admin_referer( self::NONCE_ACTION_UPDATE, self::NONCE_NAME );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$code = isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['code'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['name'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$native_name = isset( $_POST['native_name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['native_name'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$locale = isset( $_POST['locale'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['locale'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$order = isset( $_POST['order'] ) ? (int) $_POST['order'] : 10;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$flag_code = isset( $_POST['flag_code'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['flag_code'] ) ) : null;

		try {
			$this->registry->update_language(
				$code,
				array(
					'name'        => $name,
					'native_name' => $native_name,
					'locale'      => $locale,
					'order'       => $order,
					'flag_code'   => $flag_code,
				)
			);
			$this->registry->persist();

			$this->notice = array(
				'type'    => 'success',
				/* translators: %s: language code */
				'message' => sprintf( __( 'Propiedades del idioma "%s" actualizadas correctamente.', 'tf-multilingual' ), esc_html( $code ) ),
			);
		} catch ( Throwable $e ) {
			$this->notice = array(
				'type'    => 'error',
				'message' => $e->getMessage(),
			);
		}
	}

	/**
	 * Returns standard predefined languages for user convenience.
	 *
	 * @return array<string, array{name: string, native_name: string, locale: string}>
	 */
	public function get_predefined_languages(): array {
		return array(
			'es'    => array(
				'name'        => 'Spanish',
				'native_name' => 'Español',
				'locale'      => 'es_ES',
				'flag'        => 'es',
			),
			'en'    => array(
				'name'        => 'English',
				'native_name' => 'English',
				'locale'      => 'en_US',
				'flag'        => 'us',
			),
			'pt'    => array(
				'name'        => 'Portuguese',
				'native_name' => 'Português',
				'locale'      => 'pt_PT',
				'flag'        => 'pt',
			),
			'pt-br' => array(
				'name'        => 'Portuguese (Brazil)',
				'native_name' => 'Português do Brasil',
				'locale'      => 'pt_BR',
				'flag'        => 'br',
			),
			'fr'    => array(
				'name'        => 'French',
				'native_name' => 'Français',
				'locale'      => 'fr_FR',
				'flag'        => 'fr',
			),
			'de'    => array(
				'name'        => 'German',
				'native_name' => 'Deutsch',
				'locale'      => 'de_DE',
			),
			'it'    => array(
				'name'        => 'Italian',
				'native_name' => 'Italiano',
				'locale'      => 'it_IT',
			),
			'nl'    => array(
				'name'        => 'Dutch',
				'native_name' => 'Nederlands',
				'locale'      => 'nl_NL',
			),
			'ru'    => array(
				'name'        => 'Russian',
				'native_name' => 'Русский',
				'locale'      => 'ru_RU',
			),
			'zh-cn' => array(
				'name'        => 'Chinese (Simplified)',
				'native_name' => '简体中文',
				'locale'      => 'zh_CN',
			),
			'ja'    => array(
				'name'        => 'Japanese',
				'native_name' => '日本語',
				'locale'      => 'ja',
			),
		);
	}

	/**
	 * Renders an HTML flag or fallback badge for a language code.
	 *
	 * @param string      $code      Language code.
	 * @param string      $name      Language label.
	 * @param string|null $flag_code Optional visual flag override.
	 * @return string HTML representation.
	 */
	public function get_flag_html( string $code, string $name, ?string $flag_code = null ): string {
		return LanguageFlagRenderer::render( $code, $name, 28, 20, $flag_code );
	}

	/**
	 * Renders the main Languages administration page.
	 *
	 * @return void
	 */
	public function render_page(): void {
		$all_languages = $this->registry->all();
		$default_code  = $this->registry->get_default_code();
		$predefined    = $this->get_predefined_languages();

		// Check for edit mode.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$edit_code = isset( $_GET['edit'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['edit'] ) ) : '';
		$edit_lang = '' !== $edit_code ? $this->registry->get( $edit_code ) : null;

		// Retained translations audit.
		$retained_summary = array();
		if ( null !== $this->retained_registry ) {
			$all_retained = $this->retained_registry->get_all();
			foreach ( $all_retained as $rec ) {
				$elems = (array) ( $rec['retained_elements'] ?? array() );
				foreach ( $elems as $l => $item ) {
					$l_clean                      = strtolower( (string) $l );
					$retained_summary[ $l_clean ] = ( $retained_summary[ $l_clean ] ?? 0 ) + 1;
				}
			}
		}

		?>
		<div class="wrap tfml-spatial-wrap tfml-languages-wrap">
			<!-- Spatial Header -->
			<div class="tfml-spatial-header">
				<div class="tfml-header-left">
					<div class="tfml-header-icon" aria-hidden="true">
						<svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 17.93c-3.95-.49-7-3.85-7-7.93 0-.62.08-1.21.21-1.79L9 15v1c0 1.1.9 2 2 2v1.93zm6.9-2.54c-.26-.81-1-1.39-1.9-1.39h-1v-3c0-.55-.45-1-1-1H8v-2h2c.55 0 1-.45 1-1V7h2c1.1 0 2-.9 2-2v-.41c2.93 1.19 5 4.06 5 7.41 0 2.08-.8 3.97-2.1 5.39z"/></svg>
					</div>
					<div>
						<h1 class="tfml-header-title"><?php esc_html_e( 'Idiomas — TF Multilingual', 'tf-multilingual' ); ?></h1>
						<p class="tfml-header-subtitle"><?php esc_html_e( 'Configuración, activación y catálogo de idiomas soberanos de EcoTerra.', 'tf-multilingual' ); ?></p>
					</div>
				</div>
				<div class="tfml-header-actions">
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=tf-multilingual' ) ); ?>" class="tfml-btn tfml-btn-outline tfml-btn-sm">
						<span class="dashicons dashicons-dashboard" style="font-size:16px;line-height:1.2;"></span>
						<?php esc_html_e( 'Control Center', 'tf-multilingual' ); ?>
					</a>
				</div>
			</div>

			<?php if ( null !== $this->notice ) : ?>
				<div class="notice notice-<?php echo esc_attr( $this->notice['type'] ); ?> is-dismissible" style="margin-top:15px;">
					<p><?php echo esc_html( $this->notice['message'] ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( ! empty( $retained_summary ) ) : ?>
				<div class="notice notice-info" style="margin-top:15px;">
					<p>
						<strong><?php esc_html_e( 'Traducciones Retenidas Detectadas:', 'tf-multilingual' ); ?></strong>
						<?php
						$info_parts = array();
						foreach ( $retained_summary as $lang_code => $count ) {
							$info_parts[] = sprintf(
								'<strong>%s</strong> (%d %s)',
								esc_html( strtoupper( $lang_code ) ),
								(int) $count,
								_n( 'elemento', 'elementos', (int) $count, 'tf-multilingual' )
							);
						}
						echo implode( ', ', $info_parts ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						?>
						— <em><?php esc_html_e( 'Contenido protegido en el registro de migración sin interferencia en las tablas activas. Si activa o añade estos idiomas, las traducciones podrán ser promovidas.', 'tf-multilingual' ); ?></em>
					</p>
				</div>
			<?php endif; ?>

			<div id="col-container" class="wp-clearfix tfml-languages-layout">

				<!-- Columna Izquierda: Formulario Agregar / Editar -->
				<div id="col-left" class="tfml-languages-sidebar">
					<div class="col-wrap">
						<div class="form-wrap tfml-card-panel">
							<?php if ( null !== $edit_lang ) : ?>
								<h2><?php esc_html_e( 'Editar idioma', 'tf-multilingual' ); ?>: <?php echo esc_html( $edit_lang->get_name() ); ?></h2>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PARENT_SLUG ) ); ?>">
									<input type="hidden" name="tfml_action" value="update_language">
									<input type="hidden" name="code" value="<?php echo esc_attr( $edit_lang->get_code() ); ?>">
									<?php if ( function_exists( 'wp_nonce_field' ) ) : ?>
										<?php wp_nonce_field( self::NONCE_ACTION_UPDATE, self::NONCE_NAME ); ?>
									<?php endif; ?>

									<div class="tfml-form-grid">
										<div class="form-field form-required">
											<label for="edit_code_display"><?php esc_html_e( 'Código de idioma', 'tf-multilingual' ); ?></label>
											<input type="text" id="edit_code_display" value="<?php echo esc_attr( $edit_lang->get_code() ); ?>" disabled style="background:#f1f5f9;">
											<p class="description"><?php esc_html_e( 'Clave canónica no modificable.', 'tf-multilingual' ); ?></p>
										</div>

										<div class="form-field form-required">
											<label for="edit_locale"><?php esc_html_e( 'Locale de WordPress', 'tf-multilingual' ); ?></label>
											<input type="text" name="locale" id="edit_locale" value="<?php echo esc_attr( $edit_lang->get_locale() ); ?>" required>
											<p class="description"><?php esc_html_e( 'ej. es_ES, en_US', 'tf-multilingual' ); ?></p>
										</div>

										<div class="form-field form-required">
											<label for="edit_name"><?php esc_html_e( 'Nombre internacional (Admin)', 'tf-multilingual' ); ?></label>
											<input type="text" name="name" id="edit_name" value="<?php echo esc_attr( $edit_lang->get_name() ); ?>" required>
										</div>

										<div class="form-field form-required">
											<label for="edit_native_name"><?php esc_html_e( 'Nombre nativo / Endónimo', 'tf-multilingual' ); ?></label>
											<input type="text" name="native_name" id="edit_native_name" value="<?php echo esc_attr( $edit_lang->get_native_name() ); ?>" required>
										</div>

										<div class="form-field">
											<label for="edit_flag_code"><?php esc_html_e( 'Bandera visual', 'tf-multilingual' ); ?></label>
											<div style="display:flex; align-items:center; gap:8px;">
												<span id="edit_flag_preview">
													<?php echo $this->get_flag_html( $edit_lang->get_code(), $edit_lang->get_native_name(), $edit_lang->get_flag_code() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
												</span>
												<select name="flag_code" id="edit_flag_code" style="flex:1;">
													<option value="" <?php selected( empty( $edit_lang->get_flag_code() ) ); ?>>
														<?php esc_html_e( '— Automática —', 'tf-multilingual' ); ?>
													</option>
													<?php foreach ( LanguageFlagRenderer::get_available_flags() as $flag_id => $flag_label ) : ?>
														<option value="<?php echo esc_attr( $flag_id ); ?>" <?php selected( $edit_lang->get_flag_code(), $flag_id ); ?>>
															<?php echo esc_html( $flag_label ); ?>
														</option>
													<?php endforeach; ?>
												</select>
											</div>
										</div>

										<div class="form-field">
											<label for="edit_order"><?php esc_html_e( 'Orden de visualización', 'tf-multilingual' ); ?></label>
											<input type="number" name="order" id="edit_order" value="<?php echo (int) $edit_lang->get_order(); ?>">
										</div>

										<div class="form-field form-field-full" style="margin-top:8px;">
											<p class="submit" style="display:flex; gap:10px; align-items:center; margin:0; padding:0;">
												<input type="submit" class="button button-primary" value="<?php esc_attr_e( 'Actualizar idioma', 'tf-multilingual' ); ?>">
												<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PARENT_SLUG ) ); ?>" class="button button-secondary"><?php esc_html_e( 'Cancelar', 'tf-multilingual' ); ?></a>
											</p>
										</div>
									</div>
								</form>
							<?php else : ?>
								<h2><?php esc_html_e( 'Añadir nuevo idioma', 'tf-multilingual' ); ?></h2>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PARENT_SLUG ) ); ?>">
									<input type="hidden" name="tfml_action" value="add_language">
									<?php if ( function_exists( 'wp_nonce_field' ) ) : ?>
										<?php wp_nonce_field( self::NONCE_ACTION_ADD, self::NONCE_NAME ); ?>
									<?php endif; ?>

									<div class="tfml-form-grid">
										<div class="form-field form-field-full">
											<label for="tfml_predefined_select"><?php esc_html_e( 'Elegir un idioma predefinido...', 'tf-multilingual' ); ?></label>
											<select id="tfml_predefined_select" style="width:100%;">
												<option value=""><?php esc_html_e( '— Seleccione para autocompletar —', 'tf-multilingual' ); ?></option>
												<?php foreach ( $predefined as $p_code => $p_data ) : ?>
													<?php $is_already = isset( $all_languages[ $p_code ] ); ?>
													<option value="<?php echo esc_attr( $p_code ); ?>"
														data-name="<?php echo esc_attr( $p_data['name'] ); ?>"
														data-native="<?php echo esc_attr( $p_data['native_name'] ); ?>"
														data-locale="<?php echo esc_attr( $p_data['locale'] ); ?>"
														data-flag="<?php echo esc_attr( $p_data['flag'] ?? '' ); ?>"
														<?php echo $is_already ? 'disabled' : ''; ?>>
														<?php echo esc_html( $p_data['native_name'] . ' (' . $p_data['name'] . ') [' . $p_code . ']' . ( $is_already ? ' — ' . __( 'Registrado', 'tf-multilingual' ) : '' ) ); ?>
													</option>
												<?php endforeach; ?>
											</select>
											<p class="description"><?php esc_html_e( 'Autocompleta los campos o configúrelos manualmente.', 'tf-multilingual' ); ?></p>
										</div>

										<div class="form-field form-required">
											<label for="tfml_code"><?php esc_html_e( 'Código (Slug)', 'tf-multilingual' ); ?> <span class="description">(<?php esc_html_e( 'requerido', 'tf-multilingual' ); ?>)</span></label>
											<input type="text" name="code" id="tfml_code" placeholder="ej. fr, de" required>
											<p class="description"><?php esc_html_e( 'Prefijo para URL (/fr/).', 'tf-multilingual' ); ?></p>
										</div>

										<div class="form-field form-required">
											<label for="tfml_locale"><?php esc_html_e( 'Locale de WordPress', 'tf-multilingual' ); ?> <span class="description">(<?php esc_html_e( 'requerido', 'tf-multilingual' ); ?>)</span></label>
											<input type="text" name="locale" id="tfml_locale" placeholder="ej. fr_FR" required>
											<p class="description"><?php esc_html_e( 'Configuración regional.', 'tf-multilingual' ); ?></p>
										</div>

										<div class="form-field form-required">
											<label for="tfml_name"><?php esc_html_e( 'Nombre internacional', 'tf-multilingual' ); ?> <span class="description">(<?php esc_html_e( 'requerido', 'tf-multilingual' ); ?>)</span></label>
											<input type="text" name="name" id="tfml_name" placeholder="ej. French" required>
										</div>

										<div class="form-field form-required">
											<label for="tfml_native_name"><?php esc_html_e( 'Nombre nativo / Endónimo', 'tf-multilingual' ); ?> <span class="description">(<?php esc_html_e( 'requerido', 'tf-multilingual' ); ?>)</span></label>
											<input type="text" name="native_name" id="tfml_native_name" placeholder="ej. Français" required>
										</div>

										<div class="form-field">
											<label for="tfml_flag_code"><?php esc_html_e( 'Bandera visual', 'tf-multilingual' ); ?></label>
											<select name="flag_code" id="tfml_flag_code">
												<option value=""><?php esc_html_e( '— Automática —', 'tf-multilingual' ); ?></option>
												<?php foreach ( LanguageFlagRenderer::get_available_flags() as $flag_id => $flag_label ) : ?>
													<option value="<?php echo esc_attr( $flag_id ); ?>"><?php echo esc_html( $flag_label ); ?></option>
												<?php endforeach; ?>
											</select>
										</div>

										<div class="form-field">
											<label for="tfml_order"><?php esc_html_e( 'Orden de visualización', 'tf-multilingual' ); ?></label>
											<input type="number" name="order" id="tfml_order" value="<?php echo count( $all_languages ) * 10 + 10; ?>">
										</div>

										<div class="form-field form-field-full" style="margin-top:6px;">
											<label style="display:flex; align-items:center; gap:8px; font-weight:normal; cursor:pointer;">
												<input type="checkbox" name="active" value="1" checked style="margin:0;">
												<strong><?php esc_html_e( 'Activar inmediatamente para navegación pública', 'tf-multilingual' ); ?></strong>
											</label>
										</div>

										<div class="form-field form-field-full" style="margin-top:2px;">
											<label style="display:flex; align-items:center; gap:8px; font-weight:normal; cursor:pointer;">
												<input type="checkbox" name="is_default" value="1" style="margin:0;">
												<?php esc_html_e( 'Establecer como idioma predeterminado soberano', 'tf-multilingual' ); ?>
											</label>
										</div>

										<div class="form-field form-field-full" style="margin-top:10px;">
											<p class="submit" style="margin:0; padding:0;">
												<input type="submit" class="button button-primary" value="<?php esc_attr_e( 'Añadir nuevo idioma', 'tf-multilingual' ); ?>">
											</p>
										</div>
									</div>
								</form>

								<script>
								(function(){
									var sel = document.getElementById('tfml_predefined_select');
									if (!sel) return;
									sel.addEventListener('change', function(){
										var opt = sel.options[sel.selectedIndex];
										if (!opt || !opt.value) return;
										document.getElementById('tfml_code').value = opt.value;
										document.getElementById('tfml_name').value = opt.getAttribute('data-name') || '';
										document.getElementById('tfml_native_name').value = opt.getAttribute('data-native') || '';
										document.getElementById('tfml_locale').value = opt.getAttribute('data-locale') || '';
										var flagSel = document.getElementById('tfml_flag_code');
										if (flagSel) {
											flagSel.value = opt.getAttribute('data-flag') || '';
										}
									});
								})();
								</script>
							<?php endif; ?>
						</div>
					</div>
				</div>

				<!-- Columna Derecha: Tabla de Idiomas Configurados -->
				<div id="col-right" class="tfml-languages-main">
					<div class="col-wrap">
						<div class="tfml-table-card">
							<table class="wp-list-table widefat striped tfml-languages-table" style="table-layout:auto;">
								<thead>
									<tr>
										<th scope="col" style="width:52px; text-align:center;"><?php esc_html_e( 'Bandera', 'tf-multilingual' ); ?></th>
										<th scope="col" style="min-width:140px; white-space:nowrap;"><?php esc_html_e( 'Idioma / Endónimo', 'tf-multilingual' ); ?></th>
										<th scope="col" style="width:65px; white-space:nowrap;"><?php esc_html_e( 'Código', 'tf-multilingual' ); ?></th>
										<th scope="col" style="width:85px; white-space:nowrap;"><?php esc_html_e( 'Locale', 'tf-multilingual' ); ?></th>
										<th scope="col" style="width:60px; text-align:center; white-space:nowrap;"><?php esc_html_e( 'Orden', 'tf-multilingual' ); ?></th>
										<th scope="col" style="width:130px; text-align:center; white-space:nowrap;"><?php esc_html_e( 'Predeterminado', 'tf-multilingual' ); ?></th>
										<th scope="col" style="width:85px; text-align:center; white-space:nowrap;"><?php esc_html_e( 'Estado', 'tf-multilingual' ); ?></th>
										<th scope="col" style="width:170px; text-align:right; white-space:nowrap;"><?php esc_html_e( 'Acciones', 'tf-multilingual' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php if ( empty( $all_languages ) ) : ?>
										<tr>
											<td colspan="8" style="text-align:center; padding:20px;">
												<em><?php esc_html_e( 'No hay idiomas configurados en el catálogo.', 'tf-multilingual' ); ?></em>
											</td>
										</tr>
									<?php else : ?>
										<?php foreach ( $all_languages as $lang ) : ?>
											<?php
											$code       = $lang->get_code();
											$is_default = ( $code === $default_code );
											$is_active  = $lang->is_active();

											$toggle_url = function_exists( 'wp_nonce_url' )
												? wp_nonce_url(
													admin_url( 'admin.php?page=' . self::PARENT_SLUG . '&tfml_action=toggle_active&code=' . urlencode( $code ) ),
													self::NONCE_ACTION_TOGGLE . '_' . $code,
													self::NONCE_NAME
												)
												: '#';

											$default_url = function_exists( 'wp_nonce_url' )
												? wp_nonce_url(
													admin_url( 'admin.php?page=' . self::PARENT_SLUG . '&tfml_action=set_default&code=' . urlencode( $code ) ),
													self::NONCE_ACTION_DEFAULT . '_' . $code,
													self::NONCE_NAME
												)
												: '#';

											$delete_url = function_exists( 'wp_nonce_url' )
												? wp_nonce_url(
													admin_url( 'admin.php?page=' . self::PARENT_SLUG . '&tfml_action=delete_language&code=' . urlencode( $code ) ),
													self::NONCE_ACTION_DELETE . '_' . $code,
													self::NONCE_NAME
												)
												: '#';

											$edit_url = admin_url( 'admin.php?page=' . self::PARENT_SLUG . '&edit=' . urlencode( $code ) );
											?>
											<tr>
												<td style="text-align:center; vertical-align:middle; width:52px;">
													<?php echo $this->get_flag_html( $code, $lang->get_native_name(), $lang->get_flag_code() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
												</td>
												<td style="vertical-align:middle;">
													<strong><?php echo esc_html( $lang->get_native_name() ); ?></strong>
													<br>
													<span style="color:#666; font-size:12px;"><?php echo esc_html( $lang->get_name() ); ?></span>
												</td>
												<td style="vertical-align:middle;">
													<code><?php echo esc_html( $code ); ?></code>
												</td>
												<td style="vertical-align:middle;">
													<code><?php echo esc_html( $lang->get_locale() ); ?></code>
												</td>
												<td style="text-align:center; vertical-align:middle;">
													<?php echo (int) $lang->get_order(); ?>
												</td>
												<td style="text-align:center; vertical-align:middle;">
													<?php if ( $is_default ) : ?>
														<span class="badge" style="background:#1e3a2f; color:#ffffff; padding:4px 8px; border-radius:3px; font-weight:700; font-size:11px; display:inline-block;">
															★ <?php esc_html_e( 'Predeterminado', 'tf-multilingual' ); ?>
														</span>
													<?php elseif ( $is_active ) : ?>
														<a href="<?php echo esc_url( $default_url ); ?>" class="button button-small" title="<?php esc_attr_e( 'Hacer este idioma el predeterminado del sitio', 'tf-multilingual' ); ?>">
															<?php esc_html_e( 'Hacer defecto', 'tf-multilingual' ); ?>
														</a>
													<?php else : ?>
														<span style="color:#aaa; font-size:11px;"><em><?php esc_html_e( 'Inactivo', 'tf-multilingual' ); ?></em></span>
													<?php endif; ?>
												</td>
												<td style="text-align:center; vertical-align:middle;">
													<?php if ( $is_active ) : ?>
														<span style="color:#46b450; font-weight:700; font-size:12px;">✔ <?php esc_html_e( 'Activo', 'tf-multilingual' ); ?></span>
													<?php else : ?>
														<span style="color:#999; font-weight:600; font-size:12px;">✖ <?php esc_html_e( 'Inactivo', 'tf-multilingual' ); ?></span>
													<?php endif; ?>
												</td>
												<td style="text-align:right; vertical-align:middle;">
													<div style="display:flex; justify-content:flex-end; gap:5px; align-items:center;">
														<a href="<?php echo esc_url( $edit_url ); ?>" class="button button-small" title="<?php esc_attr_e( 'Editar propiedades del idioma', 'tf-multilingual' ); ?>">
															<?php esc_html_e( 'Editar', 'tf-multilingual' ); ?>
														</a>

														<?php if ( ! $is_default ) : ?>
															<a href="<?php echo esc_url( $toggle_url ); ?>" class="button button-small" title="<?php echo $is_active ? esc_attr__( 'Desactivar idioma', 'tf-multilingual' ) : esc_attr__( 'Activar idioma', 'tf-multilingual' ); ?>">
																<?php echo $is_active ? esc_html__( 'Desactivar', 'tf-multilingual' ) : esc_html__( 'Activar', 'tf-multilingual' ); ?>
															</a>
															<a href="<?php echo esc_url( $delete_url ); ?>" class="button button-small button-link-delete" style="color:#dc3232;" onclick="return confirm('<?php echo esc_js( sprintf( /* translators: 1: language name, 2: language code */ __( '¿Está seguro de eliminar el idioma "%1$s" (%2$s)? Esta acción no puede deshacerse.', 'tf-multilingual' ), $lang->get_name(), $code ) ); ?>');">
																<?php esc_html_e( 'Eliminar', 'tf-multilingual' ); ?>
															</a>
														<?php else : ?>
															<span style="color:#888; font-size:11px; padding:0 4px;" title="<?php esc_attr_e( 'El idioma predeterminado no puede desactivarse ni eliminarse.', 'tf-multilingual' ); ?>">
																🔒 <?php esc_html_e( 'Protegido', 'tf-multilingual' ); ?>
															</span>
														<?php endif; ?>
													</div>
												</td>
											</tr>
										<?php endforeach; ?>
									<?php endif; ?>
								</tbody>
							</table>
						</div>

						<!-- Panel Compacto de Arquitectura de Módulos (Spatial UI) -->
						<div class="tfml-matrix-compact-card">
							<div class="matrix-info">
								<div class="matrix-header-line">
									<h3 class="matrix-title"><?php esc_html_e( 'Matriz de Módulos & Estado Operativo de TF Multilingual', 'tf-multilingual' ); ?></h3>
									<span class="matrix-pill">
										<svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>
										<?php esc_html_e( '8/8 Módulos Activos y Certificados', 'tf-multilingual' ); ?>
									</span>
								</div>
								<p class="matrix-desc">
									<?php esc_html_e( 'Servicios de dominio autónomos, endpoints REST soberanos (/tf-multilingual/v1/*), resolución de metadatos SEO y ciclo editorial multilingüe operativo.', 'tf-multilingual' ); ?>
								</p>
							</div>
							<div class="matrix-actions">
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=tfml-diagnostic' ) ); ?>" class="button button-secondary">
									<?php esc_html_e( 'Ver Diagnóstico Completo →', 'tf-multilingual' ); ?>
								</a>
							</div>
						</div>
					</div>
				</div>

			</div>
		</div>
		<?php
	}
}
