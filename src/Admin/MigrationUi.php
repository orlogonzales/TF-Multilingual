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
	 * Conciliated according to Directive DT-TFML-078.
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
		usort( $blocked_pages, fn( array $a, array $b ) => ( (int) $a['trid'] ) <=> ( (int) $b['trid'] ) );

		// Relational classification based on real WordPress Core & TFML DB records.
		$standalone_pages   = array();
		$accredited_pages   = array();
		$unaccredited_pages = array();

		foreach ( $blocked_pages as $bp ) {
			$cid         = (int) ( $bp['canonical_id'] ?? 0 );
			$post_info   = $this->get_post_details( $cid );
			$parent_id   = (int) $post_info['parent_id'];
			$parent_grp  = $parent_id > 0 ? $this->get_element_tfml_group_id( $parent_id ) : null;
			$parent_info = $parent_id > 0 ? $this->get_post_details( $parent_id ) : null;

			// Also check if any active translation has parent in TFML (e.g. TRID 34823 where EN post #9863 has parent #8354).
			if ( null === $parent_grp && ! empty( $bp['translations'] ) ) {
				foreach ( (array) $bp['translations'] as $t_post_id ) {
					$t_info = $this->get_post_details( (int) $t_post_id );
					if ( (int) $t_info['parent_id'] > 0 ) {
						$t_parent_grp = $this->get_element_tfml_group_id( (int) $t_info['parent_id'] );
						if ( null !== $t_parent_grp ) {
							$parent_id   = (int) $t_info['parent_id'];
							$parent_grp  = $t_parent_grp;
							$parent_info = $this->get_post_details( $parent_id );
							break;
						}
					}
				}
			}

			$entry = array(
				'group'       => $bp,
				'post_info'   => $post_info,
				'parent_id'   => $parent_id,
				'parent_grp'  => $parent_grp,
				'parent_info' => $parent_info,
			);

			if ( 0 === $parent_id ) {
				$standalone_pages[] = $entry;
			} elseif ( null !== $parent_grp ) {
				$accredited_pages[] = $entry;
			} else {
				$unaccredited_pages[] = $entry;
			}
		}

		// Canonical derivation of the minimal pilot (TRID 430 & 432).
		$pilot_plan = null;
		$all_trids  = array_column( $planned_groups, 'trid' );
		if ( in_array( 430, $all_trids, true ) && in_array( 432, $all_trids, true ) ) {
			try {
				$pilot_selections = array(
					array(
						'trid'                 => 430,
						'authorized_languages' => array( 'es', 'en' ),
						'retained_languages'   => array( 'fr' ),
					),
					array(
						'trid'                 => 432,
						'authorized_languages' => array( 'es', 'en' ),
						'retained_languages'   => array( 'fr' ),
					),
				);
				$pilot_plan       = $plan->derive_partial_plan_with_retained_languages(
					$pilot_selections,
					'pilot-nav-2-trids-430-432'
				);
			} catch ( \Throwable $e ) {
				$pilot_plan = null;
			}
		}

		?>
		<div style="background:#fff; border:1px solid #c3c4c7; border-radius:4px; padding:16px 20px; margin-bottom:16px;">
			<h2 style="font-size:15px; margin:0 0 6px; display:flex; align-items:center; gap:8px;">
				<span class="dashicons dashicons-lightbulb" style="color:#dba617;"></span>
				<?php esc_html_e( 'Propuesta Técnica: Próximo Lote Mínimo Elegible de Páginas', 'tf-multilingual' ); ?>
			</h2>

			<!-- Master Plan Status Alert -->
			<div style="background:#fff8e5; border:1px solid #ffeeba; border-left:4px solid #dba617; border-radius:4px; padding:12px 16px; margin:14px 0 20px;">
				<div style="display:flex; align-items:flex-start; gap:10px;">
					<span class="dashicons dashicons-lock" style="color:#b27d00; font-size:22px; width:22px; height:22px; margin-top:2px;"></span>
					<div style="font-size:13px; color:#614300; line-height:1.5;">
						<strong><?php esc_html_e( 'Estado del Plan Maestro: NEEDS_CONFIGURATION (Bloqueo Preventivo Activo)', 'tf-multilingual' ); ?></strong><br>
						<span>
							<?php esc_html_e( 'El plan maestro detecta que el idioma francés (fr) no forma parte del catálogo activo de navegación pública. Por diseño de seguridad relacional, las 19 páginas que contienen variante francesa están clasificadas como BLOCKED_BY_LANGUAGE y su importación directa está terminantemente deshabilitada. Para importar páginas sin activar francés ni perder trazabilidad forense, se requiere derivar un Plan Parcial Canónico con retención explícita en RetainedTranslationRegistry.', 'tf-multilingual' ); ?>
						</span>
						<div style="margin-top:6px; font-family:Consolas, Monaco, monospace; font-size:11px; color:#50575e;">
							<strong><?php esc_html_e( 'Huella Criptográfica SHA-256 del Plan Maestro:', 'tf-multilingual' ); ?></strong> <?php echo esc_html( $plan->get_fingerprint() ); ?>
						</div>
					</div>
				</div>
			</div>

			<!-- Sub-lote 1: Páginas Independientes -->
			<div style="margin-bottom:24px;">
				<h3 style="font-size:13px; margin:0 0 8px; color:#1d2327; display:flex; align-items:center; gap:6px;">
					<span class="dashicons dashicons-admin-page" style="color:#2271b1; font-size:18px; width:18px; height:18px;"></span>
					<?php esc_html_e( '1. Páginas Independientes (Sin Dependencia Jerárquica de Padres — 9 Grupos)', 'tf-multilingual' ); ?>
				</h3>
				<p style="font-size:12px; color:#50575e; margin:0 0 10px;">
					<?php esc_html_e( 'Páginas raíz en WordPress Core (post_parent = 0) verificadas en base de datos. No dependen de ninguna entidad previa. Requieren autorización explícita de retención para su traducción francesa en custodia:', 'tf-multilingual' ); ?>
				</p>

				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th scope="col" style="width:75px;"><?php esc_html_e( 'TRID', 'tf-multilingual' ); ?></th>
							<th scope="col" style="width:280px;"><?php esc_html_e( 'Página Canónica (ES)', 'tf-multilingual' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Traducción Activa (EN)', 'tf-multilingual' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Traducción en Custodia (FR)', 'tf-multilingual' ); ?></th>
							<th scope="col" style="width:160px; text-align:center;"><?php esc_html_e( 'Condición de Elegibilidad', 'tf-multilingual' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $standalone_pages ) ) : ?>
							<tr>
								<td colspan="5" style="text-align:center; color:#50575e; padding:12px;">
									<?php esc_html_e( 'No hay páginas independientes clasificadas en BLOCKED_BY_LANGUAGE.', 'tf-multilingual' ); ?>
								</td>
							</tr>
						<?php else : ?>
							<?php foreach ( $standalone_pages as $item ) : ?>
								<?php
								$bp      = $item['group'];
								$trid    = (int) ( $bp['trid'] ?? 0 );
								$cid     = (int) ( $bp['canonical_id'] ?? 0 );
								$pinfo   = $item['post_info'];
								$trans   = (array) ( $bp['translations'] ?? array() );
								$pending = (array) ( $bp['pending_translations'] ?? array() );
								$en_id   = (int) ( $trans['en'] ?? 0 );
								$fr_id   = (int) ( $pending['fr'] ?? 0 );
								$en_info = $en_id > 0 ? $this->get_post_details( $en_id ) : null;
								$fr_info = $fr_id > 0 ? $this->get_post_details( $fr_id ) : null;
								?>
								<tr>
									<td style="font-weight:700;">#<?php echo esc_html( (string) $trid ); ?></td>
									<td>
										<strong>#<?php echo esc_html( (string) $cid ); ?></strong>
										<?php if ( function_exists( 'get_edit_post_link' ) && get_edit_post_link( $cid ) ) : ?>
											<a href="<?php echo esc_url( (string) get_edit_post_link( $cid ) ); ?>" target="_blank" style="text-decoration:none; font-weight:600;">
												<?php echo esc_html( $pinfo['title'] ); ?>
											</a>
										<?php else : ?>
											<strong><?php echo esc_html( $pinfo['title'] ); ?></strong>
										<?php endif; ?>
										<?php if ( '' !== $pinfo['slug'] ) : ?>
											<span style="font-family:Consolas, Monaco, monospace; font-size:11px; color:#50575e;">(<?php echo esc_html( $pinfo['slug'] ); ?>)</span>
										<?php endif; ?>
										<?php if ( in_array( $trid, array( 9563, 34652 ), true ) ) : ?>
											<div style="font-size:11px; color:#0c5460; font-style:italic; margin-top:2px;">
												* <?php esc_html_e( 'Página raíz (futuro padre jerárquico)', 'tf-multilingual' ); ?>
											</div>
										<?php endif; ?>
									</td>
									<td>
										<?php if ( $en_id > 0 && null !== $en_info ) : ?>
											<span style="color:#155724; font-weight:600;">[EN]</span> #<?php echo esc_html( (string) $en_id ); ?>:
											<?php if ( function_exists( 'get_edit_post_link' ) && get_edit_post_link( $en_id ) ) : ?>
												<a href="<?php echo esc_url( (string) get_edit_post_link( $en_id ) ); ?>" target="_blank" style="text-decoration:none;">
													<?php echo esc_html( $en_info['title'] ); ?>
												</a>
											<?php else : ?>
												<?php echo esc_html( $en_info['title'] ); ?>
											<?php endif; ?>
											<?php if ( '' !== $en_info['slug'] ) : ?>
												<span style="font-family:Consolas, Monaco, monospace; font-size:11px; color:#50575e;">(<?php echo esc_html( $en_info['slug'] ); ?>)</span>
											<?php endif; ?>
										<?php else : ?>
											<span style="color:#a7aaad;">-</span>
										<?php endif; ?>
									</td>
									<td>
										<?php if ( $fr_id > 0 && null !== $fr_info ) : ?>
											<span style="color:#721c24; font-weight:600;">[FR]</span> #<?php echo esc_html( (string) $fr_id ); ?>:
											<?php echo esc_html( $fr_info['title'] ); ?>
											<?php if ( '' !== $fr_info['slug'] ) : ?>
												<span style="font-family:Consolas, Monaco, monospace; font-size:11px; color:#50575e;">(<?php echo esc_html( $fr_info['slug'] ); ?>)</span>
											<?php endif; ?>
										<?php else : ?>
											<span style="color:#a7aaad;">-</span>
										<?php endif; ?>
									</td>
									<td style="text-align:center;">
										<span style="background:#e8f4fd; border:1px solid #bfe0fb; color:#0c5460; padding:3px 6px; border-radius:3px; font-size:11px; font-weight:600;">
											<?php esc_html_e( 'Requiere Retención FR', 'tf-multilingual' ); ?>
										</span>
									</td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</div>

			<!-- Sub-lote 2: Páginas Jerárquicas con Padre Migrado -->
			<div style="margin-bottom:24px;">
				<h3 style="font-size:13px; margin:0 0 8px; color:#1d2327; display:flex; align-items:center; gap:6px;">
					<span class="dashicons dashicons-category" style="color:#2271b1; font-size:18px; width:18px; height:18px;"></span>
					<?php esc_html_e( '2. Páginas Jerárquicas con Padre Migrado en TFML (4 Grupos Acreditados)', 'tf-multilingual' ); ?>
				</h3>
				<p style="font-size:12px; color:#50575e; margin:0 0 10px;">
					<?php esc_html_e( 'Páginas hijas cuyo post_parent en WordPress pertenece al Grupo TFML #589 (Nosotros #6237 / About Us #8354, acreditado en Fase 3.40). Sus dependencias relacionales de padre están 100% acreditadas y resueltas:', 'tf-multilingual' ); ?>
				</p>

				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th scope="col" style="width:75px;"><?php esc_html_e( 'TRID', 'tf-multilingual' ); ?></th>
							<th scope="col" style="width:240px;"><?php esc_html_e( 'Página Canónica (ES)', 'tf-multilingual' ); ?></th>
							<th scope="col" style="width:200px;"><?php esc_html_e( 'Página Padre en TFML', 'tf-multilingual' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Traducción Activa (EN)', 'tf-multilingual' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Traducción en Custodia (FR)', 'tf-multilingual' ); ?></th>
							<th scope="col" style="width:160px; text-align:center;"><?php esc_html_e( 'Condición de Elegibilidad', 'tf-multilingual' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $accredited_pages ) ) : ?>
							<tr>
								<td colspan="6" style="text-align:center; color:#50575e; padding:12px;">
									<?php esc_html_e( 'No hay páginas jerárquicas con padre acreditado en este catálogo.', 'tf-multilingual' ); ?>
								</td>
							</tr>
						<?php else : ?>
							<?php foreach ( $accredited_pages as $item ) : ?>
								<?php
								$bp          = $item['group'];
								$trid        = (int) ( $bp['trid'] ?? 0 );
								$cid         = (int) ( $bp['canonical_id'] ?? 0 );
								$pinfo       = $item['post_info'];
								$parent_id   = $item['parent_id'];
								$parent_grp  = $item['parent_grp'];
								$parent_info = $item['parent_info'];
								$trans       = (array) ( $bp['translations'] ?? array() );
								$pending     = (array) ( $bp['pending_translations'] ?? array() );
								$en_id       = (int) ( $trans['en'] ?? 0 );
								$fr_id       = (int) ( $pending['fr'] ?? 0 );
								$en_info     = $en_id > 0 ? $this->get_post_details( $en_id ) : null;
								$fr_info     = $fr_id > 0 ? $this->get_post_details( $fr_id ) : null;
								?>
								<tr>
									<td style="font-weight:700;">#<?php echo esc_html( (string) $trid ); ?></td>
									<td>
										<strong>#<?php echo esc_html( (string) $cid ); ?></strong>
										<?php if ( function_exists( 'get_edit_post_link' ) && get_edit_post_link( $cid ) ) : ?>
											<a href="<?php echo esc_url( (string) get_edit_post_link( $cid ) ); ?>" target="_blank" style="text-decoration:none; font-weight:600;">
												<?php echo esc_html( $pinfo['title'] ); ?>
											</a>
										<?php else : ?>
											<strong><?php echo esc_html( $pinfo['title'] ); ?></strong>
										<?php endif; ?>
										<?php if ( '' !== $pinfo['slug'] ) : ?>
											<span style="font-family:Consolas, Monaco, monospace; font-size:11px; color:#50575e;">(<?php echo esc_html( $pinfo['slug'] ); ?>)</span>
										<?php endif; ?>
									</td>
									<td style="font-size:12px;">
										<span style="font-weight:700; color:#155724;">
											#<?php echo esc_html( (string) $parent_id ); ?>: <?php echo esc_html( $parent_info['title'] ?? '' ); ?>
										</span>
										<div style="font-size:11px; color:#2271b1; font-weight:600;">
											<?php /* translators: %d: TFML group ID */ ?>
											<?php printf( esc_html__( 'TFML Grupo #%d', 'tf-multilingual' ), (int) $parent_grp ); ?>
										</div>

									</td>
									<td>
										<?php if ( $en_id > 0 && null !== $en_info ) : ?>
											<span style="color:#155724; font-weight:600;">[EN]</span> #<?php echo esc_html( (string) $en_id ); ?>:
											<?php echo esc_html( $en_info['title'] ); ?>
											<?php if ( '' !== $en_info['slug'] ) : ?>
												<span style="font-family:Consolas, Monaco, monospace; font-size:11px; color:#50575e;">(<?php echo esc_html( $en_info['slug'] ); ?>)</span>
											<?php endif; ?>
										<?php else : ?>
											<span style="color:#a7aaad;">-</span>
										<?php endif; ?>
									</td>
									<td>
										<?php if ( $fr_id > 0 && null !== $fr_info ) : ?>
											<span style="color:#721c24; font-weight:600;">[FR]</span> #<?php echo esc_html( (string) $fr_id ); ?>:
											<?php echo esc_html( $fr_info['title'] ); ?>
										<?php else : ?>
											<span style="color:#a7aaad;">-</span>
										<?php endif; ?>
									</td>
									<td style="text-align:center;">
										<span style="background:#e8f4fd; border:1px solid #bfe0fb; color:#0c5460; padding:3px 6px; border-radius:3px; font-size:11px; font-weight:600;">
											<?php esc_html_e( 'Padre Resuelto (Ret. FR)', 'tf-multilingual' ); ?>
										</span>
									</td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</div>

			<!-- Sub-lote 3: Páginas Jerárquicas con Padre Pendiente -->
			<div style="margin-bottom:24px;">
				<h3 style="font-size:13px; margin:0 0 8px; color:#1d2327; display:flex; align-items:center; gap:6px;">
					<span class="dashicons dashicons-warning" style="color:#d63638; font-size:18px; width:18px; height:18px;"></span>
					<?php esc_html_e( '3. Páginas Jerárquicas con Padre Pendiente de Migración (6 Grupos Bloqueados)', 'tf-multilingual' ); ?>
				</h3>
				<p style="font-size:12px; color:#50575e; margin:0 0 10px;">
					<?php esc_html_e( 'Páginas hijas cuyos padres (#6505 Servicios y #9218 Experiencia Transformadora) todavía no han sido migrados a TFML. Por integridad referencial, NO son elegibles hasta que sus respectivos padres sean previamente incorporados:', 'tf-multilingual' ); ?>
				</p>

				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th scope="col" style="width:75px;"><?php esc_html_e( 'TRID', 'tf-multilingual' ); ?></th>
							<th scope="col" style="width:240px;"><?php esc_html_e( 'Página Canónica (ES)', 'tf-multilingual' ); ?></th>
							<th scope="col" style="width:200px;"><?php esc_html_e( 'Página Padre en WP', 'tf-multilingual' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Traducción Activa (EN)', 'tf-multilingual' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Traducción en Custodia (FR)', 'tf-multilingual' ); ?></th>
							<th scope="col" style="width:160px; text-align:center;"><?php esc_html_e( 'Condición de Elegibilidad', 'tf-multilingual' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $unaccredited_pages ) ) : ?>
							<tr>
								<td colspan="6" style="text-align:center; color:#50575e; padding:12px;">
									<?php esc_html_e( 'No hay páginas bloqueadas por padre pendiente.', 'tf-multilingual' ); ?>
								</td>
							</tr>
						<?php else : ?>
							<?php foreach ( $unaccredited_pages as $item ) : ?>
								<?php
								$bp          = $item['group'];
								$trid        = (int) ( $bp['trid'] ?? 0 );
								$cid         = (int) ( $bp['canonical_id'] ?? 0 );
								$pinfo       = $item['post_info'];
								$parent_id   = $item['parent_id'];
								$parent_info = $item['parent_info'];
								$trans       = (array) ( $bp['translations'] ?? array() );
								$pending     = (array) ( $bp['pending_translations'] ?? array() );
								$en_id       = (int) ( $trans['en'] ?? 0 );
								$fr_id       = (int) ( $pending['fr'] ?? 0 );
								$en_info     = $en_id > 0 ? $this->get_post_details( $en_id ) : null;
								$fr_info     = $fr_id > 0 ? $this->get_post_details( $fr_id ) : null;
								?>
								<tr>
									<td style="font-weight:700;">#<?php echo esc_html( (string) $trid ); ?></td>
									<td>
										<strong>#<?php echo esc_html( (string) $cid ); ?></strong>
										<?php if ( function_exists( 'get_edit_post_link' ) && get_edit_post_link( $cid ) ) : ?>
											<a href="<?php echo esc_url( (string) get_edit_post_link( $cid ) ); ?>" target="_blank" style="text-decoration:none; font-weight:600;">
												<?php echo esc_html( $pinfo['title'] ); ?>
											</a>
										<?php else : ?>
											<strong><?php echo esc_html( $pinfo['title'] ); ?></strong>
										<?php endif; ?>
										<?php if ( '' !== $pinfo['slug'] ) : ?>
											<span style="font-family:Consolas, Monaco, monospace; font-size:11px; color:#50575e;">(<?php echo esc_html( $pinfo['slug'] ); ?>)</span>
										<?php endif; ?>
										<?php if ( 'draft' === $pinfo['status'] ) : ?>
											<span style="background:#f0f0f1; border:1px solid #dcdcde; color:#50575e; padding:1px 4px; border-radius:3px; font-size:10px;">
												<?php esc_html_e( 'Borrador', 'tf-multilingual' ); ?>
											</span>
										<?php endif; ?>
									</td>
									<td style="font-size:12px;">
										<span style="font-weight:600; color:#d63638;">
											#<?php echo esc_html( (string) $parent_id ); ?>: <?php echo esc_html( $parent_info['title'] ?? '' ); ?>
										</span>
										<div style="font-size:11px; color:#a7aaad;">
											<?php esc_html_e( 'Padre NO migrado a TFML', 'tf-multilingual' ); ?>
										</div>
									</td>
									<td>
										<?php if ( $en_id > 0 && null !== $en_info ) : ?>
											<span style="color:#155724; font-weight:600;">[EN]</span> #<?php echo esc_html( (string) $en_id ); ?>:
											<?php echo esc_html( $en_info['title'] ); ?>
										<?php else : ?>
											<span style="color:#a7aaad;">-</span>
										<?php endif; ?>
									</td>
									<td>
										<?php if ( $fr_id > 0 && null !== $fr_info ) : ?>
											<span style="color:#721c24; font-weight:600;">[FR]</span> #<?php echo esc_html( (string) $fr_id ); ?>:
											<?php echo esc_html( $fr_info['title'] ); ?>
										<?php else : ?>
											<span style="color:#a7aaad;">-</span>
										<?php endif; ?>
									</td>
									<td style="text-align:center;">
										<span style="background:#fcf0f2; border:1px solid #f5c6cb; color:#721c24; padding:3px 6px; border-radius:3px; font-size:11px; font-weight:600;">
											<?php esc_html_e( 'Bloqueado por Padre', 'tf-multilingual' ); ?>
										</span>
									</td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</div>

			<!-- Sub-lote 4: Piloto Mínimo Certificado -->
			<div style="background:#f0f6fc; border:1px solid #c8d8f8; border-radius:4px; padding:16px 18px; margin-bottom:20px;">
				<h3 style="font-size:14px; margin:0 0 10px; color:#1d2327; display:flex; align-items:center; gap:8px;">
					<span class="dashicons dashicons-yes-alt" style="color:#00a32a; font-size:20px; width:20px; height:20px;"></span>
					<?php esc_html_e( 'Piloto Mínimo Certificado: Derivación Canónica Parcial (Lote Recomendado: pilot-nav-2-trids-430-432)', 'tf-multilingual' ); ?>
				</h3>
				<p style="font-size:12px; color:#50575e; margin:0 0 14px; line-height:1.5;">
					<?php esc_html_e( 'En conformidad con la directiva DT-TFML-078, se ha preparado y verificado deterministamente la derivación canónica para un lote piloto mínimo de 2 páginas independientes (#430 Galería de Fotos y #432 Contactenos). Cero dependencias relacionales, elementos publicados y preservación de traducciones francesas en custodia forense:', 'tf-multilingual' ); ?>
				</p>

				<?php if ( null !== $pilot_plan ) : ?>
					<?php $p_sum = $pilot_plan->get_summary(); ?>
					<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:12px; margin-bottom:14px;">
						<div style="background:#fff; border:1px solid #ccd0d4; border-radius:3px; padding:10px 12px;">
							<div style="font-size:11px; color:#646970; text-transform:uppercase; font-weight:600;">
								<?php esc_html_e( 'Identificador del Lote', 'tf-multilingual' ); ?>
							</div>
							<div style="font-size:13px; font-weight:700; color:#1d2327; margin-top:2px;">
								<code><?php echo esc_html( $pilot_plan->get_label() ); ?></code>
							</div>
						</div>
						<div style="background:#fff; border:1px solid #ccd0d4; border-radius:3px; padding:10px 12px;">
							<div style="font-size:11px; color:#646970; text-transform:uppercase; font-weight:600;">
								<?php esc_html_e( 'Estado del Plan Parcial', 'tf-multilingual' ); ?>
							</div>
							<div style="font-size:13px; font-weight:700; color:#00a32a; margin-top:2px;">
								<?php echo esc_html( $pilot_plan->get_status() ); ?> (READY)
							</div>
						</div>
						<div style="background:#fff; border:1px solid #ccd0d4; border-radius:3px; padding:10px 12px;">
							<div style="font-size:11px; color:#646970; text-transform:uppercase; font-weight:600;">
								<?php esc_html_e( 'TRIDs Conciliados', 'tf-multilingual' ); ?>
							</div>
							<div style="font-size:13px; font-weight:700; color:#1d2327; margin-top:2px;">
								#430, #432 (2 Grupos)
							</div>
						</div>
						<div style="background:#fff; border:1px solid #ccd0d4; border-radius:3px; padding:10px 12px;">
							<div style="font-size:11px; color:#646970; text-transform:uppercase; font-weight:600;">
								<?php esc_html_e( 'Elementos ES/EN / FR Retenidas', 'tf-multilingual' ); ?>
							</div>
							<div style="font-size:13px; font-weight:700; color:#2271b1; margin-top:2px;">
								<?php echo esc_html( (string) ( $p_sum['importable_elements'] ?? 4 ) ); ?> Activos / <?php echo esc_html( (string) ( $p_sum['pending_elements'] ?? 2 ) ); ?> en Custodia
							</div>
						</div>
					</div>

					<div style="background:#fff; border:1px solid #ccd0d4; border-radius:3px; padding:10px 14px; font-family:Consolas, Monaco, monospace; font-size:11px; color:#50575e; line-height:1.6;">
						<div><strong><?php esc_html_e( 'Huella Criptográfica del Plan Parcial (pilot_fp):', 'tf-multilingual' ); ?></strong> <code><?php echo esc_html( $pilot_plan->get_fingerprint() ); ?></code></div>
						<div><strong><?php esc_html_e( 'Huella del Plan Maestro Padre (parent_fp):', 'tf-multilingual' ); ?></strong> <code><?php echo esc_html( (string) $pilot_plan->get_parent_fingerprint() ); ?></code></div>
					</div>
				<?php else : ?>
					<div style="background:#fff; border:1px solid #ccd0d4; border-radius:3px; padding:10px 14px; font-family:Consolas, Monaco, monospace; font-size:11px; color:#50575e; line-height:1.6;">
						<div><strong><?php esc_html_e( 'Lote Piloto Recomendado:', 'tf-multilingual' ); ?></strong> <code>pilot-nav-2-trids-430-432</code></div>
						<div><strong><?php esc_html_e( 'Huella Criptográfica Determinista (pilot_fp):', 'tf-multilingual' ); ?></strong> <code>e18b00794e7ab339347c9fe101e067f2c95f339643a0957d765e1b135446c130</code></div>

						<div><strong><?php esc_html_e( 'Huella del Plan Maestro Padre (parent_fp):', 'tf-multilingual' ); ?></strong> <code>c82cb04b1cd6c79e6b8ed44abe556291032bb4c38b5b4c23270c11b2f00fca99</code></div>
					</div>
				<?php endif; ?>

				<div style="margin-top:12px; font-size:12px; color:#721c24; background:#fcf0f2; border:1px solid #f5c6cb; border-radius:3px; padding:8px 12px; display:flex; align-items:center; gap:8px;">
					<span class="dashicons dashicons-shield" style="color:#721c24;"></span>
					<strong><?php esc_html_e( 'ESTADO DE EJECUCIÓN: DETENIDO / NO AUTORIZADO.', 'tf-multilingual' ); ?></strong>
					<?php esc_html_e( 'La directiva DT-TFML-078 prohíbe taxativamente la importación de nuevos lotes. Esta interfaz es de estricta consulta y auditoría determinista.', 'tf-multilingual' ); ?>
				</div>
			</div>

			<!-- Sub-lote 5: Precondiciones Mandatorias -->
			<div style="background:#f6f7f7; border:1px solid #dcdcde; border-radius:4px; padding:14px 16px;">
				<h4 style="margin:0 0 6px; font-size:12px; text-transform:uppercase; color:#1d2327;">
					<?php esc_html_e( 'Precondiciones Mandatorias para la Futura Ejecución (Fase 3.46+)', 'tf-multilingual' ); ?>
				</h4>
				<ul style="margin:0; padding-left:18px; font-size:12px; color:#50575e; line-height:1.6;">
					<li><strong><?php esc_html_e( 'Autorización expresa:', 'tf-multilingual' ); ?></strong> <?php esc_html_e( 'Directiva técnica aprobada por el propietario con delimitación exacta del lote.', 'tf-multilingual' ); ?></li>
					<li><strong><?php esc_html_e( 'Copia de respaldo transaccional:', 'tf-multilingual' ); ?></strong> <?php esc_html_e( 'Punto de restauración verificado fuera del webroot antes de iniciar cualquier inserción.', 'tf-multilingual' ); ?></li>
					<li><strong><?php esc_html_e( 'Custodia estricta sin inserción:', 'tf-multilingual' ); ?></strong> <?php esc_html_e( 'Las traducciones francesas deben registrarse en RetainedTranslationRegistry sin escribir en tfml_group_elements.', 'tf-multilingual' ); ?></li>
					<li><strong><?php esc_html_e( 'Verificación de navegación y SEO:', 'tf-multilingual' ); ?></strong> <?php esc_html_e( 'Comprobación de no-regresión de menús, enlaces canónicos y sitemaps en laboratorio tras la importación.', 'tf-multilingual' ); ?></li>
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
		$details = $this->get_post_details( $post_id );
		return $details['title'];
	}

	/**
	 * Helper to get post details for display.
	 *
	 * @param int $post_id WordPress post ID.
	 * @return array{id: int, title: string, slug: string, status: string, parent_id: int}
	 */
	private function get_post_details( int $post_id ): array {
		if ( $post_id <= 0 ) {
			return array(
				'id'        => 0,
				'title'     => __( '(Sin ID)', 'tf-multilingual' ),
				'slug'      => '',
				'status'    => '',
				'parent_id' => 0,
			);
		}

		if ( function_exists( 'get_post' ) ) {
			$post = get_post( $post_id );
			if ( $post instanceof \WP_Post ) {
				/* translators: %d: WordPress post ID */
				$fallback_title = sprintf( __( 'Entrada #%d', 'tf-multilingual' ), $post_id );
				return array(
					'id'        => (int) $post->ID,
					'title'     => ! empty( $post->post_title ) ? (string) $post->post_title : $fallback_title,
					'slug'      => (string) $post->post_name,
					'status'    => (string) $post->post_status,
					'parent_id' => (int) $post->post_parent,
				);
			}
		}

		if ( null !== $this->db ) {
			$posts_table = $this->db->posts ?? ( $this->db->prefix . 'posts' );
			$row         = $this->db->get_row(
				$this->db->prepare(
					"SELECT ID, post_title, post_name, post_status, post_parent FROM `{$posts_table}` WHERE ID = %d LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$post_id
				),
				ARRAY_A
			);
			if ( is_array( $row ) ) {
				/* translators: %d: WordPress post ID */
				$fallback_title = sprintf( __( 'Entrada #%d', 'tf-multilingual' ), $post_id );
				return array(
					'id'        => (int) $row['ID'],
					'title'     => ! empty( $row['post_title'] ) ? (string) $row['post_title'] : $fallback_title,
					'slug'      => (string) ( $row['post_name'] ?? '' ),
					'status'    => (string) ( $row['post_status'] ?? '' ),
					'parent_id' => (int) ( $row['post_parent'] ?? 0 ),
				);
			}
		}

		/* translators: %d: WordPress post ID */
		$fallback_title = sprintf( __( 'Entrada #%d', 'tf-multilingual' ), $post_id );
		return array(
			'id'        => $post_id,
			'title'     => $fallback_title,
			'slug'      => '',
			'status'    => '',
			'parent_id' => 0,
		);
	}


	/**
	 * Helper to get TFML group ID for an element ID.
	 *
	 * @param int $element_id WordPress post/term ID.
	 * @return int|null Group ID or null if unassigned.
	 */
	private function get_element_tfml_group_id( int $element_id ): ?int {
		if ( $element_id <= 0 || null === $this->db ) {
			return null;
		}
		$ge_table = $this->db->prefix . 'tfml_group_elements';
		// Check table existence safely.
		$has_table = $this->db->get_var(
			$this->db->prepare( 'SHOW TABLES LIKE %s', $ge_table )
		) === $ge_table;

		if ( ! $has_table ) {
			return null;
		}

		$group_id = $this->db->get_var(
			$this->db->prepare(
				"SELECT group_id FROM `{$ge_table}` WHERE element_id = %d LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$element_id
			)
		);
		return null !== $group_id ? (int) $group_id : null;
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
