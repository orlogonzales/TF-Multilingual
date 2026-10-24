<?php
/**
 * Translation Center / Overview Administrative UI Component.
 *
 * Provides a centralized dashboard for consulting and managing translations across
 * all supported post types, custom post types, and taxonomies in TF Multilingual.
 *
 * @package TF\Multilingual\Admin
 */

declare( strict_types=1 );

namespace TF\Multilingual\Admin;

use TF\Multilingual\Domain\Language\Language;
use TF\Multilingual\Domain\Language\LanguageFlagRenderer;
use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Domain\Translation\TranslationElement;
use TF\Multilingual\Domain\Translation\TranslationGroup;
use TF\Multilingual\Domain\Translation\TranslationGroupRepository;
use TF\Multilingual\Domain\Translation\TranslationStatus;
use TF\Multilingual\Domain\Versioning\TranslationStatusResolver;
use TF\Multilingual\Editorial\TranslationEditorialService;
use TF\Multilingual\Migration\RetainedTranslationRegistry;

/**
 * Class TranslationsOverviewUi
 *
 * Renders the central translations administrative screen under "TF Multilingual → Traducciones".
 */
class TranslationsOverviewUi {

	/**
	 * Parent menu slug.
	 */
	public const PARENT_SLUG = 'tf-multilingual';

	/**
	 * Page slug.
	 */
	public const PAGE_SLUG = 'tfml-translations';

	/**
	 * Translation group repository.
	 *
	 * @var TranslationGroupRepository
	 */
	private TranslationGroupRepository $group_repository;

	/**
	 * Language registry.
	 *
	 * @var LanguageRegistry
	 */
	private LanguageRegistry $language_registry;

	/**
	 * Translation editorial service.
	 *
	 * @var TranslationEditorialService
	 */
	private TranslationEditorialService $editorial_service;

	/**
	 * Retained translation registry.
	 *
	 * @var RetainedTranslationRegistry|null
	 */
	private ?RetainedTranslationRegistry $retained_registry;

	/**
	 * Translation status resolver.
	 *
	 * @var TranslationStatusResolver
	 */
	private TranslationStatusResolver $status_resolver;

	/**
	 * Constructor.
	 *
	 * @param TranslationGroupRepository         $group_repository   Group repository.
	 * @param LanguageRegistry                  $language_registry  Language registry.
	 * @param TranslationEditorialService        $editorial_service  Editorial service.
	 * @param RetainedTranslationRegistry|null  $retained_registry  Optional retained registry.
	 */
	public function __construct(
		TranslationGroupRepository $group_repository,
		LanguageRegistry $language_registry,
		TranslationEditorialService $editorial_service,
		?RetainedTranslationRegistry $retained_registry = null
	) {
		$this->group_repository  = $group_repository;
		$this->language_registry = $language_registry;
		$this->editorial_service = $editorial_service;
		$this->retained_registry = $retained_registry;
		$this->status_resolver   = $editorial_service->get_status_resolver();
	}

	/**
	 * Initializes WordPress admin hooks.
	 *
	 * @return void
	 */
	public function init_hooks(): void {
		if ( function_exists( 'add_action' ) ) {
			add_action( 'admin_menu', array( $this, 'register_menu_page' ), 10 );
		}
	}

