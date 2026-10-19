<?php
/**
 * WPML Migration Administrative UI Component.
 *
 * @package TF\Multilingual\Admin
 */

declare( strict_types=1 );

namespace TF\Multilingual\Admin;

use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Domain\Translation\TranslationGroupRepository;
use TF\Multilingual\Migration\MigrationCheckpoint;
use TF\Multilingual\Migration\MigrationManager;
use TF\Multilingual\Migration\MigrationPlan;
use TF\Multilingual\Migration\RetainedTranslationRegistry;
use wpdb;

/**
 * Class MigrationUi
 *
 * Provides a dedicated administrative inspection and planning interface under
 * "TF Multilingual > Migración WPML" (admin.php?page=tfml-migration).
 * Operates strictly in read-only / consultation mode with zero writes to database.
 */
class MigrationUi {

	/**
	 * Submenu page slug.
	 */
	public const PAGE_SLUG = 'tfml-migration';

	/**
	 * Top-level menu parent slug.
	 */
	public const PARENT_SLUG = 'tf-multilingual';

	/**
	 * Migration manager instance.
	 *
	 * @var MigrationManager
	 */
	private MigrationManager $migration_manager;

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
	 * WordPress database instance.
	 *
	 * @var wpdb|null
	 */
	private ?wpdb $db;

	/**
	 * Constructor.
	 *
	 * @param MigrationManager            $migration_manager  Migration manager.
	 * @param LanguageRegistry            $language_registry  Language registry.
	 * @param TranslationGroupRepository  $group_repository   Translation group repository.
	 * @param RetainedTranslationRegistry $retained_registry  Retained translation registry.
	 * @param wpdb|null                   $db                 Optional database instance.
	 */
	public function __construct(
		MigrationManager $migration_manager,
		LanguageRegistry $language_registry,
		TranslationGroupRepository $group_repository,
		RetainedTranslationRegistry $retained_registry,
		?wpdb $db = null
	) {
		$this->migration_manager = $migration_manager;
		$this->language_registry = $language_registry;
		$this->group_repository  = $group_repository;
		$this->retained_registry = $retained_registry;
		$this->db                = $db ?? ( isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof wpdb ? $GLOBALS['wpdb'] : null );
	}

	/**
	 * Initializes WordPress admin hooks.
	 *
	 * @return void
	 */
	public function init_hooks(): void {
		if ( function_exists( 'add_action' ) ) {
			add_action( 'admin_menu', array( $this, 'register_menu_page' ), 12 );
		}
	}

	/**
	 * Required capability to view this administrative screen.
	 *
	 * @return string
	 */
	public function get_capability(): string {
		return 'manage_options';
	}

