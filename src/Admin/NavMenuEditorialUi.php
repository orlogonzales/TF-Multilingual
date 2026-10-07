<?php
/**
 * Multilingual Navigation Menu Admin UI Component.
 *
 * @package TF\Multilingual\Admin
 */

declare( strict_types=1 );

namespace TF\Multilingual\Admin;

use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Domain\Navigation\NavMenuLocationRepository;
use TF\Multilingual\Domain\Translation\ContentTranslationResolver;
use TF\Multilingual\Domain\Translation\TranslationGroupRepository;

/**
 * Class NavMenuEditorialUi
 *
 * Provides a native WordPress administration interface to assign navigation menus to theme locations per language.
 */
class NavMenuEditorialUi {

	/**
	 * Page slug for the navigation admin screen.
	 */
	public const PAGE_SLUG = 'tfml-navigation';

	/**
	 * Nonce action for location mapping submissions.
	 */
	public const NONCE_ACTION = 'tfml_nav_locations_action';

	/**
	 * Nonce field name.
	 */
	public const NONCE_NAME = 'tfml_nav_nonce';

	/**
	 * Location repository.
	 *
	 * @var NavMenuLocationRepository
	 */
	private NavMenuLocationRepository $location_repository;

	/**
	 * Language registry.
	 *
	 * @var LanguageRegistry
	 */
	private LanguageRegistry $language_registry;

	/**
	 * Content translation resolver.
	 *
	 * @var ContentTranslationResolver
	 */
	private ContentTranslationResolver $translation_resolver;

	/**
	 * Translation group repository.
	 *
	 * @var TranslationGroupRepository
	 */
	private TranslationGroupRepository $group_repository;

	/**
	 * Notice message to display.
	 *
	 * @var array{type: string, message: string}|null
	 */
	private ?array $notice = null;

	/**
	 * Constructor.
	 *
	 * @param NavMenuLocationRepository  $location_repository   Location repository.
	 * @param LanguageRegistry           $language_registry     Language registry.
	 * @param ContentTranslationResolver $translation_resolver  Translation resolver.
	 * @param TranslationGroupRepository $group_repository       Group repository.
	 */
	public function __construct(
		NavMenuLocationRepository $location_repository,
		LanguageRegistry $language_registry,
		ContentTranslationResolver $translation_resolver,
		TranslationGroupRepository $group_repository
	) {
		$this->location_repository  = $location_repository;
		$this->language_registry    = $language_registry;
		$this->translation_resolver = $translation_resolver;
		$this->group_repository     = $group_repository;
	}

