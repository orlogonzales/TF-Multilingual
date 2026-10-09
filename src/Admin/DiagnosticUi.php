<?php
/**
 * Diagnostic and Health Check Admin UI.
 *
 * @package TF\Multilingual\Admin
 */

declare( strict_types=1 );

namespace TF\Multilingual\Admin;

use TF\Multilingual\Diagnostic\DiagnosticService;

/**
 * Class DiagnosticUi
 *
 * Provides a dedicated administrative status dashboard under "TF Multilingual > Diagnóstico"
 * and integrates sovereign health checks into WordPress Core Site Health ("Salud del sitio").
 */
class DiagnosticUi {

	/**
	 * Page slug.
	 */
	public const PAGE_SLUG = 'tfml-diagnostic';

	/**
	 * Top-level menu slug.
	 */
	public const PARENT_SLUG = 'tf-multilingual';

	/**
	 * Nonce action.
	 */
	public const NONCE_ACTION = 'tfml_run_diagnostic_action';

	/**
	 * Nonce field name.
	 */
	public const NONCE_NAME = 'tfml_diagnostic_nonce';

	/**
	 * Diagnostic service instance.
	 *
	 * @var DiagnosticService
	 */
	private DiagnosticService $service;

	/**
	 * Constructor.
	 *
	 * @param DiagnosticService $service Diagnostic service.
	 */
	public function __construct( DiagnosticService $service ) {
		$this->service = $service;
	}

	/**
	 * Registers WordPress admin hooks and Site Health integrations.
	 *
	 * @return void
	 */
	public function init_hooks(): void {
		if ( function_exists( 'add_action' ) ) {
			add_action( 'admin_menu', array( $this, 'register_menu_page' ) );
		}

		if ( function_exists( 'add_filter' ) ) {
			add_filter( 'site_status_tests', array( $this, 'register_site_status_tests' ) );
		}
	}

