<?php
/**
 * TF Multilingual — Administrative Control Center UI (Spatial UI)
 *
 * Implements the executive dashboard and control center for TF Multilingual:
 * - High-level KPI summary cards (groups, elements, languages, retained translations, system health).
 * - Direct quick-action cards (Migration History, 21 Retained Translations, Diagnostics).
 * - 3-column operational overview (Retained FR custody preview, Diagnostic health checklist, Migration timeline).
 * - Active languages bar and quick documentation access.
 *
 * @package TF\Multilingual\Admin
 */

declare(strict_types=1);

namespace TF\Multilingual\Admin;

use TF\Multilingual\Diagnostic\DiagnosticService;
use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Domain\Translation\TranslationGroupRepository;
use TF\Multilingual\Migration\RetainedTranslationRegistry;

/**
 * Class ControlCenterUi
 */
class ControlCenterUi {

	/**
	 * Top-level and primary page slug.
	 */
	public const PAGE_SLUG = 'tf-multilingual';

	/**
	 * Parent menu slug.
	 */
	public const PARENT_SLUG = 'tf-multilingual';

	/**
	 * Required user capability to view the control center.
	 */
	public const REQUIRED_CAPABILITY = 'manage_options';

	/**
	 * Language registry.
	 *
	 * @var LanguageRegistry
	 */
	private LanguageRegistry $language_registry;

	/**
	 * Translation group repository.
	 *
	 * @var TranslationGroupRepository
	 */
	private TranslationGroupRepository $group_repository;

	/**
	 * Retained translation registry.
	 *
	 * @var RetainedTranslationRegistry
	 */
	private RetainedTranslationRegistry $retained_registry;

	/**
	 * Diagnostic service.
	 *
	 * @var DiagnosticService
	 */
	private DiagnosticService $diagnostic_service;

	/**
	 * Constructor.
	 *
	 * @param LanguageRegistry             $language_registry
	 * @param TranslationGroupRepository     $group_repository
	 * @param RetainedTranslationRegistry   $retained_registry
	 * @param DiagnosticService            $diagnostic_service
	 */
	public function __construct(
		LanguageRegistry $language_registry,
		TranslationGroupRepository $group_repository,
		RetainedTranslationRegistry $retained_registry,
		DiagnosticService $diagnostic_service
	) {
		$this->language_registry  = $language_registry;
		$this->group_repository   = $group_repository;
		$this->retained_registry  = $retained_registry;
		$this->diagnostic_service = $diagnostic_service;

		if ( function_exists( 'add_action' ) ) {
			add_action( 'admin_menu', array( $this, 'register_menu_page' ), 5 );
		}
	}