	/**
	 * Required capability to view this administrative screen.
	 *
	 * @return string
	 */
	public function get_capability(): string {
		return 'edit_posts';
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
			__( 'Centro de Traducciones — TF Multilingual', 'tf-multilingual' ),
			__( 'Traducciones', 'tf-multilingual' ),
			$this->get_capability(),
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Renders the translations overview administrative page.
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

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$search_query = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$subtype_filt = isset( $_GET['subtype'] ) ? sanitize_key( wp_unslash( $_GET['subtype'] ) ) : '';
		$status_filt  = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'all';
		$lang_filt    = isset( $_GET['lang'] ) ? sanitize_key( wp_unslash( $_GET['lang'] ) ) : '';
		$current_page = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// phpcs:enable

		$per_page = 20;

		$query_args = array(
			'page'     => $current_page,
			'per_page' => $per_page,
			'search'   => $search_query,
			'subtype'  => $subtype_filt,
			'language' => $lang_filt,
		);

		$result = $this->group_repository->query_groups_overview( $query_args );

		/** @var array<int, TranslationGroup> $groups */
		$groups         = $result['items'];
		$total_items    = $result['total'];
		$total_pages    = $result['total_pages'];
		$total_groups   = $result['total_groups_count'];
		$total_elements = $result['total_elements_count'];

		$active_langs      = $this->language_registry->active();
		$retained_records  = null !== $this->retained_registry ? $this->retained_registry->get_all() : array();
		$retained_count    = count( $retained_records );
		$distinct_subtypes = $this->group_repository->get_distinct_subtypes();

		// Filter items in memory if status filter is applied ('complete', 'incomplete', 'review').
		if ( 'all' !== $status_filt && ! empty( $groups ) ) {
			$filtered_groups = array();
			foreach ( $groups as $g ) {
				$group_elements = $g->get_elements();
				$has_all_active = true;
				foreach ( $active_langs as $code => $lang ) {
					if ( ! isset( $group_elements[ $code ] ) ) {
						$has_all_active = false;
						break;
					}
				}

				$has_review = false;
				foreach ( $group_elements as $elem ) {
					$elem_status = $this->status_resolver->resolve_element_status( $elem, $g );
					if ( TranslationStatus::REVIEW === $elem_status ) {
						$has_review = true;
						break;
					}
				}

				if ( 'complete' === $status_filt && $has_all_active ) {
					$filtered_groups[] = $g;
				} elseif ( 'incomplete' === $status_filt && ! $has_all_active ) {
					$filtered_groups[] = $g;
				} elseif ( 'review' === $status_filt && $has_review ) {
					$filtered_groups[] = $g;
				}
			}
			$groups = $filtered_groups;
		}

		?>
		<div class="wrap tfml-spatial-wrap tfml-translations-overview-wrap">
			<!-- Spatial Header -->
			<div class="tfml-spatial-header">
				<div class="tfml-header-left">
					<div class="tfml-header-icon" aria-hidden="true">
						<svg viewBox="0 0 24 24"><path d="M12.87 15.07l-2.54-2.51.03-.03c1.74-1.94 2.98-4.17 3.71-6.53H17V4h-7V2H8v2H1v1.99h11.17C11.5 7.92 10.44 9.75 9 11.35 8.07 10.32 7.3 9.19 6.69 8h-2c.73 1.63 1.73 3.17 2.98 4.56l-5.09 5.02L4 19l5-5 3.11 3.11.76-2.04zM18.5 10h-2L12 22h2l1.12-3h4.75L21 22h2l-4.5-12zm-2.62 7l1.62-4.33L19.12 17h-3.24z"/></svg>
					</div>
					<div>
						<h1 class="tfml-header-title"><?php esc_html_e( 'Centro de Traducciones', 'tf-multilingual' ); ?></h1>
						<p class="tfml-header-subtitle"><?php esc_html_e( 'Consulte, filtre y gestione el estado editorial de todos los contenidos multilingües de EcoTerra desde un panel unificado.', 'tf-multilingual' ); ?></p>
					</div>
				</div>
				<div class="tfml-header-actions">
					<a href="#retained-custody" class="tfml-btn tfml-btn-outline tfml-btn-sm" style="border-color:var(--tfml-color-warning-border);color:var(--tfml-color-warning-text);background:var(--tfml-color-warning-bg);">
						<span class="dashicons dashicons-shield" style="font-size:16px;line-height:1.2;"></span>
						<?php printf( esc_html__( 'Ver 21 FR Retenidas', 'tf-multilingual' ) ); ?>
					</a>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=tf-multilingual' ) ); ?>" class="tfml-btn tfml-btn-outline tfml-btn-sm">
						<span class="dashicons dashicons-dashboard" style="font-size:16px;line-height:1.2;"></span>
						<?php esc_html_e( 'Control Center', 'tf-multilingual' ); ?>
					</a>
				</div>
			</div>

			<!-- KPI Summary Stat Cards -->
			<div class="tfml-kpi-grid" style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:16px; margin-bottom:20px;">
				<div class="tfml-kpi-card" style="background:#fff; border:1px solid #c3c4c7; border-left:4px solid #2271b1; border-radius:4px; padding:14px 16px;">
					<div style="font-size:12px; color:#646970; text-transform:uppercase; font-weight:600; letter-spacing:0.5px;">
						<?php esc_html_e( 'Grupos Registrados', 'tf-multilingual' ); ?>
					</div>
					<div style="font-size:26px; font-weight:700; color:#1d2327; margin-top:4px;">
						<?php echo esc_html( number_format_i18n( $total_groups ) ); ?>
					</div>
					<div style="font-size:11px; color:#50575e; margin-top:2px;">
						<?php esc_html_e( 'Grupos de traducción activos en TFML', 'tf-multilingual' ); ?>
					</div>
				</div>

				<div class="tfml-kpi-card" style="background:#fff; border:1px solid #c3c4c7; border-left:4px solid #46b450; border-radius:4px; padding:14px 16px;">
					<div style="font-size:12px; color:#646970; text-transform:uppercase; font-weight:600; letter-spacing:0.5px;">
						<?php esc_html_e( 'Elementos Vinculados', 'tf-multilingual' ); ?>
					</div>
					<div style="font-size:26px; font-weight:700; color:#1d2327; margin-top:4px;">
						<?php echo esc_html( number_format_i18n( $total_elements ) ); ?>
					</div>
					<div style="font-size:11px; color:#50575e; margin-top:2px;">
						<?php esc_html_e( 'Entidades WordPress activas gestionadas', 'tf-multilingual' ); ?>
					</div>
				</div>

				<div class="tfml-kpi-card" style="background:#fff; border:1px solid #c3c4c7; border-left:4px solid #72aee6; border-radius:4px; padding:14px 16px;">
					<div style="font-size:12px; color:#646970; text-transform:uppercase; font-weight:600; letter-spacing:0.5px;">
						<?php esc_html_e( 'Idiomas Activos', 'tf-multilingual' ); ?>
					</div>
					<div style="font-size:22px; font-weight:700; color:#1d2327; margin-top:6px; display:flex; gap:6px; align-items:center;">
						<?php foreach ( $active_langs as $code => $lang ) : ?>
							<span style="background:#f0f0f1; border:1px solid #dcdcde; border-radius:3px; padding:2px 6px; font-size:12px; display:inline-flex; align-items:center; gap:4px;">
								<?php echo LanguageFlagRenderer::render( $code, $lang->get_name(), 18, 13, $lang->get_flag_code() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								<?php echo esc_html( strtoupper( $code ) ); ?>
							</span>
						<?php endforeach; ?>
					</div>
					<div style="font-size:11px; color:#50575e; margin-top:4px;">
						<?php esc_html_e( 'Catálogo público en producción', 'tf-multilingual' ); ?>
					</div>
				</div>

				<div class="tfml-kpi-card" style="background:#fff; border:1px solid #c3c4c7; border-left:4px solid #dba617; border-radius:4px; padding:14px 16px;">
					<div style="font-size:12px; color:#646970; text-transform:uppercase; font-weight:600; letter-spacing:0.5px;">
						<?php esc_html_e( 'Traducciones Retenidas', 'tf-multilingual' ); ?>
					</div>
					<div style="font-size:26px; font-weight:700; color:#dba617; margin-top:4px;">
						<?php echo esc_html( number_format_i18n( $retained_count ) ); ?>
					</div>
					<div style="font-size:11px; color:#50575e; margin-top:2px;">
						<?php esc_html_e( 'En custodia histórica (Francés)', 'tf-multilingual' ); ?>
					</div>
				</div>
			</div>

			<!-- Filter / Search Form -->
			<form method="get" action="admin.php" style="margin-bottom:14px;">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />

				<div class="tablenav top" style="display:flex; flex-wrap:wrap; gap:8px; align-items:center; margin:0 0 10px 0;">
					<!-- Search field -->
					<div class="alignleft actions">
						<label for="tfml-search-input" class="screen-reader-text"><?php esc_html_e( 'Buscar traducciones', 'tf-multilingual' ); ?></label>
						<input type="search"
							id="tfml-search-input"
							name="s"
							value="<?php echo esc_attr( $search_query ); ?>"
							placeholder="<?php esc_attr_e( 'Buscar por título o ID...', 'tf-multilingual' ); ?>"
							style="width:220px;" />
					</div>

					<!-- Subtype Filter -->
					<div class="alignleft actions">
						<label for="filter-by-subtype" class="screen-reader-text"><?php esc_html_e( 'Filtrar por tipo de contenido', 'tf-multilingual' ); ?></label>
						<select name="subtype" id="filter-by-subtype">
							<option value=""><?php esc_html_e( 'Todos los tipos de contenido', 'tf-multilingual' ); ?></option>
							<?php foreach ( $distinct_subtypes as $st ) : ?>
								<option value="<?php echo esc_attr( $st ); ?>" <?php selected( $subtype_filt, $st ); ?>>
									<?php echo esc_html( ucfirst( $st ) ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</div>

					<!-- Status Filter -->
					<div class="alignleft actions">
						<label for="filter-by-status" class="screen-reader-text"><?php esc_html_e( 'Filtrar por estado de traducción', 'tf-multilingual' ); ?></label>
						<select name="status" id="filter-by-status">
							<option value="all" <?php selected( $status_filt, 'all' ); ?>><?php esc_html_e( 'Todos los estados', 'tf-multilingual' ); ?></option>
							<option value="complete" <?php selected( $status_filt, 'complete' ); ?>><?php esc_html_e( 'Completas (todos los idiomas traducidos)', 'tf-multilingual' ); ?></option>
							<option value="incomplete" <?php selected( $status_filt, 'incomplete' ); ?>><?php esc_html_e( 'Incompletas (traducción pendiente)', 'tf-multilingual' ); ?></option>
							<option value="review" <?php selected( $status_filt, 'review' ); ?>><?php esc_html_e( 'Requieren revisión', 'tf-multilingual' ); ?></option>
						</select>
					</div>

					<!-- Language Filter -->
					<div class="alignleft actions">
						<label for="filter-by-lang" class="screen-reader-text"><?php esc_html_e( 'Filtrar por idioma', 'tf-multilingual' ); ?></label>
						<select name="lang" id="filter-by-lang">
							<option value=""><?php esc_html_e( 'Cualquier idioma presente', 'tf-multilingual' ); ?></option>
							<?php foreach ( $active_langs as $code => $lang ) : ?>
								<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $lang_filt, $code ); ?>>
									<?php echo esc_html( sprintf( '%s (%s)', $lang->get_name(), strtoupper( $code ) ) ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</div>

					<div class="alignleft actions">
						<input type="submit" class="button" value="<?php esc_attr_e( 'Filtrar', 'tf-multilingual' ); ?>" />
						<?php if ( '' !== $search_query || '' !== $subtype_filt || 'all' !== $status_filt || '' !== $lang_filt ) : ?>
							<a href="admin.php?page=<?php echo esc_attr( self::PAGE_SLUG ); ?>" class="button button-link" style="margin-left:4px;">
								<?php esc_html_e( 'Restablecer filtros', 'tf-multilingual' ); ?>
							</a>
						<?php endif; ?>
					</div>

					<!-- Pagination info -->
					<?php if ( $total_pages > 1 ) : ?>
						<div class="tablenav-pages" style="margin-left:auto;">
							<span class="displaying-num">
								<?php
								/* translators: %s: number of translation groups */
								echo esc_html( sprintf( _n( '%s grupo', '%s grupos', $total_items, 'tf-multilingual' ), number_format_i18n( $total_items ) ) );
								?>
							</span>
							<span class="pagination-links">
								<?php
								$base_url = add_query_arg(
									array(
										'page'    => self::PAGE_SLUG,
										's'       => $search_query,
										'subtype' => $subtype_filt,
										'status'  => $status_filt,
										'lang'    => $lang_filt,
									),
									'admin.php'
								);
								if ( $current_page > 1 ) {
									printf(
										'<a class="prev-page button" href="%s"><span class="screen-reader-text">%s</span><span aria-hidden="true">‹</span></a> ',
										esc_url( add_query_arg( 'paged', $current_page - 1, $base_url ) ),
										esc_html__( 'Página anterior', 'tf-multilingual' )
									);
								}
								printf(
									'<span class="paging-input"><span class="current-page">%d</span> %s <span class="total-pages">%d</span></span> ',
									(int) $current_page,
									esc_html__( 'de', 'tf-multilingual' ),
									(int) $total_pages
								);
								if ( $current_page < $total_pages ) {
									printf(
										'<a class="next-page button" href="%s"><span class="screen-reader-text">%s</span><span aria-hidden="true">›</span></a>',
										esc_url( add_query_arg( 'paged', $current_page + 1, $base_url ) ),
										esc_html__( 'Página siguiente', 'tf-multilingual' )
									);
								}
								?>
							</span>
						</div>
					<?php endif; ?>
				</div>
			</form>

			<!-- Main Overview Table -->
			<table class="wp-list-table widefat fixed striped table-view-list tfml-translations-table">
				<thead>
					<tr>
						<th scope="col" style="width:110px;"><?php esc_html_e( 'Grupo / Tipo', 'tf-multilingual' ); ?></th>
						<th scope="col" style="width:260px;"><?php esc_html_e( 'Contenido Canónico', 'tf-multilingual' ); ?></th>
						<?php foreach ( $active_langs as $code => $lang ) : ?>
							<th scope="col" style="text-align:left;">
								<span style="display:inline-flex; align-items:center; gap:6px;">
									<?php echo LanguageFlagRenderer::render( $code, $lang->get_name(), 20, 14, $lang->get_flag_code() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
									<span style="font-weight:700; color:#1d2327;"><?php echo esc_html( strtoupper( $code ) ); ?></span>
									<span style="font-weight:400; font-size:11px; color:#646970;">(<?php echo esc_html( $lang->get_name() ); ?>)</span>
								</span>
							</th>
						<?php endforeach; ?>
					</tr>
				</thead>

				<tbody id="the-list">
					<?php if ( empty( $groups ) ) : ?>
						<tr class="no-items">
							<td class="colspanchange" colspan="<?php echo esc_attr( (string) ( 2 + count( $active_langs ) ) ); ?>" style="padding:24px; text-align:center; color:#646970;">
								<span class="dashicons dashicons-search" style="font-size:32px; width:32px; height:32px; color:#c3c4c7; margin-bottom:8px;"></span><br>
								<strong><?php esc_html_e( 'No se encontraron grupos de traducción que coincidan con los filtros seleccionados.', 'tf-multilingual' ); ?></strong>
							</td>
						</tr>
					<?php else : ?>
						<?php foreach ( $groups as $group ) : ?>
							<?php
							$gid            = (int) $group->get_id();
							$etype          = (string) $group->get_element_type();
							$stype          = (string) $group->get_subtype();
							$canonical_eid  = $group->get_canonical_element_id();
							$group_elements = $group->get_elements();

							$canonical_title    = $canonical_eid ? $this->get_element_display_title( $etype, $canonical_eid, $stype ) : __( '(Sin canónico)', 'tf-multilingual' );
							$canonical_edit_url = $canonical_eid ? $this->editorial_service->get_edit_url( $etype, $canonical_eid, $stype ) : '';
							$canonical_status   = $canonical_eid && 'post' === $etype ? $this->get_post_status_label( $canonical_eid ) : '';
							?>
							<tr>
								<!-- Col 1: Group ID & Subtype -->
								<td>
									<strong>#<?php echo esc_html( (string) $gid ); ?></strong><br>
									<span style="display:inline-block; margin-top:2px; font-size:10px; background:#f0f0f1; border:1px solid #dcdcde; border-radius:3px; padding:1px 5px; color:#50575e;">
										<?php echo esc_html( $stype ); ?>
									</span>
								</td>

								<!-- Col 2: Canonical Content -->
								<td>
									<?php if ( '' !== $canonical_edit_url ) : ?>
										<strong><a href="<?php echo esc_url( $canonical_edit_url ); ?>" style="text-decoration:none; color:#2271b1;">
											<?php echo esc_html( $canonical_title ); ?>
										</a></strong>
									<?php else : ?>
										<strong><?php echo esc_html( $canonical_title ); ?></strong>
									<?php endif; ?>

									<div style="font-size:11px; color:#646970; margin-top:3px;">
										<?php if ( $canonical_eid ) : ?>
											<code>ID: <?php echo esc_html( (string) $canonical_eid ); ?></code>
										<?php endif; ?>
										<?php if ( '' !== $canonical_status ) : ?>
											&bull; <span><?php echo esc_html( $canonical_status ); ?></span>
										<?php endif; ?>
									</div>
								</td>

								<!-- Col 3+: Active Languages -->
								<?php foreach ( $active_langs as $code => $lang ) : ?>
									<td>
										<?php if ( isset( $group_elements[ $code ] ) ) : ?>
											<?php
											/** @var TranslationElement $element */
											$element      = $group_elements[ $code ];
											$elem_id      = (int) $element->get_element_id();
											$elem_title   = $this->get_element_display_title( $etype, $elem_id, $stype );
											$elem_edit    = $this->editorial_service->get_edit_url( $etype, $elem_id, $stype );
											$elem_status  = $this->status_resolver->resolve_element_status( $element, $group );
											$is_canonical = ( $elem_id === $canonical_eid );
											?>
											<div class="tfml-trans-cell-item" style="font-size:12px;">
												<div style="display:flex; align-items:center; gap:4px;">
													<?php if ( TranslationStatus::UPDATED === $elem_status ) : ?>
														<span class="dashicons dashicons-yes" style="color:#46b450; font-size:16px; width:16px; height:16px;" title="<?php esc_attr_e( 'Actualizada', 'tf-multilingual' ); ?>"></span>
													<?php elseif ( TranslationStatus::REVIEW === $elem_status ) : ?>
														<span class="dashicons dashicons-warning" style="color:#dba617; font-size:16px; width:16px; height:16px;" title="<?php esc_attr_e( 'Requiere revisión', 'tf-multilingual' ); ?>"></span>
													<?php endif; ?>

													<?php if ( '' !== $elem_edit ) : ?>
														<a href="<?php echo esc_url( $elem_edit ); ?>" style="font-weight:600; text-decoration:none;">
															<?php echo esc_html( $elem_title ); ?>
														</a>
													<?php else : ?>
														<span style="font-weight:600;"><?php echo esc_html( $elem_title ); ?></span>
													<?php endif; ?>
												</div>

												<div style="margin-top:2px; font-size:11px; color:#646970;">
													<code>#<?php echo esc_html( (string) $elem_id ); ?></code>
													<?php if ( $is_canonical ) : ?>
														<span style="background:#e7f5ea; color:#1e4620; border:1px solid #c2e8c8; border-radius:3px; padding:0 4px; font-size:10px; margin-left:2px;">
															<?php esc_html_e( 'Origen', 'tf-multilingual' ); ?>
														</span>
													<?php endif; ?>
													<?php if ( '' !== $elem_edit ) : ?>
														&bull; <a href="<?php echo esc_url( $elem_edit ); ?>" class="row-actions" style="font-size:11px;"><?php esc_html_e( 'Editar', 'tf-multilingual' ); ?></a>
													<?php endif; ?>
												</div>
											</div>
										<?php else : ?>
											<div class="tfml-trans-cell-missing" style="font-size:11px; color:#8c8f94;">
												<span>&mdash; <?php esc_html_e( 'Sin traducción', 'tf-multilingual' ); ?></span>
												<?php if ( '' !== $canonical_edit_url ) : ?>
													<br>
													<a href="<?php echo esc_url( $canonical_edit_url ); ?>" class="button button-small" style="font-size:10px; height:20px; line-height:18px; margin-top:3px; padding:0 6px;" title="<?php esc_attr_e( 'Abrir editor para traducir', 'tf-multilingual' ); ?>">
														+ <?php esc_html_e( 'Traducir', 'tf-multilingual' ); ?>
													</a>
												<?php endif; ?>
											</div>
										<?php endif; ?>
									</td>
								<?php endforeach; ?>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>

				<tfoot>
					<tr>
						<th scope="col"><?php esc_html_e( 'Grupo / Tipo', 'tf-multilingual' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Contenido Canónico', 'tf-multilingual' ); ?></th>
						<?php foreach ( $active_langs as $code => $lang ) : ?>
							<th scope="col">
								<span style="font-weight:700;"><?php echo esc_html( strtoupper( $code ) ); ?></span>
							</th>
						<?php endforeach; ?>
					</tr>
				</tfoot>
			</table>

			<!-- Bottom Pagination -->
			<?php if ( $total_pages > 1 ) : ?>
				<div class="tablenav bottom" style="margin-top:10px;">
					<div class="tablenav-pages">
						<span class="displaying-num">
							<?php
							/* translators: %s: number of translation groups */
							echo esc_html( sprintf( _n( '%s grupo', '%s grupos', $total_items, 'tf-multilingual' ), number_format_i18n( $total_items ) ) );
							?>
						</span>
						<span class="pagination-links">
							<?php
							if ( $current_page > 1 ) {
								printf(
									'<a class="prev-page button" href="%s"><span class="screen-reader-text">%s</span><span aria-hidden="true">‹</span></a> ',
									esc_url( add_query_arg( 'paged', $current_page - 1, $base_url ) ),
									esc_html__( 'Página anterior', 'tf-multilingual' )
								);
							}
							printf(
								'<span class="paging-input"><span class="current-page">%d</span> %s <span class="total-pages">%d</span></span> ',
								(int) $current_page,
								esc_html__( 'de', 'tf-multilingual' ),
								(int) $total_pages
							);
							if ( $current_page < $total_pages ) {
								printf(
									'<a class="next-page button" href="%s"><span class="screen-reader-text">%s</span><span aria-hidden="true">›</span></a>',
									esc_url( add_query_arg( 'paged', $current_page + 1, $base_url ) ),
									esc_html__( 'Página siguiente', 'tf-multilingual' )
								);
							}
							?>
						</span>
					</div>
				</div>
			<?php endif; ?>

			<!-- Informative Card: Retained Historical Translations (French) -->
			<div id="retained-custody" class="postbox" style="margin-top:28px; border-left:4px solid #dba617;">
				<div class="postbox-header" style="padding:10px 14px; border-bottom:1px solid #dcdcde; background:#fcfcfc;">
					<h2 style="font-size:14px; font-weight:600; margin:0; display:flex; align-items:center; gap:6px;">
						<span class="dashicons dashicons-shield" style="color:#dba617; font-size:18px; width:18px; height:18px;"></span>
						<?php esc_html_e( 'Traducciones Históricas Retenidas en Custodia (Idioma Francés)', 'tf-multilingual' ); ?>
						<span style="font-size:11px; background:#fff8e5; color:#8a6d3b; border:1px solid #faebcc; border-radius:3px; padding:1px 6px; font-weight:600;">
							<?php
							/* translators: %d: number of retained records */
							echo esc_html( sprintf( _n( '%d registro', '%d registros', $retained_count, 'tf-multilingual' ), $retained_count ) );
							?>
						</span>
					</h2>
				</div>
				<div class="inside" style="padding:14px;">
					<p style="margin-top:0; font-size:13px; color:#50575e; line-height:1.5;">
						<?php esc_html_e( 'Las siguientes páginas corresponden a traducciones francesas preservadas de la migración histórica de WPML mediante el servicio soberano', 'tf-multilingual' ); ?>
						<code>RetainedTranslationRegistry</code>.
						<?php esc_html_e( 'Dado que el idioma Francés (FR) no está activo en el catálogo de producción, estas entidades no se exponen en el enrutamiento público ni en los sitemaps, pero se conservan con verificación criptográfica para ser promovidas cuando se active dicho idioma.', 'tf-multilingual' ); ?>
					</p>

					<table class="widefat fixed striped" style="margin-top:10px; font-size:12px;">
						<thead>
							<tr>
								<th style="width:110px;"><?php esc_html_e( 'TRID WPML', 'tf-multilingual' ); ?></th>
								<th style="width:110px;"><?php esc_html_e( 'Grupo TFML', 'tf-multilingual' ); ?></th>
								<th style="width:100px;"><?php esc_html_e( 'Subtipo', 'tf-multilingual' ); ?></th>
								<th><?php esc_html_e( 'Elemento Retenido', 'tf-multilingual' ); ?></th>
								<th style="width:150px;"><?php esc_html_e( 'Estado de Custodia', 'tf-multilingual' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php if ( empty( $retained_records ) ) : ?>
								<tr>
									<td colspan="5" style="color:#646970;"><?php esc_html_e( 'No hay traducciones retenidas registradas en este entorno.', 'tf-multilingual' ); ?></td>
								</tr>
							<?php else : ?>
								<?php foreach ( $retained_records as $rec ) : ?>
									<?php
									$r_trid   = (int) ( $rec['trid'] ?? 0 );
									$r_gid    = (int) ( $rec['group_id'] ?? 0 );
									$r_sub    = (string) ( $rec['subtype'] ?? 'page' );
									$r_elems  = (array) ( $rec['retained_elements'] ?? array() );
									$fr_id    = isset( $r_elems['fr'] ) ? (int) ( is_array( $r_elems['fr'] ) ? ( $r_elems['fr']['element_id'] ?? 0 ) : $r_elems['fr'] ) : 0;
									$fr_title = $fr_id > 0 ? $this->get_element_display_title( 'post', $fr_id, $r_sub ) : __( '(Desconocido)', 'tf-multilingual' );
									$fr_edit  = $fr_id > 0 ? $this->editorial_service->get_edit_url( 'post', $fr_id, $r_sub ) : '';
									?>
									<tr>
										<td><code><?php echo esc_html( (string) $r_trid ); ?></code></td>
										<td><strong>#<?php echo esc_html( (string) $r_gid ); ?></strong></td>
										<td><code><?php echo esc_html( $r_sub ); ?></code></td>
										<td>
											<span class="dashicons dashicons-translation" style="font-size:14px; width:14px; height:14px; vertical-align:middle; color:#646970;"></span>
											<?php if ( '' !== $fr_edit ) : ?>
												<a href="<?php echo esc_url( $fr_edit ); ?>" style="text-decoration:none; font-weight:600;">
													<?php echo esc_html( $fr_title ); ?>
												</a>
											<?php else : ?>
												<strong><?php echo esc_html( $fr_title ); ?></strong>
											<?php endif; ?>
											<code style="margin-left:4px;">ID: <?php echo esc_html( (string) $fr_id ); ?> [fr]</code>
										</td>
										<td>
											<span style="color:#8a6d3b; background:#fff8e5; border:1px solid #faebcc; border-radius:3px; padding:2px 6px; font-size:11px; font-weight:600;">
												<?php esc_html_e( 'En custodia (inactivo)', 'tf-multilingual' ); ?>
											</span>
										</td>
									</tr>
								<?php endforeach; ?>
							<?php endif; ?>
						</tbody>
					</table>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Gets the display title of an element.
	 *
	 * @param string $element_type Element type ('post' or 'term').
	 * @param int    $element_id   WordPress object ID.
	 * @param string $subtype      Subtype.
	 * @return string Display title.
	 */
	public function get_element_display_title( string $element_type, int $element_id, string $subtype = '' ): string {
		if ( 'post' === $element_type ) {
			if ( function_exists( 'get_post_field' ) ) {
				$raw_title = (string) get_post_field( 'post_title', $element_id );
				if ( '' !== trim( $raw_title ) ) {
					return $raw_title;
				}
			}

			if ( isset( $GLOBALS['wp_test_posts'][ $element_id ] ) ) {
				$p = $GLOBALS['wp_test_posts'][ $element_id ];
				if ( isset( $p->post_title ) && '' !== trim( (string) $p->post_title ) ) {
					return (string) $p->post_title;
				}
			}

			/* translators: %d: post ID */
			return sprintf( __( '(Sin título #%d)', 'tf-multilingual' ), $element_id );
		}

		if ( 'term' === $element_type ) {
			if ( function_exists( 'get_term' ) ) {
				$term = get_term( $element_id, $subtype );
				if ( $term && ! is_wp_error( $term ) && isset( $term->name ) && '' !== trim( (string) $term->name ) ) {
					return (string) $term->name;
				}
			}

			if ( isset( $GLOBALS['wp_test_terms'][ $element_id ] ) ) {
				$t = $GLOBALS['wp_test_terms'][ $element_id ];
				if ( isset( $t->name ) && '' !== trim( (string) $t->name ) ) {
					return (string) $t->name;
				}
			}

			/* translators: %d: term ID */
			return sprintf( __( '(Término #%d)', 'tf-multilingual' ), $element_id );
		}

		/* translators: %d: element ID */
		return sprintf( __( '(Elemento #%d)', 'tf-multilingual' ), $element_id );
	}

	/**
	 * Gets localized post status label.
	 *
	 * @param int $post_id Post ID.
	 * @return string Localized status label.
	 */
	public function get_post_status_label( int $post_id ): string {
		$status = '';
		if ( function_exists( 'get_post_status' ) ) {
			$status = (string) get_post_status( $post_id );
		} elseif ( isset( $GLOBALS['wp_test_posts'][ $post_id ]->post_status ) ) {
			$status = (string) $GLOBALS['wp_test_posts'][ $post_id ]->post_status;
		}

		switch ( $status ) {
			case 'publish':
				return __( 'Publicado', 'tf-multilingual' );
			case 'draft':
				return __( 'Borrador', 'tf-multilingual' );
			case 'pending':
				return __( 'Pendiente', 'tf-multilingual' );
			case 'future':
				return __( 'Programado', 'tf-multilingual' );
			case 'private':
				return __( 'Privado', 'tf-multilingual' );
			default:
				return $status;
		}
	}
}