	/**
	 * Registers the diagnostic submenu page.
	 *
	 * @return void
	 */
	public function register_menu_page(): void {
		if ( ! function_exists( 'add_submenu_page' ) ) {
			return;
		}

		// Ensure parent menu exists.
		global $admin_page_hooks;
		if ( ! isset( $admin_page_hooks[ self::PARENT_SLUG ] ) && function_exists( 'add_menu_page' ) ) {
			add_menu_page(
				__( 'TF Multilingual', 'tf-multilingual' ),
				__( 'TF Multilingual', 'tf-multilingual' ),
				'manage_options',
				self::PARENT_SLUG,
				array( $this, 'render_page' ),
				'dashicons-translation',
				30
			);
		}

		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Diagnóstico del Sistema — TF Multilingual', 'tf-multilingual' ),
			__( 'Diagnóstico', 'tf-multilingual' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Registers direct tests into WordPress Core Site Health tool.
	 *
	 * @param array<string, mixed> $tests Existing Site Health tests.
	 * @return array<string, mixed>
	 */
	public function register_site_status_tests( array $tests ): array {
		$tests['direct']['tfml_tables_integrity'] = array(
			'label' => __( 'Tablas Maestras de TF Multilingual', 'tf-multilingual' ),
			'test'  => array( $this, 'test_tables_integrity' ),
		);

		$tests['direct']['tfml_default_language'] = array(
			'label' => __( 'Idioma Predeterminado de TF Multilingual', 'tf-multilingual' ),
			'test'  => array( $this, 'test_default_language' ),
		);

		$tests['direct']['tfml_relations_integrity'] = array(
			'label' => __( 'Integridad Relacional de TF Multilingual', 'tf-multilingual' ),
			'test'  => array( $this, 'test_relations_integrity' ),
		);

		return $tests;
	}

	/**
	 * Site Health test callback: Table integrity.
	 *
	 * @return array<string, mixed>
	 */
	public function test_tables_integrity(): array {
		$report = $this->service->get_tables_report();

		if ( $report['all_tables_exist'] ) {
			return array(
				'label'       => __( 'Las 5 tablas maestras de TF Multilingual están instaladas correctamente', 'tf-multilingual' ),
				'status'      => 'good',
				'badge'       => array(
					'label' => __( 'TF Multilingual', 'tf-multilingual' ),
					'color' => 'blue',
				),
				'description' => sprintf(
					'<p>%s</p>',
					esc_html__( 'Todas las tablas requeridas por TF Multilingual existen y están accesibles en la base de datos.', 'tf-multilingual' )
				),
				'actions'     => '',
				'test'        => 'tfml_tables_integrity',
			);
		}

		return array(
			'label'       => __( 'Faltan tablas de la base de datos de TF Multilingual', 'tf-multilingual' ),
			'status'      => 'critical',
			'badge'       => array(
				'label' => __( 'TF Multilingual', 'tf-multilingual' ),
				'color' => 'red',
			),
			'description' => sprintf(
				'<p>%s</p>',
				esc_html__( 'Una o más tablas oficiales de TF Multilingual no se encuentran en la base de datos.', 'tf-multilingual' )
			),
			'actions'     => '',
			'test'        => 'tfml_tables_integrity',
		);
	}

	/**
	 * Site Health test callback: Default language.
	 *
	 * @return array<string, mixed>
	 */
	public function test_default_language(): array {
		$report = $this->service->get_languages_report();

		if ( $report['is_configured'] && null !== $report['default_language'] && $report['default_is_active'] ) {
			return array(
				'label'       => sprintf(
					/* translators: %s: Language code */
					__( 'El idioma predeterminado de TF Multilingual está configurado (%s)', 'tf-multilingual' ),
					esc_html( strtoupper( $report['default_language'] ) )
				),
				'status'      => 'good',
				'badge'       => array(
					'label' => __( 'TF Multilingual', 'tf-multilingual' ),
					'color' => 'blue',
				),
				'description' => sprintf(
					'<p>%s</p>',
					esc_html__( 'El sistema tiene un idioma predeterminado válido y activo.', 'tf-multilingual' )
				),
				'actions'     => '',
				'test'        => 'tfml_default_language',
			);
		}

		return array(
			'label'       => __( 'El idioma predeterminado de TF Multilingual no está configurado o está inactivo', 'tf-multilingual' ),
			'status'      => 'critical',
			'badge'       => array(
				'label' => __( 'TF Multilingual', 'tf-multilingual' ),
				'color' => 'red',
			),
			'description' => sprintf(
				'<p>%s</p>',
				esc_html__( 'TF Multilingual requiere un idioma predeterminado activo para enrutar el contenido correctamente.', 'tf-multilingual' )
			),
			'actions'     => '',
			'test'        => 'tfml_default_language',
		);
	}

	/**
	 * Site Health test callback: Relations integrity.
	 *
	 * @return array<string, mixed>
	 */
	public function test_relations_integrity(): array {
		$report = $this->service->get_relations_integrity_report();

		if ( 'good' === $report['status'] ) {
			return array(
				'label'       => __( 'La integridad relacional de grupos de traducción es óptima', 'tf-multilingual' ),
				'status'      => 'good',
				'badge'       => array(
					'label' => __( 'TF Multilingual', 'tf-multilingual' ),
					'color' => 'blue',
				),
				'description' => sprintf(
					'<p>%s</p>',
					esc_html__( 'No se han detectado duplicados, huérfanos ni incoherencias en las relaciones de contenido.', 'tf-multilingual' )
				),
				'actions'     => '',
				'test'        => 'tfml_relations_integrity',
			);
		}

		return array(
			'label'       => __( 'Se detectaron inconsistencias en las relaciones de traducción', 'tf-multilingual' ),
			'status'      => ( 'critical' === $report['status'] ) ? 'critical' : 'recommended',
			'badge'       => array(
				'label' => __( 'TF Multilingual', 'tf-multilingual' ),
				'color' => ( 'critical' === $report['status'] ) ? 'red' : 'orange',
			),
			'description' => sprintf(
				'<p>%s</p>',
				esc_html__( 'Consulte el panel de Diagnóstico de TF Multilingual para revisar detalles sobre elementos huérfanos o duplicados.', 'tf-multilingual' )
			),
			'actions'     => '',
			'test'        => 'tfml_relations_integrity',
		);
	}

	/**
	 * Renders the diagnostic administrative page.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) {
			if ( function_exists( 'wp_die' ) ) {
				wp_die( esc_html__( 'No tiene permisos suficientes para acceder a esta página.', 'tf-multilingual' ) );
			}
			return;
		}

		$report = $this->service->run_full_diagnostic();

		$status_labels = array(
			'good'     => __( 'Salud Óptima', 'tf-multilingual' ),
			'warning'  => __( 'Atención Requerida', 'tf-multilingual' ),
			'critical' => __( 'Crítico / Inconsistencia', 'tf-multilingual' ),
		);

		$status_colors = array(
			'good'     => '#46b450',
			'warning'  => '#ffb900',
			'critical' => '#dc3232',
		);

		$overall_color = $status_colors[ $report['overall_status'] ] ?? '#999';
		$overall_label = $status_labels[ $report['overall_status'] ] ?? $report['overall_status'];

		?>
		<div class="wrap tfml-diagnostic-wrap">
			<h1><?php esc_html_e( 'TF Multilingual — Diagnóstico del Sistema y Health Check', 'tf-multilingual' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Herramienta soberana de solo lectura para auditar la integridad estructural, configuración de idiomas y consistencia del núcleo multilingüe.', 'tf-multilingual' ); ?>
			</p>

			<div style="background:#fff; border-left:4px solid <?php echo esc_attr( $overall_color ); ?>; padding:15px; margin:20px 0; box-shadow:0 1px 1px rgba(0,0,0,.04);">
				<h2 style="margin:0 0 10px 0; font-size:18px;">
					<?php esc_html_e( 'Estado General del Core:', 'tf-multilingual' ); ?>
					<span style="color:<?php echo esc_attr( $overall_color ); ?>; font-weight:bold;"><?php echo esc_html( $overall_label ); ?></span>
				</h2>
				<p style="margin:0;">
					<?php
					printf(
						/* translators: 1: Passed count, 2: Warnings count, 3: Critical count */
						esc_html__( 'Auditoría completada: %1$d módulos verificados OK, %2$d advertencias, %3$d errores críticos.', 'tf-multilingual' ),
						(int) $report['summary']['passed'],
						(int) $report['summary']['warnings'],
						(int) $report['summary']['critical']
					);
					?>
				</p>
			</div>

			<form method="post" action="">
				<?php
				if ( function_exists( 'wp_nonce_field' ) ) {
					wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
				}
				?>
				<p>
					<button type="submit" class="button button-primary">
						<?php esc_html_e( 'Volver a ejecutar auditoría', 'tf-multilingual' ); ?>
					</button>
				</p>
			</form>

			<div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); gap:20px; margin-top:20px;">
				<!-- Tarjeta 1: Entorno -->
				<div class="card" style="max-width:100%; margin:0; padding:15px;">
					<h2><?php esc_html_e( '1. Entorno de Ejecución', 'tf-multilingual' ); ?></h2>
					<table class="widefat striped" style="margin-top:10px;">
						<tbody>
							<tr>
								<td><strong><?php esc_html_e( 'Versión del Plugin', 'tf-multilingual' ); ?></strong></td>
								<td><?php echo esc_html( $report['sections']['environment']['plugin_version'] ); ?></td>
							</tr>
							<tr>
								<td><strong><?php esc_html_e( 'Versión de PHP', 'tf-multilingual' ); ?></strong></td>
								<td>
									<?php echo esc_html( $report['sections']['environment']['php_version'] ); ?>
									<?php echo $report['sections']['environment']['php_compatible'] ? ' <span style="color:#46b450;">✔ OK</span>' : ' <span style="color:#dc3232;">✖ Incompatible</span>'; ?>
								</td>
							</tr>
							<tr>
								<td><strong><?php esc_html_e( 'Versión de WordPress', 'tf-multilingual' ); ?></strong></td>
								<td>
									<?php echo esc_html( $report['sections']['environment']['wordpress_version'] ); ?>
									<?php echo $report['sections']['environment']['wordpress_compatible'] ? ' <span style="color:#46b450;">✔ OK</span>' : ' <span style="color:#dc3232;">✖ Incompatible</span>'; ?>
								</td>
							</tr>
							<tr>
								<td><strong><?php esc_html_e( 'Extensiones Requeridas', 'tf-multilingual' ); ?></strong></td>
								<td>
									<?php echo $report['sections']['environment']['all_extensions_loaded'] ? '<span style="color:#46b450;">✔ mbstring, json, hash</span>' : '<span style="color:#dc3232;">✖ Falta extensión requerida</span>'; ?>
								</td>
							</tr>
						</tbody>
					</table>
				</div>