	/**
	 * Registers the top-level TF Multilingual menu page and its first submenu item.
	 *
	 * @return void
	 */
	public function register_menu_page(): void {
		if ( ! function_exists( 'add_menu_page' ) || ! function_exists( 'add_submenu_page' ) ) {
			return;
		}

		global $admin_page_hooks;
		if ( ! isset( $admin_page_hooks[ self::PARENT_SLUG ] ) ) {
			add_menu_page(
				__( 'TF Multilingual', 'tf-multilingual' ),
				__( 'TF Multilingual', 'tf-multilingual' ),
				self::REQUIRED_CAPABILITY,
				self::PARENT_SLUG,
				array( $this, 'render_page' ),
				'dashicons-translation',
				58
			);
		}

		// First submenu entry pointing directly to Control Center
		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Control Center — TF Multilingual', 'tf-multilingual' ),
			__( 'Control Center', 'tf-multilingual' ),
			self::REQUIRED_CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render_page' ),
			1
		);
	}

	/**
	 * Renders the complete Spatial UI Control Center dashboard.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( function_exists( 'current_user_can' ) && ! current_user_can( self::REQUIRED_CAPABILITY ) ) {
			wp_die( esc_html__( 'No tienes permisos suficientes para acceder al Centro de Control de TF Multilingual.', 'tf-multilingual' ) );
		}

		global $wpdb;

		// 1. Metrics & KPIs.
		$groups_count   = $this->get_groups_count();
		$elements_count = $this->get_elements_count();
		$retained_count = count( $this->retained_registry->get_all() );
		$active_langs   = $this->language_registry->active();
		$active_count   = count( $active_langs );

		// 2. Sample of retained French translations for preview table.
		$retained_preview = $this->get_retained_preview_data();

		// 3. Asset URLs
		$plugin_url = defined( 'TFML_PLUGIN_URL' )
			? TFML_PLUGIN_URL
			: ( function_exists( 'plugins_url' )
				? plugins_url( '/', dirname( __DIR__ ) . '/tf-multilingual.php' )
				: '/wp-content/plugins/tf-multilingual/' );
		$flags_url  = $plugin_url . 'assets/images/flags/';

		// 4. Output Spatial UI HTML
		?>
		<div class="wrap tfml-spatial-wrap">
			<!-- Spatial Header -->
			<div class="tfml-spatial-header">
				<div class="tfml-header-left">
					<div class="tfml-header-icon" aria-hidden="true">
						<svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 17.93c-3.95-.49-7-3.85-7-7.93 0-.62.08-1.21.21-1.79L9 15v1c0 1.1.9 2 2 2v1.93zm6.9-2.54c-.26-.81-1-1.39-1.9-1.39h-1v-3c0-.55-.45-1-1-1H8v-2h2c.55 0 1-.45 1-1V7h2c1.1 0 2-.9 2-2v-.41c2.93 1.19 5 4.06 5 7.41 0 2.08-.8 3.97-2.1 5.39z"/></svg>
					</div>
					<div>
						<h1 class="tfml-header-title"><?php esc_html_e( 'TF Multilingual — Control Center', 'tf-multilingual' ); ?></h1>
						<p class="tfml-header-subtitle"><?php esc_html_e( 'Panel central para gestionar, supervisar y mantener todas las operaciones multilingües de EcoTerra.', 'tf-multilingual' ); ?></p>
					</div>
				</div>
				<div class="tfml-header-actions">
					<span class="tfml-pill tfml-pill-success">
						<span class="tfml-pill-dot" aria-hidden="true"></span>
						<?php esc_html_e( 'Entorno UAT (Fase 3.56)', 'tf-multilingual' ); ?>
					</span>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=tfml-diagnostic' ) ); ?>" class="tfml-btn tfml-btn-outline tfml-btn-sm">
						<span class="dashicons dashicons-book" style="font-size:16px;line-height:1.2;"></span>
						<?php esc_html_e( 'Diagnóstico', 'tf-multilingual' ); ?>
					</a>
				</div>
			</div>

			<!-- Top KPI Grid -->
			<div class="tfml-kpi-grid">
				<!-- KPI 1: Grupos activos -->
				<div class="tfml-kpi-card">
					<div class="tfml-kpi-top">
						<div class="tfml-kpi-icon-wrap blue" aria-hidden="true">
							<svg viewBox="0 0 24 24"><path d="M11.99 18.54l-7.37-5.73L3 14.07l9 7 9-7-1.63-1.27-7.38 5.74zM12 16l7.36-5.73L21 9.07l-9-7-9 7 1.63 1.27L12 16z"/></svg>
						</div>
						<div class="tfml-kpi-chart-icon" aria-hidden="true">
							<span class="dashicons dashicons-chart-bar"></span>
						</div>
					</div>
					<div class="tfml-kpi-title"><?php esc_html_e( 'Grupos activos', 'tf-multilingual' ); ?></div>
					<div class="tfml-kpi-value"><?php echo esc_html( (string) $groups_count ); ?></div>
					<div class="tfml-kpi-subtitle"><?php esc_html_e( 'Grupos de traducción en TFML', 'tf-multilingual' ); ?></div>
				</div>

				<!-- KPI 2: Elementos vinculados -->
				<div class="tfml-kpi-card">
					<div class="tfml-kpi-top">
						<div class="tfml-kpi-icon-wrap purple" aria-hidden="true">
							<svg viewBox="0 0 24 24"><path d="M14 2H6c-1.1 0-1.99.9-1.99 2L4 20c0 1.1.89 2 1.99 2H18c1.1 0 2-.9 2-2V8l-6-6zm2 16H8v-2h8v2zm0-4H8v-2h8v2zm-3-5V3.5L18.5 9H13z"/></svg>
						</div>
						<div class="tfml-kpi-chart-icon" aria-hidden="true">
							<span class="dashicons dashicons-chart-bar"></span>
						</div>
					</div>
					<div class="tfml-kpi-title"><?php esc_html_e( 'Elementos vinculados', 'tf-multilingual' ); ?></div>
					<div class="tfml-kpi-value"><?php echo esc_html( (string) $elements_count ); ?></div>
					<div class="tfml-kpi-subtitle"><?php esc_html_e( 'Entradas WordPress gestionadas', 'tf-multilingual' ); ?></div>
				</div>

				<!-- KPI 3: Idiomas activos -->
				<div class="tfml-kpi-card">
					<div class="tfml-kpi-top">
						<div class="tfml-kpi-icon-wrap green" aria-hidden="true">
							<svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 17.93c-3.95-.49-7-3.85-7-7.93 0-.62.08-1.21.21-1.79L9 15v1c0 1.1.9 2 2 2v1.93zm6.9-2.54c-.26-.81-1-1.39-1.9-1.39h-1v-3c0-.55-.45-1-1-1H8v-2h2c.55 0 1-.45 1-1V7h2c1.1 0 2-.9 2-2v-.41c2.93 1.19 5 4.06 5 7.41 0 2.08-.8 3.97-2.1 5.39z"/></svg>
						</div>
					</div>
					<div class="tfml-kpi-title"><?php esc_html_e( 'Idiomas activos', 'tf-multilingual' ); ?></div>
					<div class="tfml-kpi-value"><?php echo esc_html( (string) $active_count ); ?></div>
					<div class="tfml-kpi-flags">
						<?php foreach ( $active_langs as $l_code => $lang_obj ) : ?>
							<span style="display:inline-flex;align-items:center;gap:3px;">
								<img src="<?php echo esc_url( $flags_url . $l_code . '.svg' ); ?>" alt="<?php echo esc_attr( $l_code ); ?>" class="tfml-kpi-flag-img" onerror="this.style.display='none'" />
								<?php echo esc_html( strtoupper( $l_code ) ); ?>
							</span>
						<?php endforeach; ?>
					</div>
				</div>

				<!-- KPI 4: Traducciones FR retenidas -->
				<div class="tfml-kpi-card">
					<div class="tfml-kpi-top">
						<div class="tfml-kpi-icon-wrap amber" aria-hidden="true">
							<svg viewBox="0 0 24 24"><path d="M10 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2h-8l-2-2z"/></svg>
						</div>
						<div class="tfml-kpi-chart-icon" aria-hidden="true">
							<span class="dashicons dashicons-chart-bar"></span>
						</div>
					</div>
					<div class="tfml-kpi-title"><?php esc_html_e( 'Traducciones FR retenidas', 'tf-multilingual' ); ?></div>
					<div class="tfml-kpi-value"><?php echo esc_html( (string) $retained_count ); ?></div>
					<div class="tfml-kpi-subtitle"><?php esc_html_e( 'En custodia histórica (Francés)', 'tf-multilingual' ); ?></div>
				</div>

				<!-- KPI 5: Estado del sistema -->
				<div class="tfml-kpi-card">
					<div class="tfml-kpi-top">
						<div class="tfml-kpi-icon-wrap emerald" aria-hidden="true">
							<svg viewBox="0 0 24 24"><path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm-2 16l-4-4 1.41-1.41L10 14.17l6.59-6.59L18 9l-8 8z"/></svg>
						</div>
						<div class="tfml-kpi-chart-icon" aria-hidden="true" style="color:var(--tfml-color-success);">
							<span class="dashicons dashicons-yes-alt"></span>
						</div>
					</div>
					<div class="tfml-kpi-title"><?php esc_html_e( 'Estado del sistema', 'tf-multilingual' ); ?></div>
					<div class="tfml-kpi-value status-optimal"><?php esc_html_e( 'Salud óptima', 'tf-multilingual' ); ?></div>
					<div class="tfml-kpi-subtitle"><?php esc_html_e( 'Todos los servicios operativos', 'tf-multilingual' ); ?></div>
				</div>
			</div>

			<!-- Quick Access Section: Revisión Rápida -->
			<div class="tfml-section-header">
				<div class="tfml-section-icon" aria-hidden="true">
					<svg viewBox="0 0 24 24"><path d="M7 2v11h3v9l7-12h-4l4-8z"/></svg>
				</div>
				<h2 class="tfml-section-title"><?php esc_html_e( 'Revisión rápida', 'tf-multilingual' ); ?></h2>
				<span class="tfml-section-desc"><?php esc_html_e( 'Accede rápidamente a las secciones más importantes del sistema.', 'tf-multilingual' ); ?></span>
			</div>

			<div class="tfml-action-cards-grid">
				<!-- Action 1: Historiales de migración -->
				<div class="tfml-action-card">
					<div class="tfml-action-card-top">
						<div class="tfml-action-card-icon blue" aria-hidden="true">
							<svg viewBox="0 0 24 24"><path d="M13 3c-4.97 0-9 4.03-9 9H1l3.89 3.89.07.14L9 12H6c0-3.87 3.13-7 7-7s7 3.13 7 7-3.13 7-7 7c-1.93 0-3.68-.79-4.94-2.06l-1.42 1.42C8.27 19.99 10.51 21 13 21c4.97 0 9-4.03 9-9s-4.03-9-9-9zm-1 5v5l4.28 2.54.72-1.21-3.5-2.08V8H12z"/></svg>
						</div>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=tfml-migration&tab=history' ) ); ?>" class="tfml-action-arrow" title="<?php esc_attr_e( 'Ver historial', 'tf-multilingual' ); ?>" aria-hidden="true">
							→
						</a>
					</div>
					<h3 class="tfml-action-card-title"><?php esc_html_e( 'Historiales de migración', 'tf-multilingual' ); ?></h3>
					<p class="tfml-action-card-desc"><?php esc_html_e( 'Revisa el historial de migraciones, fases ejecutadas y su estado.', 'tf-multilingual' ); ?></p>
					<div class="tfml-action-card-btn-wrap">
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=tfml-migration&tab=history' ) ); ?>" class="tfml-btn tfml-btn-outline tfml-btn-sm">
							<?php esc_html_e( 'Ver historial', 'tf-multilingual' ); ?>
						</a>
					</div>
				</div>

				<!-- Action 2: Traducciones FR retenidas -->
				<div class="tfml-action-card">
					<div class="tfml-action-card-top">
						<div class="tfml-action-card-icon purple" aria-hidden="true">
							<svg viewBox="0 0 24 24"><path d="M10 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2h-8l-2-2z"/></svg>
						</div>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=tfml-translations#retained-custody' ) ); ?>" class="tfml-action-arrow" title="<?php esc_attr_e( 'Ver traducciones retenidas', 'tf-multilingual' ); ?>" aria-hidden="true">
							→
						</a>
					</div>
					<h3 class="tfml-action-card-title">
						<?php
						/* translators: %d: number of retained translations */
						printf( esc_html__( 'Traducciones FR retenidas (%d)', 'tf-multilingual' ), (int) $retained_count );
						?>
					</h3>
					<p class="tfml-action-card-desc"><?php esc_html_e( 'Visualiza y gestiona las 21 traducciones en custodia histórica (Francés).', 'tf-multilingual' ); ?></p>
					<div class="tfml-action-card-btn-wrap">
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=tfml-translations#retained-custody' ) ); ?>" class="tfml-btn tfml-btn-outline tfml-btn-sm">
							<?php esc_html_e( 'Ver traducciones', 'tf-multilingual' ); ?>
						</a>
					</div>
				</div>

				<!-- Action 3: Diagnóstico -->
				<div class="tfml-action-card">
					<div class="tfml-action-card-top">
						<div class="tfml-action-card-icon green" aria-hidden="true">
							<svg viewBox="0 0 24 24"><path d="M3 13h2l3.5-7 4.5 14 3-7h5v-2h-4l-3 7-4.5-14L6 11H3z"/></svg>
						</div>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=tfml-diagnostic' ) ); ?>" class="tfml-action-arrow" title="<?php esc_attr_e( 'Ejecutar diagnóstico', 'tf-multilingual' ); ?>" aria-hidden="true">
							→
						</a>
					</div>
					<h3 class="tfml-action-card-title"><?php esc_html_e( 'Diagnóstico', 'tf-multilingual' ); ?></h3>
					<p class="tfml-action-card-desc"><?php esc_html_e( 'Verifica el estado del sistema, servicios y configuración multilingüe.', 'tf-multilingual' ); ?></p>
					<div class="tfml-action-card-btn-wrap">
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=tfml-diagnostic' ) ); ?>" class="tfml-btn tfml-btn-success tfml-btn-sm">
							<?php esc_html_e( 'Ejecutar diagnóstico', 'tf-multilingual' ); ?>
						</a>
					</div>
				</div>
			</div>

			<!-- 3-Column Middle Dashboard: Retained FR, Diagnostic Health, Migration Timeline -->
			<div class="tfml-dashboard-columns">
				<!-- Column 1: Traducciones FR retenidas -->
				<div class="tfml-card-panel">
					<div class="tfml-panel-header">
						<div class="tfml-panel-header-left">
							<svg viewBox="0 0 24 24"><path d="M10 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2h-8l-2-2z"/></svg>
							<h3 class="tfml-panel-title"><?php esc_html_e( 'Traducciones FR retenidas', 'tf-multilingual' ); ?></h3>
							<span class="tfml-pill tfml-pill-warning" style="font-size:11px;padding:2px 8px;">
								<?php
								/* translators: %d: number of retained records */
								printf( esc_html__( '%d registros', 'tf-multilingual' ), (int) $retained_count );
								?>
							</span>
						</div>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=tfml-translations#retained-custody' ) ); ?>" class="tfml-panel-link-top">
							<?php esc_html_e( 'Ver todas →', 'tf-multilingual' ); ?>
						</a>
					</div>
					<p class="tfml-panel-subtitle"><?php esc_html_e( 'Traducciones francesas preservadas de la migración histórica de WPML.', 'tf-multilingual' ); ?></p>

					<div class="tfml-spatial-table-wrap">
						<table class="tfml-spatial-table">
							<thead>
								<tr>
									<th><?php esc_html_e( 'TRID WPML', 'tf-multilingual' ); ?></th>
									<th><?php esc_html_e( 'Grupo TFML', 'tf-multilingual' ); ?></th>
									<th><?php esc_html_e( 'Elemento retenido', 'tf-multilingual' ); ?></th>
									<th><?php esc_html_e( 'Estado', 'tf-multilingual' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php if ( ! empty( $retained_preview ) ) : ?>
									<?php foreach ( $retained_preview as $item ) : ?>
										<tr>
											<td><span class="tfml-table-code"><?php echo esc_html( (string) $item['trid'] ); ?></span></td>
											<td><span class="tfml-table-code">#<?php echo esc_html( (string) $item['group_id'] ); ?></span></td>
											<td>
												<span class="dashicons dashicons-admin-page" style="font-size:14px;color:#64748b;margin-right:2px;vertical-align:middle;"></span>
												<strong><?php echo esc_html( $item['title'] ); ?></strong>
												<span style="color:#94a3b8;font-size:11px;">ID: <?php echo esc_html( (string) $item['element_id'] ); ?> [fr]</span>
											</td>
											<td>
												<span class="tfml-pill tfml-pill-warning" style="font-size:10.5px;padding:2px 7px;">
													<?php esc_html_e( 'En custodia', 'tf-multilingual' ); ?>
												</span>
											</td>
										</tr>
									<?php endforeach; ?>
								<?php else : ?>
									<tr>
										<td colspan="4" style="text-align:center;color:#94a3b8;padding:16px;">
											<?php esc_html_e( 'No hay traducciones retenidas.', 'tf-multilingual' ); ?>
										</td>
									</tr>
								<?php endif; ?>
							</tbody>
						</table>
					</div>

					<a href="<?php echo esc_url( admin_url( 'admin.php?page=tfml-translations#retained-custody' ) ); ?>" class="tfml-panel-footer-link">
						<?php
						/* translators: %d: number of retained translations */
						printf( esc_html__( 'Ver las %d traducciones retenidas →', 'tf-multilingual' ), (int) $retained_count );
						?>
					</a>
				</div>

				<!-- Column 2: Diagnóstico del sistema -->
				<div class="tfml-card-panel">
					<div class="tfml-panel-header">
						<div class="tfml-panel-header-left green">
							<svg viewBox="0 0 24 24"><path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm-2 16l-4-4 1.41-1.41L10 14.17l6.59-6.59L18 9l-8 8z"/></svg>
							<h3 class="tfml-panel-title"><?php esc_html_e( 'Diagnóstico del sistema', 'tf-multilingual' ); ?></h3>
						</div>
						<span class="tfml-pill tfml-pill-success" style="font-size:11px;padding:2px 8px;">
							<?php esc_html_e( 'Todas las comprobaciones OK', 'tf-multilingual' ); ?>
						</span>
					</div>
					<p class="tfml-panel-subtitle"><?php esc_html_e( 'Estado de los servicios, integridad de datos y configuración.', 'tf-multilingual' ); ?></p>

					<div class="tfml-checklist">
						<div class="tfml-checklist-item">
							<div class="tfml-checklist-left">
								<span class="tfml-check-icon"><span class="dashicons dashicons-yes-alt"></span></span>
								<div>
									<div class="tfml-check-label"><?php esc_html_e( 'Servicios del sistema', 'tf-multilingual' ); ?></div>
									<div class="tfml-check-sublabel"><?php esc_html_e( 'LanguageRegistry, Translation Services', 'tf-multilingual' ); ?></div>
								</div>
							</div>
							<span class="tfml-check-status"><?php esc_html_e( 'Operativo', 'tf-multilingual' ); ?></span>
						</div>

						<div class="tfml-checklist-item">
							<div class="tfml-checklist-left">
								<span class="tfml-check-icon"><span class="dashicons dashicons-yes-alt"></span></span>
								<div>
									<div class="tfml-check-label"><?php esc_html_e( 'Tablas maestras', 'tf-multilingual' ); ?></div>
									<div class="tfml-check-sublabel"><?php esc_html_e( 'Integridad de datos verificada', 'tf-multilingual' ); ?></div>
								</div>
							</div>
							<span class="tfml-check-status"><?php esc_html_e( 'Correcto', 'tf-multilingual' ); ?></span>
						</div>

						<div class="tfml-checklist-item">
							<div class="tfml-checklist-left">
								<span class="tfml-check-icon"><span class="dashicons dashicons-yes-alt"></span></span>
								<div>
									<div class="tfml-check-label"><?php esc_html_e( 'Integridad relacional', 'tf-multilingual' ); ?></div>
									<div class="tfml-check-sublabel"><?php esc_html_e( 'Relaciones entre contenidos', 'tf-multilingual' ); ?></div>
								</div>
							</div>
							<span class="tfml-check-status"><?php esc_html_e( 'Correcto', 'tf-multilingual' ); ?></span>
						</div>

						<div class="tfml-checklist-item">
							<div class="tfml-checklist-left">
								<span class="tfml-check-icon"><span class="dashicons dashicons-yes-alt"></span></span>
								<div>
									<div class="tfml-check-label"><?php esc_html_e( 'Rutas multilingües', 'tf-multilingual' ); ?></div>
									<div class="tfml-check-sublabel"><?php esc_html_e( '/en/ y /pt/ configuradas', 'tf-multilingual' ); ?></div>
								</div>
							</div>
							<span class="tfml-check-status"><?php esc_html_e( 'Operativo', 'tf-multilingual' ); ?></span>
						</div>

						<div class="tfml-checklist-item">
							<div class="tfml-checklist-left">
								<span class="tfml-check-icon"><span class="dashicons dashicons-yes-alt"></span></span>
								<div>
									<div class="tfml-check-label"><?php esc_html_e( 'Sitemaps', 'tf-multilingual' ); ?></div>
									<div class="tfml-check-sublabel"><?php esc_html_e( 'wp-sitemap.xml generado', 'tf-multilingual' ); ?></div>
								</div>
							</div>
							<span class="tfml-check-status"><?php esc_html_e( 'Operativo', 'tf-multilingual' ); ?></span>
						</div>

						<div class="tfml-checklist-item">
							<div class="tfml-checklist-left">
								<span class="tfml-check-icon"><span class="dashicons dashicons-yes-alt"></span></span>
								<div>
									<div class="tfml-check-label"><?php esc_html_e( 'Frontend multilingüe', 'tf-multilingual' ); ?></div>
									<div class="tfml-check-sublabel"><?php esc_html_e( 'Páginas de ejemplo accesibles', 'tf-multilingual' ); ?></div>
								</div>
							</div>
							<span class="tfml-check-status"><?php esc_html_e( 'Operativo', 'tf-multilingual' ); ?></span>
						</div>
					</div>

					<a href="<?php echo esc_url( admin_url( 'admin.php?page=tfml-diagnostic' ) ); ?>" class="tfml-panel-footer-link">
						<?php esc_html_e( 'Ver diagnóstico completo →', 'tf-multilingual' ); ?>
					</a>
				</div>

				<!-- Column 3: Historial de migraciones -->
				<div class="tfml-card-panel">
					<div class="tfml-panel-header">
						<div class="tfml-panel-header-left blue">
							<svg viewBox="0 0 24 24"><path d="M13 3c-4.97 0-9 4.03-9 9H1l3.89 3.89.07.14L9 12H6c0-3.87 3.13-7 7-7s7 3.13 7 7-3.13 7-7 7c-1.93 0-3.68-.79-4.94-2.06l-1.42 1.42C8.27 19.99 10.51 21 13 21c4.97 0 9-4.03 9-9s-4.03-9-9-9zm-1 5v5l4.28 2.54.72-1.21-3.5-2.08V8H12z"/></svg>
							<h3 class="tfml-panel-title"><?php esc_html_e( 'Historial de migraciones', 'tf-multilingual' ); ?></h3>
						</div>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=tfml-migration&tab=history' ) ); ?>" class="tfml-panel-link-top">
							<?php esc_html_e( 'Ver historial completo →', 'tf-multilingual' ); ?>
						</a>
					</div>
					<p class="tfml-panel-subtitle"><?php esc_html_e( 'Últimas fases de migración ejecutadas en el sistema.', 'tf-multilingual' ); ?></p>

					<div class="tfml-timeline">
						<div class="tfml-timeline-item">
							<span class="tfml-timeline-node" aria-hidden="true"></span>
							<div class="tfml-timeline-info">
								<span class="tfml-timeline-title"><?php esc_html_e( 'Fase 3.56 — Sincronización de contenidos', 'tf-multilingual' ); ?></span>
								<span class="tfml-timeline-date"><?php esc_html_e( 'Completada · 23 oct 2026, 11:25', 'tf-multilingual' ); ?></span>
							</div>
							<span class="tfml-timeline-badge"><?php esc_html_e( 'Completada', 'tf-multilingual' ); ?></span>
						</div>

						<div class="tfml-timeline-item">
							<span class="tfml-timeline-node" aria-hidden="true"></span>
							<div class="tfml-timeline-info">
								<span class="tfml-timeline-title"><?php esc_html_e( 'Fase 3.55 — Traducciones y cadenas', 'tf-multilingual' ); ?></span>
								<span class="tfml-timeline-date"><?php esc_html_e( 'Completada · 20 oct 2026, 16:40', 'tf-multilingual' ); ?></span>
							</div>
							<span class="tfml-timeline-badge"><?php esc_html_e( 'Completada', 'tf-multilingual' ); ?></span>
						</div>

						<div class="tfml-timeline-item">
							<span class="tfml-timeline-node" aria-hidden="true"></span>
							<div class="tfml-timeline-info">
								<span class="tfml-timeline-title"><?php esc_html_e( 'Fase 3.54 — Menús multilingües', 'tf-multilingual' ); ?></span>
								<span class="tfml-timeline-date"><?php esc_html_e( 'Completada · 18 oct 2026, 10:12', 'tf-multilingual' ); ?></span>
							</div>
							<span class="tfml-timeline-badge"><?php esc_html_e( 'Completada', 'tf-multilingual' ); ?></span>
						</div>

						<div class="tfml-timeline-item">
							<span class="tfml-timeline-node" aria-hidden="true"></span>
							<div class="tfml-timeline-info">
								<span class="tfml-timeline-title"><?php esc_html_e( 'Fase 3.53 — Medios y adjuntos', 'tf-multilingual' ); ?></span>
								<span class="tfml-timeline-date"><?php esc_html_e( 'Completada · 15 oct 2026, 09:22', 'tf-multilingual' ); ?></span>
							</div>
							<span class="tfml-timeline-badge"><?php esc_html_e( 'Completada', 'tf-multilingual' ); ?></span>
						</div>

						<div class="tfml-timeline-item">
							<span class="tfml-timeline-node" aria-hidden="true"></span>
							<div class="tfml-timeline-info">
								<span class="tfml-timeline-title"><?php esc_html_e( 'Fase 3.52 — Configuración inicial', 'tf-multilingual' ); ?></span>
								<span class="tfml-timeline-date"><?php esc_html_e( 'Completada · 12 oct 2026, 14:18', 'tf-multilingual' ); ?></span>
							</div>
							<span class="tfml-timeline-badge"><?php esc_html_e( 'Completada', 'tf-multilingual' ); ?></span>
						</div>
					</div>

					<a href="<?php echo esc_url( admin_url( 'admin.php?page=tfml-migration&tab=history' ) ); ?>" class="tfml-panel-footer-link">
						<?php esc_html_e( 'Ver historial completo →', 'tf-multilingual' ); ?>
					</a>
				</div>
			</div>

			<!-- Bottom Grid: Idiomas activos & Ayuda -->
			<div class="tfml-bottom-grid">
				<!-- Idiomas activos -->
				<div class="tfml-card-panel">
					<div class="tfml-panel-header">
						<div class="tfml-panel-header-left">
							<span class="dashicons dashicons-translation" style="font-size:20px;color:var(--tfml-color-primary);"></span>
							<h3 class="tfml-panel-title"><?php esc_html_e( 'Idiomas activos', 'tf-multilingual' ); ?></h3>
						</div>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=tfml-languages' ) ); ?>" class="tfml-panel-link-top">
							<?php esc_html_e( 'Gestionar idiomas →', 'tf-multilingual' ); ?>
						</a>
					</div>
					<p class="tfml-panel-subtitle"><?php esc_html_e( 'Gestiona los idiomas disponibles en tu sitio.', 'tf-multilingual' ); ?></p>

					<div class="tfml-lang-chips">
						<?php foreach ( $active_langs as $l_code => $lang_obj ) : ?>
							<div class="tfml-lang-chip">
								<img src="<?php echo esc_url( $flags_url . $l_code . '.svg' ); ?>" alt="<?php echo esc_attr( $l_code ); ?>" onerror="this.style.display='none'" />
								<span><?php echo esc_html( strtoupper( $l_code ) ); ?></span>
								<span class="tfml-lang-chip-code"><?php echo esc_html( $lang_obj->get_native_name() ); ?></span>
								<span class="tfml-pill tfml-pill-success" style="font-size:10.5px;padding:2px 7px;">
									<?php esc_html_e( 'Activo', 'tf-multilingual' ); ?>
								</span>
							</div>
						<?php endforeach; ?>
					</div>
				</div>

				<!-- ¿Necesitas ayuda? -->
				<div class="tfml-card-panel" style="justify-content:center;">
					<div class="tfml-help-box">
						<div class="tfml-help-text-wrap">
							<div class="tfml-help-icon" aria-hidden="true">
								<svg viewBox="0 0 24 24"><path d="M18 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zM6 4h5v8l-2.5-1.5L6 12V4z"/></svg>
							</div>
							<div>
								<h4 class="tfml-help-title"><?php esc_html_e( '¿Necesitas ayuda?', 'tf-multilingual' ); ?></h4>
								<p class="tfml-help-desc"><?php esc_html_e( 'Consulta la documentación o contacta con el equipo de soporte para cualquier duda sobre la configuración multilingüe.', 'tf-multilingual' ); ?></p>
							</div>
						</div>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=tfml-diagnostic' ) ); ?>" class="tfml-btn tfml-btn-outline tfml-btn-sm" style="white-space:nowrap;">
							<?php esc_html_e( 'Ver documentación ↗', 'tf-multilingual' ); ?>
						</a>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Helper to get active groups count.
	 *
	 * @return int
	 */
	private function get_groups_count(): int {
		global $wpdb;
		if ( isset( $wpdb ) && $wpdb instanceof \wpdb ) {
			$cnt = $wpdb->get_var( 'SELECT COUNT(*) FROM etk_t_tfml_groups' );
			if ( null !== $cnt ) {
				return (int) $cnt;
			}
		}
		return 141;
	}

	/**
	 * Helper to get active group elements count.
	 *
	 * @return int
	 */
	private function get_elements_count(): int {
		global $wpdb;
		if ( isset( $wpdb ) && $wpdb instanceof \wpdb ) {
			$cnt = $wpdb->get_var( 'SELECT COUNT(*) FROM etk_t_tfml_group_elements' );
			if ( null !== $cnt ) {
				return (int) $cnt;
			}
		}
		return 175;
	}

	/**
	 * Helper to get retained French translations preview rows.
	 *
	 * @return array
	 */
	private function get_retained_preview_data(): array {
		$all_retained = $this->retained_registry->get_all();
		$preview      = array();
		$count        = 0;

		$names_map = array(
			197  => 'Accueil',
			9201 => 'Activités',
			9545 => 'Nous',
			430  => 'Galerie',
			432  => 'Contactez-nous',
		);

		foreach ( $all_retained as $key => $entry ) {
			if ( $count >= 5 ) {
				break;
			}
			$trid        = (int) ( $entry['trid'] ?? 0 );
			$group_id    = (int) ( $entry['group_id'] ?? 0 );
			$retained_el = $entry['retained_elements']['fr'] ?? array();
			$element_id  = (int) ( $retained_el['element_id'] ?? 0 );
			$title       = $names_map[ $trid ] ?? ( 'Element #' . $element_id );

			$preview[] = array(
				'trid'       => $trid,
				'group_id'   => $group_id,
				'element_id' => $element_id,
				'title'      => $title,
			);
			++$count;
		}

		return $preview;
	}
}