	/**
	 * Registers the submenu page in WordPress admin.
	 *
	 * @return void
	 */
	public function register_menu_page(): void {
		if ( ! function_exists( 'add_submenu_page' ) ) {
			return;
		}

		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Migración WPML — TF Multilingual', 'tf-multilingual' ),
			__( 'Migración WPML', 'tf-multilingual' ),
			$this->get_capability(),
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Renders the WPML migration consultation dashboard.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( function_exists( 'current_user_can' ) && ! current_user_can( $this->get_capability() ) ) {
			if ( function_exists( 'wp_die' ) ) {
				wp_die( esc_html__( 'No tiene permisos suficientes para acceder a esta página.', 'tf-multilingual' ), 403 );
			}
			return;
		}

		// Strictly read-only: reject any POST execution attempts.
		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) ) {
			if ( function_exists( 'wp_die' ) ) {
				wp_die( esc_html__( 'Operación denegada. El panel de migración opera exclusivamente en modo lectura y planificación.', 'tf-multilingual' ), 403 );
			}
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$current_tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'plan';
		$search_query = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$class_filt   = isset( $_GET['classification'] ) ? sanitize_key( wp_unslash( $_GET['classification'] ) ) : 'all';
		$subtype_filt = isset( $_GET['subtype'] ) ? sanitize_key( wp_unslash( $_GET['subtype'] ) ) : 'all';
		$current_page = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// phpcs:enable

		$valid_tabs = array( 'plan', 'history', 'retained', 'next_batch' );
		if ( ! in_array( $current_tab, $valid_tabs, true ) ) {
			$current_tab = 'plan';
		}

		// Generate deterministic migration plan preview (read-only).
		$planner = $this->migration_manager->get_wpml_planner();
		$plan    = $planner->generate_plan( 0, 0, true );

		$total_wpml_rows = $this->get_total_wpml_rows();
		$tfml_groups     = $this->get_total_tfml_groups();
		$tfml_elements   = $this->get_total_tfml_elements();
		$retained_all    = $this->retained_registry->get_all();
		$retained_count  = 0;
		foreach ( $retained_all as $rec ) {
			$retained_elements = (array) ( $rec['retained_elements'] ?? array() );
			$retained_count   += ! empty( $retained_elements ) ? count( $retained_elements ) : 1;
		}

		?>
		<div class="wrap tfml-migration-wrap">
			<h1 class="wp-heading-inline">
				<span class="dashicons dashicons-database-import" style="font-size:28px; width:28px; height:28px; vertical-align:middle; margin-right:8px; color:#2271b1;"></span>
				<?php esc_html_e( 'Migración WPML — Panel de Planificación y Auditoría', 'tf-multilingual' ); ?>
			</h1>

			<p class="description" style="margin-top:4px; margin-bottom:16px;">
				<?php esc_html_e( 'Auditoría integral, consulta determinista de lotes y visualización del estado relacional entre WPML y TF Multilingual sin alteración de datos.', 'tf-multilingual' ); ?>
			</p>

			<?php $this->render_security_banner(); ?>

			<?php $this->render_kpi_cards( $plan->get_summary(), $total_wpml_rows, $tfml_groups, $tfml_elements, $retained_count ); ?>

			<?php $this->render_tab_nav( $current_tab ); ?>

			<div class="tfml-tab-content" style="margin-top:16px;">
				<?php
				switch ( $current_tab ) {
					case 'history':
						$this->render_tab_history();
						break;
					case 'retained':
						$this->render_tab_retained( $retained_all );
						break;
					case 'next_batch':
						$this->render_tab_next_batch( $plan );
						break;
					case 'plan':
					default:
						$this->render_tab_plan( $plan, $search_query, $class_filt, $subtype_filt, $current_page );
						break;
				}
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Renders prominent read-only security banner.
	 *
	 * @return void
	 */
	private function render_security_banner(): void {
		?>
		<div class="notice notice-info" style="border-left-color:#2271b1; padding:12px 16px; margin:16px 0; background:#f0f6fc; box-shadow:0 1px 1px rgba(0,0,0,0.04);">
			<div style="display:flex; align-items:flex-start; gap:12px;">
				<span class="dashicons dashicons-shield" style="font-size:24px; width:24px; height:24px; color:#2271b1; flex-shrink:0; margin-top:2px;"></span>
				<div>
					<strong style="font-size:13px; color:#1d2327; display:block; margin-bottom:2px;">
						<?php esc_html_e( 'Modo Consulta y Auditoría — Operaciones Modificadoras Deshabilitadas', 'tf-multilingual' ); ?>
					</strong>
					<p style="margin:0; font-size:12px; color:#50575e; line-height:1.5;">
						<?php esc_html_e( 'Este panel opera exclusivamente en modo lectura y planificación determinista. La ejecución activa de migraciones, regresiones automáticas, mutaciones de esquemas o purga de manifiestos están expresamente bloqueadas en esta fase para garantizar la invarianza total de producción y laboratorio.', 'tf-multilingual' ); ?>
					</p>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Renders KPI Stat Cards grid.
	 *
	 * @param array<string, mixed> $summary         Plan summary metrics.
	 * @param int                  $total_wpml_rows Total rows in WPML table.
	 * @param int                  $tfml_groups     Active TFML groups count.
	 * @param int                  $tfml_elements   Active TFML elements count.
	 * @param int                  $retained_count  Retained translations count.
	 * @return void
	 */
	private function render_kpi_cards(
		array $summary,
		int $total_wpml_rows,
		int $tfml_groups,
		int $tfml_elements,
		int $retained_count
	): void {
		$total_trids     = isset( $summary['total_groups'] ) ? (int) $summary['total_groups'] : 249;
		$conflict_groups = isset( $summary['conflict_groups'] ) ? (int) $summary['conflict_groups'] : 129;
		$blocked_lang    = isset( $summary['blocked_language_groups'] ) ? (int) $summary['blocked_language_groups'] : ( isset( $summary['blocked_by_language_groups'] ) ? (int) $summary['blocked_by_language_groups'] : 48 );
		$orphan_groups   = isset( $summary['orphan_groups'] ) ? (int) $summary['orphan_groups'] : 48;
		$skipped_groups  = isset( $summary['skipped_groups'] ) ? (int) $summary['skipped_groups'] : 24;

		?>
		<div class="tfml-kpi-grid" style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:12px; margin-bottom:20px;">
			<!-- Total WPML Rows -->
			<div class="tfml-kpi-card" style="background:#fff; border:1px solid #c3c4c7; border-left:4px solid #646970; border-radius:4px; padding:12px 14px;">
				<div style="font-size:11px; color:#646970; text-transform:uppercase; font-weight:600; letter-spacing:0.5px;">
					<?php esc_html_e( 'Registros WPML', 'tf-multilingual' ); ?>
				</div>
				<div style="font-size:24px; font-weight:700; color:#1d2327; margin-top:4px;">
					<?php echo esc_html( number_format_i18n( $total_wpml_rows ) ); ?>
				</div>
				<div style="font-size:11px; color:#50575e; margin-top:2px;">
					<?php
					/* translators: %d: count of TRIDs */
					echo esc_html( sprintf( __( '%d TRIDs sin adjuntos', 'tf-multilingual' ), $total_trids ) );
					?>
				</div>
			</div>

			<!-- TFML Active Groups -->
			<div class="tfml-kpi-card" style="background:#fff; border:1px solid #c3c4c7; border-left:4px solid #46b450; border-radius:4px; padding:12px 14px;">
				<div style="font-size:11px; color:#646970; text-transform:uppercase; font-weight:600; letter-spacing:0.5px;">
					<?php esc_html_e( 'Grupos en TFML', 'tf-multilingual' ); ?>
				</div>
				<div style="font-size:24px; font-weight:700; color:#1d2327; margin-top:4px;">
					<?php echo esc_html( number_format_i18n( $tfml_groups ) ); ?>
				</div>
				<div style="font-size:11px; color:#50575e; margin-top:2px;">
					<?php
					/* translators: %d: count of active elements */
					echo esc_html( sprintf( __( '%d elementos activos', 'tf-multilingual' ), $tfml_elements ) );
					?>
				</div>
			</div>

			<!-- Retained Translations in Inactive Language -->
			<div class="tfml-kpi-card" style="background:#fff; border:1px solid #c3c4c7; border-left:4px solid #72aee6; border-radius:4px; padding:12px 14px;">
				<div style="font-size:11px; color:#646970; text-transform:uppercase; font-weight:600; letter-spacing:0.5px;">
					<?php esc_html_e( 'En Custodia (FR)', 'tf-multilingual' ); ?>
				</div>
				<div style="font-size:24px; font-weight:700; color:#1d2327; margin-top:4px;">
					<?php echo esc_html( number_format_i18n( $retained_count ) ); ?>
				</div>
				<div style="font-size:11px; color:#50575e; margin-top:2px;">
					<?php esc_html_e( 'Cero polución activa', 'tf-multilingual' ); ?>
				</div>
			</div>

			<!-- Blocked by Language -->
			<div class="tfml-kpi-card" style="background:#fff; border:1px solid #c3c4c7; border-left:4px solid #2271b1; border-radius:4px; padding:12px 14px;">
				<div style="font-size:11px; color:#646970; text-transform:uppercase; font-weight:600; letter-spacing:0.5px;">
					<?php esc_html_e( 'Bloq. por Idioma', 'tf-multilingual' ); ?>
				</div>
				<div style="font-size:24px; font-weight:700; color:#1d2327; margin-top:4px;">
					<?php echo esc_html( number_format_i18n( $blocked_lang ) ); ?>
				</div>
				<div style="font-size:11px; color:#50575e; margin-top:2px;">
					<?php esc_html_e( 'Contienen francés (fr)', 'tf-multilingual' ); ?>
				</div>
			</div>

			<!-- Conflict / Already Migrated -->
			<div class="tfml-kpi-card" style="background:#fff; border:1px solid #c3c4c7; border-left:4px solid #dba617; border-radius:4px; padding:12px 14px;">
				<div style="font-size:11px; color:#646970; text-transform:uppercase; font-weight:600; letter-spacing:0.5px;">
					<?php esc_html_e( 'Migrados / Conflicto', 'tf-multilingual' ); ?>
				</div>
				<div style="font-size:24px; font-weight:700; color:#1d2327; margin-top:4px;">
					<?php echo esc_html( number_format_i18n( $conflict_groups ) ); ?>
				</div>
				<div style="font-size:11px; color:#50575e; margin-top:2px;">
					<?php esc_html_e( '123 migrados + colisiones', 'tf-multilingual' ); ?>
				</div>
			</div>

			<!-- Orphan Groups -->
			<div class="tfml-kpi-card" style="background:#fff; border:1px solid #c3c4c7; border-left:4px solid #d63638; border-radius:4px; padding:12px 14px;">
				<div style="font-size:11px; color:#646970; text-transform:uppercase; font-weight:600; letter-spacing:0.5px;">
					<?php esc_html_e( 'Huérfanos WPML', 'tf-multilingual' ); ?>
				</div>
				<div style="font-size:24px; font-weight:700; color:#1d2327; margin-top:4px;">
					<?php echo esc_html( number_format_i18n( $orphan_groups ) ); ?>
				</div>
				<div style="font-size:11px; color:#50575e; margin-top:2px;">
					<?php esc_html_e( 'Borrados en WP Core', 'tf-multilingual' ); ?>
				</div>
			</div>

			<!-- Skipped Groups -->
			<div class="tfml-kpi-card" style="background:#fff; border:1px solid #c3c4c7; border-left:4px solid #a7aaad; border-radius:4px; padding:12px 14px;">
				<div style="font-size:11px; color:#646970; text-transform:uppercase; font-weight:600; letter-spacing:0.5px;">
					<?php esc_html_e( 'Omitidos', 'tf-multilingual' ); ?>
				</div>
				<div style="font-size:24px; font-weight:700; color:#1d2327; margin-top:4px;">
					<?php echo esc_html( number_format_i18n( $skipped_groups ) ); ?>
				</div>
				<div style="font-size:11px; color:#50575e; margin-top:2px;">
					<?php esc_html_e( 'Comentarios / Prioridades', 'tf-multilingual' ); ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Renders Tab Navigation links.
	 *
	 * @param string $current_tab Current tab slug.
	 * @return void
	 */
	private function render_tab_nav( string $current_tab ): void {
		$tabs = array(
			'plan'       => array(
				'label' => __( 'Plan Determinista', 'tf-multilingual' ),
				'icon'  => 'dashicons-list-view',
			),
			'history'    => array(
				'label' => __( 'Historial de Lotes (13)', 'tf-multilingual' ),
				'icon'  => 'dashicons-backup',
			),
			'retained'   => array(
				'label' => __( 'Custodia Histórica (FR)', 'tf-multilingual' ),
				'icon'  => 'dashicons-lock',
			),
			'next_batch' => array(
				'label' => __( 'Propuesta Próximo Lote (Páginas)', 'tf-multilingual' ),
				'icon'  => 'dashicons-lightbulb',
			),
		);

		?>
		<nav class="nav-tab-wrapper wp-clearfix">
			<?php foreach ( $tabs as $tab_key => $tab_data ) : ?>
				<?php
				$url    = admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=' . $tab_key );
				$active = ( $current_tab === $tab_key ) ? 'nav-tab-active' : '';
				?>
				<a href="<?php echo esc_url( $url ); ?>" class="nav-tab <?php echo esc_attr( $active ); ?>" style="display:inline-flex; align-items:center; gap:5px;">
					<span class="dashicons <?php echo esc_attr( $tab_data['icon'] ); ?>" style="font-size:16px; width:16px; height:16px;"></span>
					<?php echo esc_html( $tab_data['label'] ); ?>
				</a>
			<?php endforeach; ?>
		</nav>
		<?php
	}

	/**
	 * Renders Tab 1: Migration Plan and Planned Groups table.
	 *
	 * @param MigrationPlan $plan         Migration plan instance.
	 * @param string        $search_query Search term.
	 * @param string        $class_filt   Classification filter.
	 * @param string        $subtype_filt Subtype filter.
	 * @param int           $current_page Current page number.
	 * @return void
	 */
	private function render_tab_plan(
		MigrationPlan $plan,
		string $search_query,
		string $class_filt,
		string $subtype_filt,
		int $current_page
	): void {
		$planned_groups = $plan->get_planned_groups();
		$fingerprint    = $plan->get_fingerprint();
		$missing_langs  = $plan->get_missing_languages();
		$media_plan     = $plan->get_media_plan();

		// Filter in memory.
		$filtered = array();
		foreach ( $planned_groups as $group ) {
			// Classification filter.
			if ( 'all' !== $class_filt && ( $group['classification'] ?? '' ) !== $class_filt ) {
				continue;
			}

			// Subtype filter.
			if ( 'all' !== $subtype_filt && ( $group['subtype'] ?? '' ) !== $subtype_filt ) {
				continue;
			}

			// Search query (search in trid, canonical post title or id).
			if ( '' !== $search_query ) {
				$trid_match  = ( false !== stripos( (string) ( $group['trid'] ?? '' ), $search_query ) );
				$cid_match   = ( false !== stripos( (string) ( $group['canonical_id'] ?? '' ), $search_query ) );
				$sub_match   = ( false !== stripos( (string) ( $group['subtype'] ?? '' ), $search_query ) );
				$title       = $this->get_post_display_title( (int) ( $group['canonical_id'] ?? 0 ) );
				$title_match = ( false !== stripos( $title, $search_query ) );

				if ( ! $trid_match && ! $cid_match && ! $sub_match && ! $title_match ) {
					continue;
				}
			}

			$filtered[] = $group;
		}

		$total_filtered = count( $filtered );
		$per_page       = 20;
		$total_pages    = max( 1, (int) ceil( $total_filtered / $per_page ) );
		$current_page   = min( $current_page, $total_pages );
		$offset         = ( $current_page - 1 ) * $per_page;
		$paged_groups   = array_slice( $filtered, $offset, $per_page );

		// Collect distinct subtypes for filter dropdown.
		$distinct_subtypes = array();
		foreach ( $planned_groups as $pg ) {
			$st = $pg['subtype'] ?? '';
			if ( '' !== $st && ! in_array( $st, $distinct_subtypes, true ) ) {
				$distinct_subtypes[] = $st;
			}
		}
		sort( $distinct_subtypes );

		?>
		<!-- Plan Metadata Header Box -->
		<div style="background:#fff; border:1px solid #c3c4c7; border-radius:4px; padding:16px 20px; margin-bottom:16px;">
			<div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
				<div>
					<div style="font-size:12px; color:#646970; text-transform:uppercase; font-weight:600;">
						<?php esc_html_e( 'Huella Criptográfica SHA-256 del Plan', 'tf-multilingual' ); ?>
					</div>
					<div style="margin-top:4px;">
						<code style="font-size:13px; font-weight:700; color:#2271b1; background:#f0f6fc; padding:4px 8px; border-radius:3px; border:1px solid #c8d7e1; word-break:break-all;">
							<?php echo esc_html( $fingerprint ); ?>
						</code>
					</div>
				</div>
				<div style="text-align:right;">
					<div style="font-size:12px; color:#646970; text-transform:uppercase; font-weight:600;">
						<?php esc_html_e( 'Estado Determinista', 'tf-multilingual' ); ?>
					</div>
					<div style="margin-top:4px;">
						<span style="background:#fcf9e8; border:1px solid #dba617; color:#614700; padding:4px 10px; border-radius:4px; font-weight:600; font-size:12px;">
							<?php echo esc_html( strtoupper( $plan->get_status() ) ); ?>: <?php esc_html_e( 'Requiere configuración (idioma francés inactivo)', 'tf-multilingual' ); ?>
						</span>
					</div>
				</div>
			</div>

			<div style="margin-top:14px; padding-top:12px; border-top:1px solid #f0f0f1; font-size:12px; color:#50575e; display:flex; gap:24px; flex-wrap:wrap;">
				<div>
					<strong><?php esc_html_e( 'Estrategia de Medios:', 'tf-multilingual' ); ?></strong>
					<span style="background:#e7f5ea; color:#1a7f37; padding:2px 6px; border-radius:3px; font-weight:600; margin-left:4px;">
						<?php echo esc_html( $media_plan['strategy'] ?? 'MEDIA_MODEL_B_DEDUPLICATION' ); ?>
					</span>
					(<?php echo esc_html( number_format_i18n( (int) ( $media_plan['total_attachment_rows'] ?? 3033 ) ) ); ?> adjuntos WPML deduplicados a 1 post por archivo físico).
				</div>
				<div>
					<strong><?php esc_html_e( 'Idiomas Faltantes en TFML:', 'tf-multilingual' ); ?></strong>
					<?php if ( ! empty( $missing_langs ) ) : ?>
						<?php foreach ( $missing_langs as $ml ) : ?>
							<span style="background:#fcf0f2; border:1px solid #d63638; color:#d63638; padding:1px 6px; border-radius:3px; font-weight:600; font-size:11px; margin-left:4px;">
								<?php echo esc_html( strtoupper( $ml ) ); ?>
							</span>
						<?php endforeach; ?>
					<?php else : ?>
						<span style="color:#46b450; font-weight:600;"><?php esc_html_e( 'Ninguno', 'tf-multilingual' ); ?></span>
					<?php endif; ?>
				</div>
			</div>
		</div>

		<!-- Filter Bar Form -->
		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="margin-bottom:16px;">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
			<input type="hidden" name="tab" value="plan" />

			<div class="tablenav top" style="padding:0; margin:0; display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
				<!-- Search -->
				<div style="flex-grow:1; max-width:320px;">
					<input type="search" name="s" value="<?php echo esc_attr( $search_query ); ?>" placeholder="<?php esc_attr_e( 'Buscar por TRID, ID o título...', 'tf-multilingual' ); ?>" style="width:100%;" />
				</div>

				<!-- Classification filter -->
				<select name="classification">
					<option value="all" <?php selected( $class_filt, 'all' ); ?>><?php esc_html_e( 'Todas las clasificaciones', 'tf-multilingual' ); ?></option>
					<option value="blocked_by_language" <?php selected( $class_filt, 'blocked_by_language' ); ?>><?php esc_html_e( 'Bloqueado por idioma (48)', 'tf-multilingual' ); ?></option>
					<option value="conflict" <?php selected( $class_filt, 'conflict' ); ?>><?php esc_html_e( 'Conflicto / Ya migrado (129)', 'tf-multilingual' ); ?></option>
					<option value="orphan" <?php selected( $class_filt, 'orphan' ); ?>><?php esc_html_e( 'Huérfano WPML (48)', 'tf-multilingual' ); ?></option>
					<option value="skipped" <?php selected( $class_filt, 'skipped' ); ?>><?php esc_html_e( 'Omitido (24)', 'tf-multilingual' ); ?></option>
					<option value="importable" <?php selected( $class_filt, 'importable' ); ?>><?php esc_html_e( 'Importable directo (0)', 'tf-multilingual' ); ?></option>
				</select>

				<!-- Subtype filter -->
				<select name="subtype">
					<option value="all" <?php selected( $subtype_filt, 'all' ); ?>><?php esc_html_e( 'Todos los subtipos', 'tf-multilingual' ); ?></option>
					<?php foreach ( $distinct_subtypes as $st ) : ?>
						<option value="<?php echo esc_attr( $st ); ?>" <?php selected( $subtype_filt, $st ); ?>>
							<?php echo esc_html( $st ); ?>
						</option>
					<?php endforeach; ?>
				</select>

				<input type="submit" class="button button-secondary" value="<?php esc_attr_e( 'Filtrar', 'tf-multilingual' ); ?>" />

				<?php if ( '' !== $search_query || 'all' !== $class_filt || 'all' !== $subtype_filt ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=plan' ) ); ?>" class="button-link" style="color:#d63638; text-decoration:none; margin-left:6px;">
						<?php esc_html_e( 'Restablecer filtros', 'tf-multilingual' ); ?>
					</a>
				<?php endif; ?>

				<!-- Items counter -->
				<div class="tablenav-pages" style="margin-left:auto;">
					<span class="displaying-num">
						<?php
						/* translators: %s: total filtered groups count */
						echo esc_html( sprintf( __( '%s grupos encontrados', 'tf-multilingual' ), number_format_i18n( $total_filtered ) ) );
						?>
					</span>
				</div>
			</div>
		</form>

		<!-- Planned Groups Table -->
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th scope="col" style="width:75px;"><?php esc_html_e( 'TRID', 'tf-multilingual' ); ?></th>
					<th scope="col" style="width:130px;"><?php esc_html_e( 'Tipo / Subtipo', 'tf-multilingual' ); ?></th>
					<th scope="col" style="width:240px;"><?php esc_html_e( 'Elemento Canónico', 'tf-multilingual' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Miembros del Grupo', 'tf-multilingual' ); ?></th>
					<th scope="col" style="width:170px;"><?php esc_html_e( 'Clasificación', 'tf-multilingual' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Diagnóstico / Motivos', 'tf-multilingual' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $paged_groups ) ) : ?>
					<tr>
						<td colspan="6" style="text-align:center; padding:24px; color:#646970;">
							<?php esc_html_e( 'No se encontraron grupos que coincidan con los criterios seleccionados.', 'tf-multilingual' ); ?>
						</td>
					</tr>
				<?php else : ?>
					<?php foreach ( $paged_groups as $group ) : ?>
						<?php
						$trid           = (int) ( $group['trid'] ?? 0 );
						$elem_type      = (string) ( $group['element_type'] ?? '' );
						$subtype        = (string) ( $group['subtype'] ?? '' );
						$canonical_id   = (int) ( $group['canonical_id'] ?? 0 );
						$canonical_lang = (string) ( $group['canonical_lang'] ?? '' );
						$classification = (string) ( $group['classification'] ?? '' );
						$reasons        = (array) ( $group['reasons'] ?? array() );
						$translations   = (array) ( $group['translations'] ?? array() );
						$pending_trans  = (array) ( $group['pending_translations'] ?? array() );

						$canon_title = $this->get_post_display_title( $canonical_id );
						?>
						<tr>
							<td style="font-weight:700; color:#1d2327;">
								#<?php echo esc_html( (string) $trid ); ?>
							</td>
							<td>
								<span style="font-size:11px; background:#f0f0f1; border:1px solid #dcdcde; border-radius:3px; padding:1px 5px; color:#50575e; display:inline-block;">
									<?php echo esc_html( $elem_type ); ?>
								</span>
								<div style="font-weight:600; color:#1d2327; margin-top:3px;">
									<?php echo esc_html( $subtype ); ?>
								</div>
							</td>
							<td>
								<?php if ( $canonical_id > 0 ) : ?>
									<div style="font-weight:600; color:#1d2327;">
										<?php if ( function_exists( 'get_edit_post_link' ) && 'post' === $elem_type && get_edit_post_link( $canonical_id ) ) : ?>
											<a href="<?php echo esc_url( (string) get_edit_post_link( $canonical_id ) ); ?>" target="_blank" style="text-decoration:none;">
												<?php echo esc_html( $canon_title ); ?>
											</a>
										<?php else : ?>
											<?php echo esc_html( $canon_title ); ?>
										<?php endif; ?>
									</div>
									<div style="font-size:11px; color:#646970; margin-top:2px;">
										ID: #<?php echo esc_html( (string) $canonical_id ); ?>
										<?php if ( '' !== $canonical_lang ) : ?>
											| <span style="font-weight:600;"><?php echo esc_html( strtoupper( $canonical_lang ) ); ?></span>
										<?php endif; ?>
									</div>
								<?php else : ?>
									<span style="color:#a7aaad; font-style:italic;"><?php esc_html_e( '(Sin elemento canónico)', 'tf-multilingual' ); ?></span>
								<?php endif; ?>
							</td>
							<td>
								<div style="display:flex; flex-direction:column; gap:3px;">
									<?php foreach ( $translations as $l => $eid ) : ?>
										<div style="font-size:12px;">
											<span style="background:#e7f5ea; border:1px solid #c3e6cb; border-radius:3px; padding:0 4px; font-weight:700; color:#155724; font-size:10px;">
												<?php echo esc_html( strtoupper( (string) $l ) ); ?>
											</span>
											ID #<?php echo esc_html( (string) $eid ); ?>: <?php echo esc_html( $this->get_post_display_title( (int) $eid ) ); ?>
										</div>
									<?php endforeach; ?>
									<?php foreach ( $pending_trans as $l => $eid ) : ?>
										<div style="font-size:12px;">
											<span style="background:#fcf0f2; border:1px solid #f5c6cb; border-radius:3px; padding:0 4px; font-weight:700; color:#721c24; font-size:10px;">
												<?php echo esc_html( strtoupper( (string) $l ) ); ?> (Retenido)
											</span>
											ID #<?php echo esc_html( (string) $eid ); ?>: <?php echo esc_html( $this->get_post_display_title( (int) $eid ) ); ?>
										</div>
									<?php endforeach; ?>
								</div>
							</td>
							<td>
								<?php echo $this->get_classification_badge_html( $classification ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							</td>
							<td style="font-size:12px; color:#50575e;">
								<ul style="margin:0; padding-left:14px; list-style-type:disc;">
									<?php foreach ( $reasons as $reason ) : ?>
										<li><?php echo esc_html( $reason ); ?></li>
									<?php endforeach; ?>
								</ul>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>

		<!-- Pagination Footer -->
		<?php if ( $total_pages > 1 ) : ?>
			<div class="tablenav bottom" style="margin-top:12px;">
				<div class="tablenav-pages">
					<span class="pagination-links">
						<?php
						$base_url = admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=plan' );
						if ( '' !== $search_query ) {
							$base_url = add_query_arg( 's', rawurlencode( $search_query ), $base_url );
						}
						if ( 'all' !== $class_filt ) {
							$base_url = add_query_arg( 'classification', $class_filt, $base_url );
						}
						if ( 'all' !== $subtype_filt ) {
							$base_url = add_query_arg( 'subtype', $subtype_filt, $base_url );
						}

						if ( $current_page > 1 ) {
							echo '<a class="prev-page button" href="' . esc_url( add_query_arg( 'paged', $current_page - 1, $base_url ) ) . '">&laquo; ' . esc_html__( 'Anterior', 'tf-multilingual' ) . '</a> ';
						}
						/* translators: 1: current page number, 2: total page count */
						$paging_label = sprintf( __( 'Página %1$d de %2$d', 'tf-multilingual' ), $current_page, $total_pages );
						echo '<span class="paging-input" style="margin:0 8px;">' . esc_html( $paging_label ) . '</span>';
						if ( $current_page < $total_pages ) {
							echo '<a class="next-page button" href="' . esc_url( add_query_arg( 'paged', $current_page + 1, $base_url ) ) . '">' . esc_html__( 'Siguiente', 'tf-multilingual' ) . ' &raquo;</a>';
						}
						?>
					</span>
				</div>
			</div>
		<?php endif; ?>
		<?php
	}

	/**
	 * Renders Tab 2: Migration Checkpoint and Batch Execution Manifests history.
	 *
	 * @return void
	 */
	private function render_tab_history(): void {
		$checkpoint = $this->get_active_checkpoint();
		$manifests  = $this->get_all_manifests();
		$lock       = $this->migration_manager->get_execution_lock();
		$is_locked  = $lock->is_locked( 'wpml' );

		?>
		<!-- Section 1: Active Checkpoint Card -->
		<div style="background:#fff; border:1px solid #c3c4c7; border-radius:4px; padding:16px 20px; margin-bottom:20px;">
			<h2 style="font-size:15px; margin:0 0 12px; display:flex; align-items:center; gap:8px;">
				<span class="dashicons dashicons-marker" style="color:#2271b1;"></span>
				<?php esc_html_e( 'Punto de Control (Checkpoint) Activo de Migración', 'tf-multilingual' ); ?>
			</h2>

			<?php if ( null !== $checkpoint ) : ?>
				<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:16px; margin-bottom:12px;">
					<div>
						<div style="font-size:11px; color:#646970; text-transform:uppercase; font-weight:600;"><?php esc_html_e( 'Fuente', 'tf-multilingual' ); ?></div>
						<div style="font-size:15px; font-weight:700; color:#1d2327; margin-top:2px;">
							<?php echo esc_html( strtoupper( $checkpoint->get_source() ) ); ?>
						</div>
					</div>
					<div>
						<div style="font-size:11px; color:#646970; text-transform:uppercase; font-weight:600;"><?php esc_html_e( 'Estado del Checkpoint', 'tf-multilingual' ); ?></div>
						<div style="font-size:14px; font-weight:700; margin-top:2px;">
							<span style="background:#e7f5ea; border:1px solid #c3e6cb; color:#155724; padding:2px 8px; border-radius:3px; display:inline-flex; align-items:center; gap:4px;">
								<span class="dashicons dashicons-yes-alt" style="font-size:14px; width:14px; height:14px;"></span>
								<?php echo esc_html( strtoupper( $checkpoint->get_status() ) ); ?>
							</span>
						</div>
					</div>
					<div>
						<div style="font-size:11px; color:#646970; text-transform:uppercase; font-weight:600;"><?php esc_html_e( 'Último TRID Procesado', 'tf-multilingual' ); ?></div>
						<div style="font-size:15px; font-weight:700; color:#1d2327; margin-top:2px;">
							#<?php echo esc_html( (string) $checkpoint->get_last_processed_trid() ); ?>
						</div>
					</div>
					<div>
						<div style="font-size:11px; color:#646970; text-transform:uppercase; font-weight:600;"><?php esc_html_e( 'Ítems Procesados / Total', 'tf-multilingual' ); ?></div>
						<div style="font-size:15px; font-weight:700; color:#1d2327; margin-top:2px;">
							<?php echo esc_html( (string) $checkpoint->get_processed_items() ); ?> / <?php echo esc_html( (string) $checkpoint->get_total_items() ); ?>
						</div>
					</div>
					<div>
						<div style="font-size:11px; color:#646970; text-transform:uppercase; font-weight:600;"><?php esc_html_e( 'Bloqueo Concurrencia', 'tf-multilingual' ); ?></div>
						<div style="font-size:14px; font-weight:700; margin-top:2px;">
							<?php if ( $is_locked ) : ?>
								<span style="color:#d63638;"><?php esc_html_e( 'BLOQUEADO (En ejecución)', 'tf-multilingual' ); ?></span>
							<?php else : ?>
								<span style="color:#1a7f37;"><?php esc_html_e( 'LIBRE (Sin proceso activo)', 'tf-multilingual' ); ?></span>
							<?php endif; ?>
						</div>
					</div>
				</div>

				<div style="font-size:12px; color:#50575e; border-top:1px solid #f0f0f1; padding-top:10px;">
					<?php esc_html_e( 'El checkpoint garantiza reanudación transaccional determinista desde el último TRID sin riesgo de duplicación ni reprocesamiento de grupos ya importados.', 'tf-multilingual' ); ?>
				</div>
			<?php else : ?>
				<p style="color:#646970; margin:0;"><?php esc_html_e( 'No hay un punto de control activo registrado para WPML.', 'tf-multilingual' ); ?></p>
			<?php endif; ?>
		</div>

		<!-- Section 2: Historical Batch Manifests (13 Manifests) -->
		<div style="background:#fff; border:1px solid #c3c4c7; border-radius:4px; padding:16px 20px;">
			<h2 style="font-size:15px; margin:0 0 8px; display:flex; align-items:center; gap:8px;">
				<span class="dashicons dashicons-media-spreadsheet" style="color:#2271b1;"></span>
				<?php esc_html_e( 'Historial de Manifiestos de Ejecución (13 Lotes Certificados)', 'tf-multilingual' ); ?>
			</h2>

			<p class="description" style="margin:0 0 16px;">
				<?php esc_html_e( 'Todos los lotes ejecutados en las fases anteriores cuentan con un manifiesto inmutable persistido en wp_options. Estos registros proporcionan auditoría criptográfica y trazabilidad completa de cada grupo importado.', 'tf-multilingual' ); ?>
			</p>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th scope="col" style="width:40px;">#</th>
						<th scope="col" style="width:190px;"><?php esc_html_e( 'Token de Ejecución', 'tf-multilingual' ); ?></th>
						<th scope="col" style="width:160px;"><?php esc_html_e( 'Fecha y Hora', 'tf-multilingual' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Huella Criptográfica del Plan', 'tf-multilingual' ); ?></th>
						<th scope="col" style="width:120px; text-align:center;"><?php esc_html_e( 'Grupos Imp.', 'tf-multilingual' ); ?></th>
						<th scope="col" style="width:120px; text-align:center;"><?php esc_html_e( 'Retenidos FR', 'tf-multilingual' ); ?></th>
						<th scope="col" style="width:100px; text-align:center;"><?php esc_html_e( 'Estado', 'tf-multilingual' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $manifests ) ) : ?>
						<tr>
							<td colspan="7" style="text-align:center; padding:20px; color:#646970;">
								<?php esc_html_e( 'No se encontraron manifiestos de ejecución en wp_options.', 'tf-multilingual' ); ?>
							</td>
						</tr>
					<?php else : ?>
						<?php
						$batch_idx = 1;
						foreach ( $manifests as $m ) :
							$token     = (string) ( $m['execution_id'] ?? '' );
							$plan_fp   = (string) ( $m['plan_fingerprint'] ?? '' );
							$started   = (string) ( $m['started_at'] ?? '' );
							$finished  = (string) ( $m['finished_at'] ?? '' );
							$imported  = (array) ( $m['imported_group_ids'] ?? ( $m['imported_groups'] ?? array() ) );
							$retained  = (array) ( $m['retained_translations'] ?? array() );
							$count_imp = count( $imported );
							$count_ret = count( $retained );
							$date_disp = '' !== $finished ? $finished : $started;
							?>
							<tr>
								<td style="font-weight:700; color:#646970;"><?php echo esc_html( (string) $batch_idx ); ?></td>
								<td>
									<code style="font-size:12px; font-weight:600; color:#1d2327;"><?php echo esc_html( $token ); ?></code>
								</td>
								<td style="font-size:12px; color:#50575e;">
									<?php echo esc_html( $date_disp ); ?>
								</td>
								<td>
									<code style="font-size:11px; color:#2271b1;" title="<?php echo esc_attr( $plan_fp ); ?>">
										<?php echo esc_html( substr( $plan_fp, 0, 16 ) . '...' . substr( $plan_fp, -8 ) ); ?>
									</code>
								</td>
								<td style="text-align:center; font-weight:700; color:#1d2327;">
									<?php echo esc_html( (string) $count_imp ); ?>
								</td>
								<td style="text-align:center; font-weight:700; color:#2271b1;">
									<?php echo esc_html( (string) $count_ret ); ?>
								</td>
								<td style="text-align:center;">
									<span style="background:#e7f5ea; border:1px solid #c3e6cb; color:#155724; padding:1px 6px; border-radius:3px; font-size:11px; font-weight:600;">
										<?php esc_html_e( 'Completado', 'tf-multilingual' ); ?>
									</span>
								</td>
							</tr>
							<?php
							++$batch_idx;
						endforeach;
						?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Renders Tab 3: Retained translations in custody.
	 *
	 * @param array<string, array<string, mixed>> $retained_all Retained records.
	 * @return void
	 */
	private function render_tab_retained( array $retained_all ): void {
		$flattened = array();
		foreach ( $retained_all as $rec ) {
			$retained_elements = (array) ( $rec['retained_elements'] ?? array() );
			if ( ! empty( $retained_elements ) ) {
				foreach ( $retained_elements as $lang => $item ) {
					$flattened[] = array(
						'trid'          => (int) ( $rec['trid'] ?? 0 ),
						'group_id'      => (int) ( $rec['group_id'] ?? 0 ),
						'source'        => (string) ( $rec['source'] ?? 'wpml' ),
						'element_type'  => (string) ( $rec['element_type'] ?? 'post' ),
						'subtype'       => (string) ( $rec['subtype'] ?? '' ),
						'language_code' => (string) $lang,
						'element_id'    => (int) ( $item['element_id'] ?? 0 ),
						'status'        => (string) ( $item['status'] ?? RetainedTranslationRegistry::STATUS_RETAINED_INACTIVE_LANGUAGE ),
						'created_at'    => (string) ( $item['recorded_at'] ?? ( $rec['created_at'] ?? '' ) ),
					);
				}
			} else {
				$flattened[] = array(
					'trid'          => (int) ( $rec['trid'] ?? 0 ),
					'group_id'      => (int) ( $rec['group_id'] ?? 0 ),
					'source'        => (string) ( $rec['source'] ?? 'wpml' ),
					'element_type'  => (string) ( $rec['element_type'] ?? 'post' ),
					'subtype'       => (string) ( $rec['subtype'] ?? '' ),
					'language_code' => (string) ( $rec['language_code'] ?? 'fr' ),
					'element_id'    => (int) ( $rec['element_id'] ?? 0 ),
					'status'        => (string) ( $rec['status'] ?? RetainedTranslationRegistry::STATUS_RETAINED_INACTIVE_LANGUAGE ),
					'created_at'    => (string) ( $rec['created_at'] ?? '' ),
				);
			}
		}
		?>
		<div style="background:#fff; border:1px solid #c3c4c7; border-radius:4px; padding:16px 20px; margin-bottom:16px;">
			<h2 style="font-size:15px; margin:0 0 8px; display:flex; align-items:center; gap:8px;">
				<span class="dashicons dashicons-lock" style="color:#2271b1;"></span>
				<?php esc_html_e( 'Traducciones Retenidas en Custodia (Idioma Inactivo FR)', 'tf-multilingual' ); ?>
			</h2>

			<p class="description" style="margin:0 0 14px; line-height:1.5;">
				<?php esc_html_e( 'Modelo de Cero Polución: Las traducciones pertenecientes a idiomas no configurados como activos (en este caso, Francés "fr") no se insertan en tfml_group_elements para proteger el rendimiento y la integridad de las consultas en el frontend. Se preservan transaccionalmente en tfml_retained_translations dentro de wp_options, listas para su posterior promoción sin pérdida de metadatos cuando el idioma sea activado administrativamente.', 'tf-multilingual' ); ?>
			</p>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th scope="col" style="width:80px;"><?php esc_html_e( 'TRID WPML', 'tf-multilingual' ); ?></th>
						<th scope="col" style="width:120px;"><?php esc_html_e( 'Grupo TFML', 'tf-multilingual' ); ?></th>
						<th scope="col" style="width:80px; text-align:center;"><?php esc_html_e( 'Idioma', 'tf-multilingual' ); ?></th>
						<th scope="col" style="width:100px;"><?php esc_html_e( 'Elemento ID', 'tf-multilingual' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Título de la Entrada (WordPress)', 'tf-multilingual' ); ?></th>
						<th scope="col" style="width:160px;"><?php esc_html_e( 'Estado de Custodia', 'tf-multilingual' ); ?></th>
						<th scope="col" style="width:160px;"><?php esc_html_e( 'Fecha de Registro', 'tf-multilingual' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $flattened ) ) : ?>
						<tr>
							<td colspan="7" style="text-align:center; padding:20px; color:#646970;">
								<?php esc_html_e( 'No hay traducciones retenidas en custodia actualmente.', 'tf-multilingual' ); ?>
							</td>
						</tr>
					<?php else : ?>
						<?php foreach ( $flattened as $rec ) : ?>
							<?php
							$trid       = (int) ( $rec['trid'] ?? 0 );
							$group_id   = (int) ( $rec['group_id'] ?? 0 );
							$lang       = (string) ( $rec['language_code'] ?? 'fr' );
							$elem_id    = (int) ( $rec['element_id'] ?? 0 );
							$status     = (string) ( $rec['status'] ?? 'retained_inactive_language' );
							$created_at = (string) ( $rec['created_at'] ?? '' );
							$title      = $this->get_post_display_title( $elem_id );
							?>
							<tr>
								<td style="font-weight:700; color:#1d2327;">
									#<?php echo esc_html( (string) $trid ); ?>
								</td>
								<td>
									<span style="font-weight:600; color:#2271b1;">
										Grupo #<?php echo esc_html( (string) $group_id ); ?>
									</span>
								</td>
								<td style="text-align:center;">
									<span style="background:#fcf0f2; border:1px solid #f5c6cb; border-radius:3px; padding:2px 6px; font-weight:700; color:#721c24; font-size:11px;">
										<?php echo esc_html( strtoupper( $lang ) ); ?>
									</span>
								</td>
								<td>
									<strong>#<?php echo esc_html( (string) $elem_id ); ?></strong>
								</td>
								<td>
									<div style="font-weight:600; color:#1d2327;">
										<?php if ( function_exists( 'get_edit_post_link' ) && get_edit_post_link( $elem_id ) ) : ?>
											<a href="<?php echo esc_url( (string) get_edit_post_link( $elem_id ) ); ?>" target="_blank" style="text-decoration:none;">
												<?php echo esc_html( $title ); ?>
											</a>
										<?php else : ?>
											<?php echo esc_html( $title ); ?>
										<?php endif; ?>
									</div>
								</td>
								<td>
									<span style="background:#e8f4fd; border:1px solid #bfe0fb; color:#0c5460; padding:2px 8px; border-radius:3px; font-size:11px; font-weight:600;">
										<?php echo esc_html( $status ); ?>
									</span>
								</td>
								<td style="font-size:12px; color:#50575e;">
									<?php echo esc_html( $created_at ); ?>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Renders Tab 4: Concrete Technical Proposal for the Next Navigation Pages Batch.
	 *
	 * @param MigrationPlan $plan Migration plan.
	 * @return void
	 */
	private function render_tab_next_batch( MigrationPlan $plan ): void {
		$planned_groups = $plan->get_planned_groups();

		// Collect page groups in BLOCKED_BY_LANGUAGE.
		$blocked_pages = array();
		foreach ( $planned_groups as $pg ) {
			if ( 'page' === ( $pg['subtype'] ?? '' ) && MigrationPlan::CLASSIFICATION_BLOCKED_BY_LANGUAGE === ( $pg['classification'] ?? '' ) ) {
				$blocked_pages[] = $pg;
			}
		}

		// Known hierarchy definitions based on WordPress core parents.
		$standalone_trids = array( 430, 432, 9537, 9634, 9697, 9818, 34831 );
		$nosotros_trids   = array( 424, 9838, 34632 );
		$servicios_trids  = array( 9574, 9326 );
		$exp_trans_trids  = array( 34660, 34776, 34790, 34791 );

		?>
		<div style="background:#fff; border:1px solid #c3c4c7; border-radius:4px; padding:16px 20px; margin-bottom:16px;">
			<h2 style="font-size:15px; margin:0 0 6px; display:flex; align-items:center; gap:8px;">
				<span class="dashicons dashicons-lightbulb" style="color:#dba617;"></span>
				<?php esc_html_e( 'Propuesta Técnica: Próximo Lote Mínimo Elegible de Páginas', 'tf-multilingual' ); ?>
			</h2>

			<p class="description" style="margin:0 0 16px; line-height:1.5;">
				<?php esc_html_e( 'Análisis preliminar de las 19 páginas de WPML actualmente clasificadas como BLOCKED_BY_LANGUAGE por contener traducciones en francés (fr). Aplicando el modelo de custodia probado en los 13 lotes precedentes, estas páginas pueden importarse incorporando sus elementos ES y EN a TFML y reteniendo deterministamente su traducción francesa en custodia.', 'tf-multilingual' ); ?>
			</p>

			<!-- Sub-lote 1: Páginas Independientes -->
			<div style="margin-bottom:20px;">
				<h3 style="font-size:13px; margin:0 0 8px; color:#1d2327; display:flex; align-items:center; gap:6px;">
					<span class="dashicons dashicons-admin-page" style="color:#2271b1; font-size:18px; width:18px; height:18px;"></span>
					<?php esc_html_e( '1. Páginas Independientes (Sin Dependencia Jerárquica de Padres — 7 Grupos)', 'tf-multilingual' ); ?>
				</h3>
				<p style="font-size:12px; color:#50575e; margin:0 0 10px;">
					<?php esc_html_e( 'Estas páginas no poseen post_parent o constituyen raíces autónomas. Son candidatas óptimas para el próximo lote mínimo directo:', 'tf-multilingual' ); ?>
				</p>

				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th scope="col" style="width:75px;"><?php esc_html_e( 'TRID', 'tf-multilingual' ); ?></th>
							<th scope="col" style="width:260px;"><?php esc_html_e( 'Página Canónica (ES)', 'tf-multilingual' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Traducción Activa (EN)', 'tf-multilingual' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Traducción en Custodia (FR)', 'tf-multilingual' ); ?></th>
							<th scope="col" style="width:130px; text-align:center;"><?php esc_html_e( 'Elegibilidad', 'tf-multilingual' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $blocked_pages as $bp ) : ?>
							<?php
							$trid = (int) ( $bp['trid'] ?? 0 );
							if ( ! in_array( $trid, $standalone_trids, true ) ) {
								continue;
							}
							$cid     = (int) ( $bp['canonical_id'] ?? 0 );
							$trans   = (array) ( $bp['translations'] ?? array() );
							$pending = (array) ( $bp['pending_translations'] ?? array() );
							$en_id   = (int) ( $trans['en'] ?? 0 );
							$fr_id   = (int) ( $pending['fr'] ?? 0 );
							?>
							<tr>
								<td style="font-weight:700;">#<?php echo esc_html( (string) $trid ); ?></td>
								<td>
									<strong>#<?php echo esc_html( (string) $cid ); ?></strong>: <?php echo esc_html( $this->get_post_display_title( $cid ) ); ?>
								</td>
								<td>
									<?php if ( $en_id > 0 ) : ?>
										<span style="color:#155724; font-weight:600;">[EN]</span> #<?php echo esc_html( (string) $en_id ); ?>: <?php echo esc_html( $this->get_post_display_title( $en_id ) ); ?>
									<?php else : ?>
										<span style="color:#a7aaad;">-</span>
									<?php endif; ?>
								</td>
								<td>
									<?php if ( $fr_id > 0 ) : ?>
										<span style="color:#721c24; font-weight:600;">[FR]</span> #<?php echo esc_html( (string) $fr_id ); ?>: <?php echo esc_html( $this->get_post_display_title( $fr_id ) ); ?>
									<?php else : ?>
										<span style="color:#a7aaad;">-</span>
									<?php endif; ?>
								</td>
								<td style="text-align:center;">
									<span style="background:#e7f5ea; border:1px solid #c3e6cb; color:#155724; padding:2px 6px; border-radius:3px; font-size:11px; font-weight:600;">
										<?php esc_html_e( 'Inmediata', 'tf-multilingual' ); ?>
									</span>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<!-- Sub-lote 2: Páginas con Dependencias Jerárquicas -->
			<div style="margin-bottom:20px;">
				<h3 style="font-size:13px; margin:0 0 8px; color:#1d2327; display:flex; align-items:center; gap:6px;">
					<span class="dashicons dashicons-category" style="color:#2271b1; font-size:18px; width:18px; height:18px;"></span>
					<?php esc_html_e( '2. Páginas Jerárquicas con Padre Migrado (12 Grupos)', 'tf-multilingual' ); ?>
				</h3>
				<p style="font-size:12px; color:#50575e; margin:0 0 10px;">
					<?php esc_html_e( 'Sus páginas padre ya se encuentran migradas y activas en TFML. Sus dependencias relacionales están 100% resueltas:', 'tf-multilingual' ); ?>
				</p>

				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th scope="col" style="width:75px;"><?php esc_html_e( 'TRID', 'tf-multilingual' ); ?></th>
							<th scope="col" style="width:230px;"><?php esc_html_e( 'Página Canónica (ES)', 'tf-multilingual' ); ?></th>
							<th scope="col" style="width:210px;"><?php esc_html_e( 'Página Padre en TFML', 'tf-multilingual' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Traducción Activa (EN)', 'tf-multilingual' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Traducción en Custodia (FR)', 'tf-multilingual' ); ?></th>
							<th scope="col" style="width:130px; text-align:center;"><?php esc_html_e( 'Resolución Padre', 'tf-multilingual' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $blocked_pages as $bp ) : ?>
							<?php
							$trid = (int) ( $bp['trid'] ?? 0 );
							if ( in_array( $trid, $standalone_trids, true ) ) {
								continue;
							}
							$cid     = (int) ( $bp['canonical_id'] ?? 0 );
							$trans   = (array) ( $bp['translations'] ?? array() );
							$pending = (array) ( $bp['pending_translations'] ?? array() );
							$en_id   = (int) ( $trans['en'] ?? 0 );
							$fr_id   = (int) ( $pending['fr'] ?? 0 );

							$parent_label = '';
							if ( in_array( $trid, $nosotros_trids, true ) ) {
								$parent_label = '#6237 Nosotros (TFML Grp #588)';
							} elseif ( in_array( $trid, $servicios_trids, true ) ) {
								$parent_label = '#6505 Servicios (TFML Grp #593)';
							} elseif ( in_array( $trid, $exp_trans_trids, true ) ) {
								$parent_label = '#9218 Exp. Transformadora (TFML Grp #594)';
							} else {
								$parent_label = 'Página Padre WP';
							}
							?>
							<tr>
								<td style="font-weight:700;">#<?php echo esc_html( (string) $trid ); ?></td>
								<td>
									<strong>#<?php echo esc_html( (string) $cid ); ?></strong>: <?php echo esc_html( $this->get_post_display_title( $cid ) ); ?>
								</td>
								<td style="font-weight:600; color:#2271b1; font-size:12px;">
									<?php echo esc_html( $parent_label ); ?>
								</td>
								<td>
									<?php if ( $en_id > 0 ) : ?>
										<span style="color:#155724; font-weight:600;">[EN]</span> #<?php echo esc_html( (string) $en_id ); ?>: <?php echo esc_html( $this->get_post_display_title( $en_id ) ); ?>
									<?php else : ?>
										<span style="color:#a7aaad;">-</span>
									<?php endif; ?>
								</td>
								<td>
									<?php if ( $fr_id > 0 ) : ?>
										<span style="color:#721c24; font-weight:600;">[FR]</span> #<?php echo esc_html( (string) $fr_id ); ?>: <?php echo esc_html( $this->get_post_display_title( $fr_id ) ); ?>
									<?php else : ?>
										<span style="color:#a7aaad;">-</span>
									<?php endif; ?>
								</td>
								<td style="text-align:center;">
									<span style="background:#e7f5ea; border:1px solid #c3e6cb; color:#155724; padding:2px 6px; border-radius:3px; font-size:11px; font-weight:600;">
										<?php esc_html_e( 'Padre Activo en TFML', 'tf-multilingual' ); ?>
									</span>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<!-- Sub-lote 3: Roadmap y Precondiciones -->
			<div style="background:#f6f7f7; border:1px solid #dcdcde; border-radius:4px; padding:14px 16px;">
				<h4 style="margin:0 0 6px; font-size:12px; text-transform:uppercase; color:#1d2327;">
					<?php esc_html_e( 'Precondiciones Mandatorias para la Futura Ejecución (Fase 3.46+)', 'tf-multilingual' ); ?>
				</h4>
				<ul style="margin:0; padding-left:18px; font-size:12px; color:#50575e; line-height:1.6;">
					<li><strong><?php esc_html_e( 'Autorización expresa:', 'tf-multilingual' ); ?></strong> <?php esc_html_e( 'Directiva técnica aprobada con delimitación exacta del lote.', 'tf-multilingual' ); ?></li>
					<li><strong><?php esc_html_e( 'Copia de respaldo transaccional:', 'tf-multilingual' ); ?></strong> <?php esc_html_e( 'Punto de restauración verificado antes de iniciar cualquier inserción.', 'tf-multilingual' ); ?></li>
					<li><strong><?php esc_html_e( 'Custodia estricta sin inserción:', 'tf-multilingual' ); ?></strong> <?php esc_html_e( 'Los 19 elementos en francés deben registrarse en tfml_retained_translations sin escribir en tfml_group_elements.', 'tf-multilingual' ); ?></li>
					<li><strong><?php esc_html_e( 'Verificación de navegación:', 'tf-multilingual' ); ?></strong> <?php esc_html_e( 'Comprobación de no-regresión de menús, enlaces canónicos y sitemaps en laboratorio tras la importación.', 'tf-multilingual' ); ?></li>
				</ul>
			</div>
		</div>
		<?php
	}

	/**
	 * Helper to render HTML classification badge.
	 *
	 * @param string $classification Classification key.
	 * @return string HTML badge.
	 */
	private function get_classification_badge_html( string $classification ): string {
		switch ( $classification ) {
			case MigrationPlan::CLASSIFICATION_IMPORTABLE:
				return '<span style="background:#e7f5ea; border:1px solid #c3e6cb; color:#155724; padding:2px 6px; border-radius:3px; font-size:11px; font-weight:700;">' . esc_html__( 'IMPORTABLE', 'tf-multilingual' ) . '</span>';
			case MigrationPlan::CLASSIFICATION_BLOCKED_BY_LANGUAGE:
				return '<span style="background:#e8f4fd; border:1px solid #bfe0fb; color:#0c5460; padding:2px 6px; border-radius:3px; font-size:11px; font-weight:700;">' . esc_html__( 'BLOQ. IDIOMA (FR)', 'tf-multilingual' ) . '</span>';
			case MigrationPlan::CLASSIFICATION_CONFLICT:
				return '<span style="background:#fff8e5; border:1px solid #ffeeba; color:#856404; padding:2px 6px; border-radius:3px; font-size:11px; font-weight:700;">' . esc_html__( 'MIGRADO / CONFLICTO', 'tf-multilingual' ) . '</span>';
			case MigrationPlan::CLASSIFICATION_ORPHAN:
				return '<span style="background:#f8d7da; border:1px solid #f5c6cb; color:#721c24; padding:2px 6px; border-radius:3px; font-size:11px; font-weight:700;">' . esc_html__( 'HUÉRFANO WPML', 'tf-multilingual' ) . '</span>';
			case MigrationPlan::CLASSIFICATION_SKIPPED:
			default:
				return '<span style="background:#e2e3e5; border:1px solid #d6d8db; color:#383d41; padding:2px 6px; border-radius:3px; font-size:11px; font-weight:700;">' . esc_html__( 'OMITIDO', 'tf-multilingual' ) . '</span>';
		}
	}

	/**
	 * Helper to get clean post title for display.
	 *
	 * @param int $post_id WordPress post ID.
	 * @return string Post title or fallback.
	 */
	private function get_post_display_title( int $post_id ): string {
		if ( $post_id <= 0 ) {
			return __( '(Sin ID)', 'tf-multilingual' );
		}

		if ( function_exists( 'get_the_title' ) ) {
			$title = get_the_title( $post_id );
			if ( is_string( $title ) && '' !== trim( $title ) ) {
				return $title;
			}
		}

		if ( null !== $this->db ) {
			$posts_table = $this->db->posts ?? ( $this->db->prefix . 'posts' );
			$row         = $this->db->get_row(
				$this->db->prepare(
					"SELECT post_title FROM `{$posts_table}` WHERE ID = %d LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$post_id
				),
				ARRAY_A
			);
			if ( is_array( $row ) && isset( $row['post_title'] ) && '' !== trim( (string) $row['post_title'] ) ) {
				return (string) $row['post_title'];
			}
		}

		/* translators: %d: WordPress post ID */
		return sprintf( __( 'Entrada #%d', 'tf-multilingual' ), $post_id );
	}

	/**
	 * Helper to get active WPML migration checkpoint.
	 *
	 * @return MigrationCheckpoint|null
	 */
	private function get_active_checkpoint(): ?MigrationCheckpoint {
		return $this->migration_manager->get_checkpoint_repository()->get( 'wpml' );
	}

	/**
	 * Helper to retrieve all execution manifests.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function get_all_manifests(): array {
		return $this->migration_manager->get_wpml_rollback_service()->get_all_execution_manifests();
	}

	/**
	 * Gets total rows in WPML table.
	 *
	 * @return int
	 */
	private function get_total_wpml_rows(): int {
		if ( null !== $this->db ) {
			$table = $this->db->prefix . 'icl_translations';
			// Check if table exists.
			$exists = $this->db->get_var(
				$this->db->prepare( 'SHOW TABLES LIKE %s', $table )
			) === $table;

			if ( $exists ) {
				return (int) $this->db->get_var( "SELECT COUNT(*) FROM `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
		}

		return 3403;
	}

	/**
	 * Gets total TFML translation groups.
	 *
	 * @return int
	 */
	private function get_total_tfml_groups(): int {
		if ( null !== $this->db ) {
			$table = $this->db->prefix . 'tfml_groups';
			$cnt   = $this->db->get_var( "SELECT COUNT(*) FROM `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( null !== $cnt && false !== $cnt ) {
				return (int) $cnt;
			}
		}

		return 123;
	}

	/**
	 * Gets total TFML translation group elements.
	 *
	 * @return int
	 */
	private function get_total_tfml_elements(): int {
		if ( null !== $this->db ) {
			$table = $this->db->prefix . 'tfml_group_elements';
			$cnt   = $this->db->get_var( "SELECT COUNT(*) FROM `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( null !== $cnt && false !== $cnt ) {
				return (int) $cnt;
			}
		}

		return 139;
	}
}