				<!-- Tarjeta 2: Tablas de Base de Datos -->
				<div class="card" style="max-width:100%; margin:0; padding:15px;">
					<h2><?php esc_html_e( '2. Tablas Maestras SQL', 'tf-multilingual' ); ?></h2>
					<table class="widefat striped" style="margin-top:10px;">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Tabla', 'tf-multilingual' ); ?></th>
								<th><?php esc_html_e( 'Estado', 'tf-multilingual' ); ?></th>
								<th><?php esc_html_e( 'Filas', 'tf-multilingual' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $report['sections']['tables']['tables'] as $tbl ) : ?>
								<tr>
									<td><code><?php echo esc_html( $tbl['table_name'] ); ?></code></td>
									<td>
										<?php echo $tbl['exists'] ? '<span style="color:#46b450;">✔ Instalada</span>' : '<span style="color:#dc3232;">✖ No existe</span>'; ?>
									</td>
									<td><?php echo (int) $tbl['rows']; ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>

				<!-- Tarjeta 3: Idiomas -->
				<div class="card" style="max-width:100%; margin:0; padding:15px;">
					<h2><?php esc_html_e( '3. Catálogo de Idiomas', 'tf-multilingual' ); ?></h2>
					<table class="widefat striped" style="margin-top:10px;">
						<tbody>
							<tr>
								<td><strong><?php esc_html_e( 'Configurado', 'tf-multilingual' ); ?></strong></td>
								<td><?php echo $report['sections']['languages']['is_configured'] ? '✔ Sí' : '✖ No'; ?></td>
							</tr>
							<tr>
								<td><strong><?php esc_html_e( 'Idioma Predeterminado', 'tf-multilingual' ); ?></strong></td>
								<td>
									<code><?php echo esc_html( strtoupper( (string) $report['sections']['languages']['default_language'] ) ); ?></code>
									<?php echo $report['sections']['languages']['default_is_active'] ? ' <span style="color:#46b450;">(Activo)</span>' : ' <span style="color:#dc3232;">(Inactivo)</span>'; ?>
								</td>
							</tr>
							<tr>
								<td><strong><?php esc_html_e( 'Idiomas Activos', 'tf-multilingual' ); ?></strong></td>
								<td><?php echo esc_html( implode( ', ', array_map( 'strtoupper', $report['sections']['languages']['active_languages'] ) ) ); ?></td>
							</tr>
							<tr>
								<td><strong><?php esc_html_e( 'Idiomas Inactivos', 'tf-multilingual' ); ?></strong></td>
								<td><?php echo ! empty( $report['sections']['languages']['inactive_languages'] ) ? esc_html( implode( ', ', array_map( 'strtoupper', $report['sections']['languages']['inactive_languages'] ) ) ) : '<em>' . esc_html__( 'Ninguno', 'tf-multilingual' ) . '</em>'; ?></td>
							</tr>
						</tbody>
					</table>
				</div>