	/**
	 * Registers WordPress admin hooks.
	 *
	 * @return void
	 */
	public function init_hooks(): void {
		add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'handle_form_submission' ) );
	}

	/**
	 * Registers the Multilingual Menus submenu under Appearance.
	 *
	 * @return void
	 */
	public function register_admin_menu(): void {
		add_theme_page(
			__( 'Menús Multilingües', 'tf-multilingual' ),
			__( 'Menús Multilingües', 'tf-multilingual' ),
			'edit_theme_options',
			self::PAGE_SLUG,
			array( $this, 'render_admin_page' )
		);
	}

	/**
	 * Handles form submission for menu location assignments.
	 *
	 * @return void
	 */
	public function handle_form_submission(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = sanitize_key( $_GET['page'] ?? '' );
		if ( self::PAGE_SLUG !== $page ) {
			return;
		}

		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_theme_options' ) ) {
			wp_die( esc_html__( 'No tienes permisos suficientes para realizar esta acción.', 'tf-multilingual' ) );
		}

		check_admin_referer( self::NONCE_ACTION, self::NONCE_NAME );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$submitted_locations = isset( $_POST['tfml_nav_locations'] ) && is_array( $_POST['tfml_nav_locations'] )
			? $_POST['tfml_nav_locations'] // Sanitized per element below.
			: array();

		$active_languages = $this->language_registry->active();
		$default_code     = $this->language_registry->get_default_code();

		foreach ( $submitted_locations as $location => $lang_menus ) {
			$clean_location = sanitize_key( (string) $location );
			if ( empty( $clean_location ) || ! is_array( $lang_menus ) ) {
				continue;
			}

			foreach ( $active_languages as $lang ) {
				$code = $lang->get_code();
				if ( $code === $default_code ) {
					continue; // Default language managed by native WordPress locations.
				}

				$menu_id = isset( $lang_menus[ $code ] ) && is_numeric( $lang_menus[ $code ] )
					? (int) $lang_menus[ $code ]
					: null;

				$this->location_repository->set_menu_for_location( $clean_location, $code, $menu_id );
			}
		}

		$saved = $this->location_repository->persist();
		if ( $saved ) {
			$this->notice = array(
				'type'    => 'success',
				'message' => __( 'Asignaciones de menús multilingües guardadas correctamente.', 'tf-multilingual' ),
			);
		} else {
			$this->notice = array(
				'type'    => 'error',
				'message' => __( 'Ocurrió un error al persistir la configuración.', 'tf-multilingual' ),
			);
		}
	}

	/**
	 * Renders the administration settings page.
	 *
	 * @return void
	 */
	public function render_admin_page(): void {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			wp_die( esc_html__( 'No tienes permisos suficientes para acceder a esta página.', 'tf-multilingual' ) );
		}

		$active_languages     = $this->language_registry->active();
		$default_code         = $this->language_registry->get_default_code();
		$registered_locations = $this->get_registered_locations();
		$available_menus      = $this->get_available_menus();
		$current_mappings     = $this->location_repository->get_all_mappings();
		$native_locations     = $this->get_native_locations();

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Navegación y Menús Multilingües', 'tf-multilingual' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Asocia cada ubicación de menú de tu tema con el menú correspondiente en cada idioma.', 'tf-multilingual' ); ?>
			</p>

			<?php if ( null !== $this->notice ) : ?>
				<div class="notice notice-<?php echo esc_attr( $this->notice['type'] ); ?> is-dismissible">
					<p><?php echo esc_html( $this->notice['message'] ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( empty( $registered_locations ) ) : ?>
				<div class="notice notice-warning">
					<p><?php esc_html_e( 'El tema actual no tiene ubicaciones de menú registradas.', 'tf-multilingual' ); ?></p>
				</div>
			<?php else : ?>
				<form method="post" action="">
					<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>

					<table class="widefat fixed striped" style="margin-top: 15px;">
						<thead>
							<tr>
								<th scope="col" style="width: 250px;"><?php esc_html_e( 'Ubicación del Tema', 'tf-multilingual' ); ?></th>
								<?php foreach ( $active_languages as $lang ) : ?>
									<th scope="col">
										<?php echo esc_html( $lang->get_name() ); ?>
										<code>(<?php echo esc_html( $lang->get_code() ); ?>)</code>
										<?php if ( $lang->get_code() === $default_code ) : ?>
											<span class="badge" style="background:#e0e0e0; padding:2px 6px; border-radius:3px; font-size:11px;"><?php esc_html_e( 'Nativo', 'tf-multilingual' ); ?></span>
										<?php endif; ?>
									</th>
								<?php endforeach; ?>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $registered_locations as $location_slug => $location_label ) : ?>
								<tr>
									<th><strong><?php echo esc_html( $location_label ); ?></strong><br><small><code><?php echo esc_html( $location_slug ); ?></code></small></th>
									<?php foreach ( $active_languages as $lang ) : ?>
										<?php
										$code = $lang->get_code();
										if ( $code === $default_code ) :
											$native_id   = $native_locations[ $location_slug ] ?? 0;
											$native_menu = $available_menus[ $native_id ] ?? null;
											?>
											<td>
												<?php if ( $native_menu ) : ?>
													<strong><?php echo esc_html( $native_menu->name ); ?></strong>
												<?php else : ?>
													<em><?php esc_html_e( '— Ninguno asignado en Core —', 'tf-multilingual' ); ?></em>
												<?php endif; ?>
											</td>
										<?php else : ?>
											<?php
											$assigned_id = $current_mappings[ $location_slug ][ $code ] ?? 0;
											?>
											<td>
												<select name="tfml_nav_locations[<?php echo esc_attr( $location_slug ); ?>][<?php echo esc_attr( $code ); ?>]">
													<option value="0"><?php esc_html_e( '— Usar menú canónico / predeterminado —', 'tf-multilingual' ); ?></option>
													<?php foreach ( $available_menus as $menu_item ) : ?>
														<option value="<?php echo esc_attr( (string) $menu_item->term_id ); ?>" <?php selected( (int) $assigned_id, (int) $menu_item->term_id ); ?>>
															<?php echo esc_html( $menu_item->name ); ?>
														</option>
													<?php endforeach; ?>
												</select>
											</td>
										<?php endif; ?>
									<?php endforeach; ?>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>

					<p class="submit">
						<input type="submit" name="submit" id="submit" class="button button-primary" value="<?php esc_attr_e( 'Guardar Asignaciones', 'tf-multilingual' ); ?>">
					</p>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Retrieves registered theme nav menu locations.
	 *
	 * @return array<string, string>
	 */
	protected function get_registered_locations(): array {
		if ( function_exists( 'get_registered_nav_menus' ) ) {
			$locs = get_registered_nav_menus();
			return is_array( $locs ) ? $locs : array();
		}

		if ( isset( $GLOBALS['wp_test_registered_nav_menus'] ) && is_array( $GLOBALS['wp_test_registered_nav_menus'] ) ) {
			return $GLOBALS['wp_test_registered_nav_menus'];
		}

		return array();
	}

	/**
	 * Retrieves native WordPress theme location assignments.
	 *
	 * @return array<string, int>
	 */
	protected function get_native_locations(): array {
		if ( function_exists( 'get_nav_menu_locations' ) ) {
			$locs = get_nav_menu_locations();
			return is_array( $locs ) ? $locs : array();
		}

		if ( isset( $GLOBALS['wp_test_nav_menu_locations'] ) && is_array( $GLOBALS['wp_test_nav_menu_locations'] ) ) {
			return $GLOBALS['wp_test_nav_menu_locations'];
		}

		return array();
	}

	/**
	 * Retrieves all available WordPress nav menus.
	 *
	 * @return array<int, object> Map of term_id => menu object.
	 */
	protected function get_available_menus(): array {
		$menus_map = array();

		if ( function_exists( 'wp_get_nav_menus' ) ) {
			$menus = wp_get_nav_menus( array( 'hide_empty' => false ) );
			if ( is_array( $menus ) ) {
				foreach ( $menus as $m ) {
					if ( is_object( $m ) && isset( $m->term_id ) ) {
						$menus_map[ (int) $m->term_id ] = $m;
					}
				}
			}
		}

		if ( empty( $menus_map ) && isset( $GLOBALS['wp_test_nav_menus'] ) && is_array( $GLOBALS['wp_test_nav_menus'] ) ) {
			return $GLOBALS['wp_test_nav_menus'];
		}

		return $menus_map;
	}
}