				<!-- Tarjeta 4: Integridad Relacional -->
				<div class="card" style="max-width:100%; margin:0; padding:15px;">
					<h2><?php esc_html_e( '4. Integridad Relacional', 'tf-multilingual' ); ?></h2>
					<table class="widefat striped" style="margin-top:10px;">
						<tbody>
							<tr>
								<td><strong><?php esc_html_e( 'Total Grupos de Traducción', 'tf-multilingual' ); ?></strong></td>
								<td><?php echo (int) $report['sections']['relations']['total_groups']; ?></td>
							</tr>
							<tr>
								<td><strong><?php esc_html_e( 'Total Elementos Vinculados', 'tf-multilingual' ); ?></strong></td>
								<td><?php echo (int) $report['sections']['relations']['total_elements']; ?></td>
							</tr>
							<tr>
								<td><strong><?php esc_html_e( 'Grupos Vacíos', 'tf-multilingual' ); ?></strong></td>
								<td>
									<?php
									$ev = (int) $report['sections']['relations']['empty_groups_count'];
									echo 0 === $ev ? '<span style="color:#46b450;">0 ✔</span>' : '<span style="color:#ffb900;">' . $ev . '</span>';
									?>
								</td>
							</tr>
							<tr>
								<td><strong><?php esc_html_e( 'Duplicados en Mismo Grupo', 'tf-multilingual' ); ?></strong></td>
								<td>
									<?php
									$dl = (int) $report['sections']['relations']['duplicate_languages_count'];
									echo 0 === $dl ? '<span style="color:#46b450;">0 ✔</span>' : '<span style="color:#dc3232;">' . $dl . ' ✖</span>';
									?>
								</td>
							</tr>
							<tr>
								<td><strong><?php esc_html_e( 'Posts Huérfanos', 'tf-multilingual' ); ?></strong></td>
								<td>
									<?php
									$op = (int) $report['sections']['relations']['orphaned_posts_count'];
									echo 0 === $op ? '<span style="color:#46b450;">0 ✔</span>' : '<span style="color:#ffb900;">' . $op . '</span>';
									?>
								</td>
							</tr>
							<tr>
								<td><strong><?php esc_html_e( 'Términos Huérfanos', 'tf-multilingual' ); ?></strong></td>
								<td>
									<?php
									$ot = (int) $report['sections']['relations']['orphaned_terms_count'];
									echo 0 === $ot ? '<span style="color:#46b450;">0 ✔</span>' : '<span style="color:#ffb900;">' . $ot . '</span>';
									?>
								</td>
							</tr>
						</tbody>
					</table>
				</div>

				<!-- Tarjeta 5: Módulos del Core -->
				<div class="card" style="max-width:100%; margin:0; padding:15px;">
					<h2><?php esc_html_e( '5. Módulos del Core', 'tf-multilingual' ); ?></h2>
					<table class="widefat striped" style="margin-top:10px;">
						<tbody>
							<tr>
								<td><strong><?php esc_html_e( 'Media Multilingüe', 'tf-multilingual' ); ?></strong></td>
								<td>✔ <?php esc_html_e( 'Activo', 'tf-multilingual' ); ?> (<?php echo (int) $report['sections']['modules']['media']['total_translations']; ?> <?php esc_html_e( 'traducciones', 'tf-multilingual' ); ?>)</td>
							</tr>
							<tr>
								<td><strong><?php esc_html_e( 'Cadenas de Texto (Strings)', 'tf-multilingual' ); ?></strong></td>
								<td>✔ <?php esc_html_e( 'Activo', 'tf-multilingual' ); ?> (<?php echo (int) $report['sections']['modules']['strings']['total_strings']; ?> <?php esc_html_e( 'cadenas registradas', 'tf-multilingual' ); ?>)</td>
							</tr>
							<tr>
								<td><strong><?php esc_html_e( 'Menús y Navegación', 'tf-multilingual' ); ?></strong></td>
								<td>✔ <?php esc_html_e( 'Activo', 'tf-multilingual' ); ?> (<?php echo (int) $report['sections']['modules']['menus']['locations_mapped']; ?> <?php esc_html_e( 'ubicaciones configuradas', 'tf-multilingual' ); ?>)</td>
							</tr>
							<tr>
								<td><strong><?php esc_html_e( 'SEO y Sitemaps', 'tf-multilingual' ); ?></strong></td>
								<td>✔ <?php esc_html_e( 'Activo (Hreflang, Canonical, XML Sitemaps)', 'tf-multilingual' ); ?></td>
							</tr>
							<tr>
								<td><strong><?php esc_html_e( 'REST API', 'tf-multilingual' ); ?></strong></td>
								<td>✔ <?php esc_html_e( 'Activo', 'tf-multilingual' ); ?> (<code><?php echo esc_html( $report['sections']['modules']['rest']['namespace'] ); ?></code>)</td>
							</tr>
						</tbody>
					</table>
				</div>

				<!-- Tarjeta 6: URLs y Enrutamiento -->
				<div class="card" style="max-width:100%; margin:0; padding:15px;">
					<h2><?php esc_html_e( '6. Enrutamiento y URLs', 'tf-multilingual' ); ?></h2>
					<table class="widefat striped" style="margin-top:10px;">
						<tbody>
							<tr>
								<td><strong><?php esc_html_e( 'Estructura Canónica', 'tf-multilingual' ); ?></strong></td>
								<td><code><?php echo esc_html( $report['sections']['rewrites']['permalink_structure'] ); ?></code></td>
							</tr>
							<tr>
								<td><strong><?php esc_html_e( 'Pretty Permalinks', 'tf-multilingual' ); ?></strong></td>
								<td>
									<?php echo $report['sections']['rewrites']['using_pretty_permalinks'] ? '<span style="color:#46b450;">✔ Habilitado</span>' : '<span style="color:#ffb900;">⚠ Enlaces simples</span>'; ?>
								</td>
							</tr>
							<tr>
								<td><strong><?php esc_html_e( 'Prefijos Lingüísticos', 'tf-multilingual' ); ?></strong></td>
								<td>
									<?php
									$prefixes = $report['sections']['rewrites']['active_prefixes'];
									echo ! empty( $prefixes ) ? esc_html( implode( ', ', array_map( fn( $p ) => '/' . $p . '/', $prefixes ) ) ) : '<em>' . esc_html__( 'Solo idioma por defecto (sin prefijo)', 'tf-multilingual' ) . '</em>';
									?>
								</td>
							</tr>
						</tbody>
					</table>
				</div>
			</div>
		</div>
		<?php
	}
}
