<?php
/**
 * Pruebas Unitarias del Resolvedor Nativo de Portada Multilingüe (DT-TFML-063 — Fase 3.33).
 *
 * @package TF\Multilingual\Tests\Unit
 */

declare( strict_types=1 );

namespace TF\Multilingual\Tests\Unit;

use PHPUnit\Framework\TestCase;
use stdClass;
use TF\Multilingual\Domain\Language\Language;
use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Domain\Language\SettingsRepository;
use TF\Multilingual\Domain\Navigation\NavMenuFrontendFilter;
use TF\Multilingual\Domain\Navigation\NavMenuLocationRepository;
use TF\Multilingual\Domain\Seo\CanonicalUrlManager;
use TF\Multilingual\Domain\Seo\CoreSitemapsFilter;
use TF\Multilingual\Domain\Seo\HreflangGenerator;
use TF\Multilingual\Domain\Translation\ContentTranslationResolver;
use TF\Multilingual\Domain\Translation\TranslationGroupRepository;
use TF\Multilingual\Query\QueryLanguageFilter;
use TF\Multilingual\Routing\CurrentLanguageResolver;
use TF\Multilingual\Routing\LocalizedUrlGenerator;
use TF\Multilingual\Routing\ResolvedorPortadaMultilingue;
use TF\Multilingual\Routing\RewriteManager;
use TF\Multilingual\Routing\UrlLanguageResolver;
use TF\Multilingual\Tests\Doubles\TestableWpdb;
use WP_Post;
use WP_Query;

/**
 * Clase ResolvedorPortadaMultilingueTest
 *
 * Cubre los 22 escenarios obligatorios de la Sección 10 de DT-TFML-063.
 */
class ResolvedorPortadaMultilingueTest extends TestCase {

	/**
	 * Doble de base de datos en memoria.
	 *
	 * @var TestableWpdb
	 */
	private TestableWpdb $wpdb;

	/**
	 * Repositorio de ajustes.
	 *
	 * @var SettingsRepository
	 */
	private SettingsRepository $settings_repo;

	/**
	 * Registro de idiomas.
	 *
	 * @var LanguageRegistry
	 */
	private LanguageRegistry $registro_idiomas;

	/**
	 * Repositorio de grupos de traducción.
	 *
	 * @var TranslationGroupRepository
	 */
	private TranslationGroupRepository $repositorio_grupos;

	/**
	 * Resolvedor de traducciones de contenido.
	 *
	 * @var ContentTranslationResolver
	 */
	private ContentTranslationResolver $resolvedor_traducciones;

	/**
	 * Resolvedor de idioma desde URL.
	 *
	 * @var UrlLanguageResolver
	 */
	private UrlLanguageResolver $resolvedor_url;

	/**
	 * Resolvedor de idioma actual.
	 *
	 * @var CurrentLanguageResolver
	 */
	private CurrentLanguageResolver $resolutor_idioma_actual;

	/**
	 * Generador de URLs localizadas.
	 *
	 * @var LocalizedUrlGenerator
	 */
	private LocalizedUrlGenerator $generador_urls;

	/**
	 * Resolvedor de portada bajo prueba.
	 *
	 * @var ResolvedorPortadaMultilingue
	 */
	private ResolvedorPortadaMultilingue $resolvedor_portada;

	/**
	 * Gestor de URLs canónicas.
	 *
	 * @var CanonicalUrlManager
	 */
	private CanonicalUrlManager $gestor_canonico;

	/**
	 * Generador de etiquetas hreflang.
	 *
	 * @var HreflangGenerator
	 */
	private HreflangGenerator $generador_hreflang;

	/**
	 * Configura el entorno aislado antes de cada prueba.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->wpdb         = new TestableWpdb();
		$this->wpdb->prefix = 'wp_';
		$GLOBALS['wpdb']    = $this->wpdb;

		$GLOBALS['wp_test_options']           = array();
		$GLOBALS['wp_test_posts']             = array();
		$GLOBALS['wp_test_post_status']       = array();
		$GLOBALS['wp_test_permalinks']        = array();
		$GLOBALS['wp_test_terms']             = array();
		$GLOBALS['wp_test_term_links']        = array();
		$GLOBALS['wp_test_filters']           = array();
		$GLOBALS['wp_test_actions']           = array();
		$GLOBALS['wp_test_is_singular']       = false;
		$GLOBALS['wp_test_is_category']       = false;
		$GLOBALS['wp_test_is_tag']            = false;
		$GLOBALS['wp_test_is_tax']            = false;
		$GLOBALS['wp_test_is_front_page']     = false;
		$GLOBALS['wp_test_is_home']           = false;
		$GLOBALS['wp_test_is_404']            = false;
		$GLOBALS['wp_test_is_search']         = false;
		$GLOBALS['wp_test_is_feed']           = false;
		$GLOBALS['wp_test_is_trackback']      = false;
		$GLOBALS['wp_test_is_preview']        = false;
		$GLOBALS['wp_test_is_sitemap']        = false;
		$GLOBALS['wp_test_queried_object']    = null;
		$GLOBALS['wp_test_queried_object_id'] = 0;
		$GLOBALS['wp_test_home_url']          = 'http://lab.ecoterra.test';

		$this->settings_repo    = new SettingsRepository();
		$this->registro_idiomas = new LanguageRegistry( $this->settings_repo );

		$es = Language::create( 'es', 'es_ES', 'Spanish', 'Español', true, 1 );
		$en = Language::create( 'en', 'en_US', 'English', 'English', true, 2 );
		$fr = Language::create( 'fr', 'fr_FR', 'French', 'Français', false, 3 ); // Inactivo.

		$this->registro_idiomas->add_language( $es, true );
		$this->registro_idiomas->add_language( $en );
		$this->registro_idiomas->add_language( $fr );

		$this->repositorio_grupos      = new TranslationGroupRepository( $this->wpdb, $this->registro_idiomas );
		$this->resolvedor_traducciones = new ContentTranslationResolver( $this->repositorio_grupos, $this->registro_idiomas );
		$this->resolvedor_url          = new UrlLanguageResolver( $this->registro_idiomas, 'http://lab.ecoterra.test' );
		$this->resolutor_idioma_actual = new CurrentLanguageResolver( $this->resolvedor_url );
		$this->generador_urls          = new LocalizedUrlGenerator(
			$this->registro_idiomas,
			$this->resolvedor_url,
			$this->resolvedor_traducciones,
			'http://lab.ecoterra.test'
		);

		$this->resolvedor_portada = new ResolvedorPortadaMultilingue(
			$this->registro_idiomas,
			$this->resolutor_idioma_actual,
			$this->resolvedor_traducciones,
			$this->generador_urls
		);
		$this->resolvedor_portada->habilitar_en_cli_para_pruebas( true );
		$this->generador_urls->set_resolvedor_portada( $this->resolvedor_portada );

		$this->gestor_canonico = new CanonicalUrlManager(
			$this->registro_idiomas,
			$this->resolvedor_traducciones,
			$this->generador_urls,
			$this->resolvedor_url
		);

		$this->generador_hreflang = new HreflangGenerator(
			$this->registro_idiomas,
			$this->repositorio_grupos,
			$this->resolvedor_traducciones,
			$this->generador_urls
		);
	}

	/**
	 * Limpia las variables globales tras cada prueba.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$this->resolvedor_portada->desregistrar_ganchos();
		$GLOBALS['wp_test_filters']         = array();
		$GLOBALS['wp_test_actions']         = array();
		$GLOBALS['wp_test_is_admin']        = false;
		$GLOBALS['wp_test_doing_cron']      = false;
		$GLOBALS['wp_test_doing_ajax']      = false;
		$GLOBALS['wp_test_is_json_request'] = false;
		parent::tearDown();
	}

	/**
	 * Crea y registra una página simulada en memoria.
	 *
	 * @param int    $id     ID de la página.
	 * @param string $slug   Slug de la página.
	 * @param string $titulo Título de la página.
	 * @param string $estado Estado de publicación ('publish', 'draft', 'trash').
	 * @return WP_Post
	 */
	private function crear_pagina( int $id, string $slug, string $titulo, string $estado = 'publish' ): WP_Post {
		$post              = new WP_Post();
		$post->ID          = $id;
		$post->post_type   = 'page';
		$post->post_status = $estado;
		$post->post_name   = $slug;
		$post->post_title  = $titulo;

		$GLOBALS['wp_test_posts'][ $id ]       = $post;
		$GLOBALS['wp_test_post_status'][ $id ] = $estado;
		$GLOBALS['wp_test_permalinks'][ $id ]  = 'http://lab.ecoterra.test/' . trim( $slug, '/' ) . '/';

		return $post;
	}

	/**
	 * Configura el escenario base bilingüe de portada estática (ES=197 <-> EN=8356).
	 *
	 * @param string $estado_en Estado de la traducción en inglés.
	 * @return int ID del grupo de traducción creado.
	 */
	private function configurar_portada_bilingue( string $estado_en = 'publish' ): int {
		$GLOBALS['wp_test_options']['show_on_front'] = 'page';
		$GLOBALS['wp_test_options']['page_on_front'] = 197;

		$this->crear_pagina( 197, 'inicio', 'Inicio', 'publish' );
		$this->crear_pagina( 8356, 'home', 'Home', $estado_en );

		$grupo = $this->repositorio_grupos->create_group( 'post', 'page', 197, 'es' );
		$this->repositorio_grupos->add_translation( $grupo->get_id(), 8356, 'en' );

		return $grupo->get_id();
	}

	/**
	 * Escenario 1: `show_on_front = 'posts'` no altera el archivo principal de entradas.
	 */
	public function test_show_on_front_posts_no_altera_archivo_principal(): void {
		$GLOBALS['wp_test_options']['show_on_front'] = 'posts';
		$GLOBALS['wp_test_options']['page_on_front'] = 197;
		$this->crear_pagina( 197, 'inicio', 'Inicio', 'publish' );
		$this->crear_pagina( 8356, 'home', 'Home', 'publish' );

		$grupo = $this->repositorio_grupos->create_group( 'post', 'page', 197, 'es' );
		$this->repositorio_grupos->add_translation( $grupo->get_id(), 8356, 'en' );

		$this->resolutor_idioma_actual->set_current_language( 'en' );

		$this->assertFalse( $this->resolvedor_portada->es_modo_portada_estatica() );
		$this->assertNull( $this->resolvedor_portada->obtener_id_portada_para_idioma( 'en' ) );

		$consulta                = new WP_Query( array( RewriteManager::QUERY_VAR => 'en' ) );
		$consulta->is_main_query = true;
		$consulta->is_home       = true;

		$this->resolvedor_portada->normalizar_consulta_principal_portada( $consulta );

		$this->assertTrue( $consulta->is_home );
		$this->assertFalse( $consulta->is_page );
		$this->assertSame( '', $consulta->get( 'page_id', '' ) );
	}

	/**
	 * Escenario 2: `show_on_front = 'page'` con `page_on_front = 0` no activa resolución de portada estática.
	 */
	public function test_show_on_front_page_con_page_on_front_cero_no_activa_resolucion(): void {
		$GLOBALS['wp_test_options']['show_on_front'] = 'page';
		$GLOBALS['wp_test_options']['page_on_front'] = 0;

		$this->resolutor_idioma_actual->set_current_language( 'en' );

		$this->assertFalse( $this->resolvedor_portada->es_modo_portada_estatica() );
		$this->assertSame( 0, $this->resolvedor_portada->obtener_id_portada_base() );
		$this->assertNull( $this->resolvedor_portada->obtener_id_portada_para_idioma( 'es' ) );
		$this->assertNull( $this->resolvedor_portada->obtener_id_portada_para_idioma( 'en' ) );
		$this->assertSame( 0, $this->resolvedor_portada->filtrar_opcion_page_on_front( 0 ) );
	}

	/**
	 * Escenario 3: Idioma por defecto `/` resuelve la página configurada en `page_on_front`.
	 */
	public function test_idioma_por_defecto_resuelve_portada_base(): void {
		$this->configurar_portada_bilingue();
		$this->resolvedor_portada->registrar_ganchos();
		$this->resolutor_idioma_actual->set_current_language( 'es' );

		$this->assertTrue( $this->resolvedor_portada->es_modo_portada_estatica() );
		$this->assertSame( 197, $this->resolvedor_portada->obtener_id_portada_base() );
		$this->assertSame( 197, $this->resolvedor_portada->obtener_id_portada_para_idioma( 'es' ) );
		$this->assertSame( 197, $this->resolvedor_portada->obtener_id_portada_actual() );
		$this->assertSame( 197, (int) get_option( 'page_on_front' ) );
		$this->assertTrue( $this->resolvedor_portada->es_portada_de_idioma( 197, 'es' ) );
		$this->assertFalse( $this->resolvedor_portada->es_portada_de_idioma( 197, 'en' ) );
	}

	/**
	 * Escenario 4: Idioma secundario activo `/en/` con traducción existente resuelve la página traducida.
	 */
	public function test_idioma_secundario_activo_resuelve_portada_traducida(): void {
		$this->configurar_portada_bilingue();
		$this->resolvedor_portada->registrar_ganchos();
		$this->resolutor_idioma_actual->set_current_language( 'en' );

		$this->assertSame( 197, $this->resolvedor_portada->obtener_id_portada_base() );
		$this->assertSame( 8356, $this->resolvedor_portada->obtener_id_portada_para_idioma( 'en' ) );
		$this->assertSame( 8356, $this->resolvedor_portada->obtener_id_portada_actual() );
		$this->assertSame( 8356, (int) get_option( 'page_on_front' ) );
		$this->assertTrue( $this->resolvedor_portada->es_portada_de_idioma( 8356, 'en' ) );
		$this->assertSame( 'en', $this->resolvedor_portada->obtener_idioma_de_portada( 8356 ) );

		// Verificar normalización de WP_Query para GET /en/.
		$consulta                = new WP_Query( array( RewriteManager::QUERY_VAR => 'en' ) );
		$consulta->is_main_query = true;
		$consulta->is_home       = true;

		$this->resolvedor_portada->normalizar_consulta_principal_portada( $consulta );

		$this->assertTrue( $consulta->is_page );
		$this->assertTrue( $consulta->is_singular );
		$this->assertFalse( $consulta->is_home );
		$this->assertSame( 8356, $consulta->get( 'page_id' ) );
		$this->assertFalse( $consulta->get( ResolvedorPortadaMultilingue::QUERY_VAR_PORTADA_AUSENTE ) );
		$this->assertTrue( $consulta->get( ResolvedorPortadaMultilingue::QUERY_VAR_NORMALIZADA ) );

		// Persistencia intacta: el valor crudo en wp_test_options continúa siendo 197.
		$this->assertSame( 197, $GLOBALS['wp_test_options']['page_on_front'] );
	}

	/**
	 * Escenario 5: Idioma secundario activo sin traducción de portada aplica la política definida sin fallback al español.
	 */
	public function test_idioma_secundario_activo_sin_traduccion_aplica_politica_sin_fallback(): void {
		$GLOBALS['wp_test_options']['show_on_front'] = 'page';
		$GLOBALS['wp_test_options']['page_on_front'] = 197;

		// Solo existe la página en español (197); inglés ('en') no tiene traducción en el grupo.
		$this->crear_pagina( 197, 'inicio', 'Inicio', 'publish' );
		$this->repositorio_grupos->create_group( 'post', 'page', 197, 'es' );

		$this->resolvedor_portada->registrar_ganchos();
		$this->resolutor_idioma_actual->set_current_language( 'en' );

		$this->assertNull( $this->resolvedor_portada->obtener_id_portada_para_idioma( 'en' ) );
		// option_page_on_front conserva el ID base (197) para que QueryLanguageFilter bloquee el post español.
		$this->assertSame( 197, (int) get_option( 'page_on_front' ) );

		$consulta                = new WP_Query( array( RewriteManager::QUERY_VAR => 'en' ) );
		$consulta->is_main_query = true;
		$consulta->is_home       = true;

		$this->resolvedor_portada->normalizar_consulta_principal_portada( $consulta );

		// La consulta no degrada a archivo de blog (is_home=false) y fija page_id=197 con bandera de portada ausente.
		$this->assertTrue( $consulta->is_page );
		$this->assertFalse( $consulta->is_home );
		$this->assertSame( 197, $consulta->get( 'page_id' ) );
		$this->assertTrue( $consulta->get( ResolvedorPortadaMultilingue::QUERY_VAR_PORTADA_AUSENTE ) );

		// Verificar que QueryLanguageFilter añade la restricción estricta de 'en' que producirá 0 filas (404).
		$filtro_query = new QueryLanguageFilter( $this->registro_idiomas, $this->resolutor_idioma_actual, $this->wpdb );
		$filtro_query->on_pre_get_posts( $consulta );
		$clausulas = $filtro_query->filter_posts_clauses(
			array(
				'join'  => '',
				'where' => 'AND wp_posts.ID = 197',
			),
			$consulta
		);

		$this->assertStringContainsString( "AND tfml_ge.language_code = 'en'", $clausulas['where'] );
		$this->assertStringNotContainsString( 'IS NULL', $clausulas['where'] );

		// Verificar que HreflangGenerator NO publica hreflang="en" hacia una portada inexistente.
		$variantes_hreflang = $this->generador_hreflang->get_front_page_hreflang_variants();
		$this->assertArrayHasKey( 'es', $variantes_hreflang );
		$this->assertArrayNotHasKey( 'en', $variantes_hreflang );
	}

	/**
	 * Escenario 6: Idioma inactivo `/fr/` no resuelve portada aunque exista página francesa.
	 */
	public function test_idioma_inactivo_fr_no_resuelve_portada(): void {
		$this->configurar_portada_bilingue();
		$this->crear_pagina( 8839, 'accueil', 'Accueil', 'publish' );

		$this->assertFalse( $this->registro_idiomas->is_active( 'fr' ) );
		$this->assertNull( $this->resolvedor_portada->obtener_id_portada_para_idioma( 'fr' ) );
		$this->assertFalse( $this->resolvedor_portada->es_portada_de_idioma( 8839, 'fr' ) );
		$this->assertNull( $this->resolvedor_portada->obtener_idioma_de_portada( 8839 ) );
	}

	/**
	 * Escenario 7: Idioma inexistente `/de/` o `/xx/` no resuelve portada.
	 */
	public function test_idioma_inexistente_no_resuelve_portada(): void {
		$this->configurar_portada_bilingue();

		$this->assertNull( $this->resolvedor_portada->obtener_id_portada_para_idioma( 'de' ) );
		$this->assertNull( $this->resolvedor_portada->obtener_id_portada_para_idioma( 'xx' ) );
		$this->assertFalse( $this->resolvedor_portada->es_portada_de_idioma( 8356, 'de' ) );
	}

	/**
	 * Escenario 8: Traducción de portada en `draft` no se sirve como portada pública.
	 */
	public function test_traduccion_portada_en_draft_no_se_sirve_como_portada_publica(): void {
		$this->configurar_portada_bilingue( 'draft' );
		$this->resolvedor_portada->registrar_ganchos();
		$this->resolutor_idioma_actual->set_current_language( 'en' );

		$this->assertNull( $this->resolvedor_portada->obtener_id_portada_para_idioma( 'en' ) );
		$this->assertFalse( $this->resolvedor_portada->es_portada_de_idioma( 8356, 'en' ) );
		$this->assertNull( $this->resolvedor_portada->obtener_idioma_de_portada( 8356 ) );
		$this->assertSame( 197, (int) get_option( 'page_on_front' ) );
	}

	/**
	 * Escenario 9: Traducción de portada en `trash` no se sirve como portada pública.
	 */
	public function test_traduccion_portada_en_trash_no_se_sirve_como_portada_publica(): void {
		$this->configurar_portada_bilingue( 'trash' );
		$this->resolvedor_portada->registrar_ganchos();
		$this->resolutor_idioma_actual->set_current_language( 'en' );

		$this->assertNull( $this->resolvedor_portada->obtener_id_portada_para_idioma( 'en' ) );
		$this->assertFalse( $this->resolvedor_portada->es_portada_de_idioma( 8356, 'en' ) );
		$this->assertNull( $this->resolvedor_portada->obtener_idioma_de_portada( 8356 ) );
		$this->assertSame( 197, (int) get_option( 'page_on_front' ) );
	}

	/**
	 * Escenario 10: `page_link` / `get_permalink()` de la portada secundaria devuelve `/en/`.
	 */
	public function test_filtrar_enlace_pagina_devuelve_raiz_de_idioma(): void {
		$this->configurar_portada_bilingue();
		$this->crear_pagina( 500, 'nosotros', 'Nosotros', 'publish' );

		// Portada ES (197) -> http://lab.ecoterra.test/
		$enlace_es = $this->resolvedor_portada->filtrar_enlace_pagina( 'http://lab.ecoterra.test/inicio/', 197, false );
		$this->assertSame( 'http://lab.ecoterra.test/', $enlace_es );

		// Portada EN (8356) -> http://lab.ecoterra.test/en/
		$enlace_en = $this->resolvedor_portada->filtrar_enlace_pagina( 'http://lab.ecoterra.test/home/', 8356, false );
		$this->assertSame( 'http://lab.ecoterra.test/en/', $enlace_en );

		// Si sample=true (editor de slug en WP-Admin), preserva el enlace original con slug.
		$enlace_muestra = $this->resolvedor_portada->filtrar_enlace_pagina( 'http://lab.ecoterra.test/home/', 8356, true );
		$this->assertSame( 'http://lab.ecoterra.test/home/', $enlace_muestra );

		// Página común (500) -> no se transforma en raíz.
		$enlace_comun = $this->resolvedor_portada->filtrar_enlace_pagina( 'http://lab.ecoterra.test/nosotros/', 500, false );
		$this->assertSame( 'http://lab.ecoterra.test/nosotros/', $enlace_comun );
	}

	/**
	 * Escenario 11: `LocalizedUrlGenerator` para la portada secundaria devuelve `/en/` y para la principal `/`.
	 */
	public function test_localized_url_generator_resuelve_portada_a_raiz_de_idioma(): void {
		$this->configurar_portada_bilingue();

		$this->assertSame( 'http://lab.ecoterra.test/en/', $this->generador_urls->get_post_translation_url( 197, 'en' ) );
		$this->assertSame( 'http://lab.ecoterra.test/', $this->generador_urls->get_post_translation_url( 8356, 'es' ) );
		$this->assertSame( 'http://lab.ecoterra.test/en/', $this->generador_urls->get_post_translation_url( 8356, 'en' ) );
		$this->assertSame( 'http://lab.ecoterra.test/', $this->generador_urls->get_post_translation_url( 197, 'es' ) );
	}

	/**
	 * Escenario 12: `CanonicalUrlManager` en `/` devuelve `http://.../`; en `/en/` devuelve `http://.../en/`; en 404 devuelve vacío.
	 */
	public function test_canonical_url_manager_en_portada_principal_secundaria_y_404(): void {
		$this->configurar_portada_bilingue();

		// Portada española (post 197).
		$canon_es = $this->gestor_canonico->filter_canonical_url( 'http://lab.ecoterra.test/inicio/', 197 );
		$this->assertSame( 'http://lab.ecoterra.test/', $canon_es );

		// Portada inglesa (post 8356), incluso si WordPress pasara /home/ o / como base.
		$canon_en = $this->gestor_canonico->filter_canonical_url( 'http://lab.ecoterra.test/home/', 8356 );
		$this->assertSame( 'http://lab.ecoterra.test/en/', $canon_en );

		// En contexto 404 no debe emitir canonical engañoso.
		$GLOBALS['wp_test_is_404'] = true;
		$this->assertSame( '', $this->gestor_canonico->filter_canonical_url( 'http://lab.ecoterra.test/en/', 8356 ) );
	}

	/**
	 * Escenario 13: `HreflangGenerator` en `/` y `/en/` publica enlaces simétricos hacia `/` y `/en/` más `x-default`,
	 * y omite variantes de portada no publicadas (`draft`).
	 */
	public function test_hreflang_generator_simetrico_en_portadas_y_omite_no_publicadas(): void {
		$this->configurar_portada_bilingue( 'publish' );

		$variantes_desde_es = $this->generador_hreflang->get_post_hreflang_variants( 197 );
		$variantes_desde_en = $this->generador_hreflang->get_post_hreflang_variants( 8356 );
		$variantes_front    = $this->generador_hreflang->get_front_page_hreflang_variants();

		$esperado = array(
			'es'        => 'http://lab.ecoterra.test/',
			'en'        => 'http://lab.ecoterra.test/en/',
			'x-default' => 'http://lab.ecoterra.test/',
		);

		$this->assertSame( $esperado, $variantes_desde_es );
		$this->assertSame( $esperado, $variantes_desde_en );
		$this->assertSame( $esperado, $variantes_front );
		$this->assertArrayNotHasKey( 'fr', $variantes_desde_es );

		// Si la variante inglesa pasa a 'draft', debe omitirse de hreflang.
		$GLOBALS['wp_test_posts'][8356]->post_status = 'draft';
		$GLOBALS['wp_test_post_status'][8356]        = 'draft';

		$variantes_con_draft = $this->generador_hreflang->get_post_hreflang_variants( 197 );
		$this->assertArrayHasKey( 'es', $variantes_con_draft );
		$this->assertArrayNotHasKey( 'en', $variantes_con_draft );
		$this->assertSame( 'http://lab.ecoterra.test/', $variantes_con_draft['x-default'] );
	}

	/**
	 * Escenario 14: Menú de navegación con enlace hacia la portada resuelve a `/en/` en inglés.
	 */
	public function test_menu_navegacion_hacia_portada_secundaria_resuelve_a_raiz_en(): void {
		$this->configurar_portada_bilingue();
		$this->resolutor_idioma_actual->set_current_language( 'en' );

		$location_repo = new NavMenuLocationRepository(
			$this->settings_repo,
			$this->registro_idiomas,
			$this->resolvedor_traducciones
		);
		$menu_filter   = new NavMenuFrontendFilter(
			$location_repo,
			$this->resolutor_idioma_actual,
			$this->registro_idiomas,
			$this->resolvedor_traducciones,
			$this->generador_urls
		);

		$item_portada            = new WP_Post();
		$item_portada->ID        = 901;
		$item_portada->post_type = 'nav_menu_item';
		$item_portada->type      = 'post_type';
		$item_portada->object    = 'page';
		$item_portada->object_id = '197';
		$item_portada->url       = 'http://lab.ecoterra.test/';

		$items_procesados = $menu_filter->filter_wp_nav_menu_objects( array( $item_portada ), new stdClass() );

		$this->assertSame( '8356', $items_procesados[0]->object_id );
		$this->assertSame( 'http://lab.ecoterra.test/en/', $items_procesados[0]->url );
	}

	/**
	 * Escenario 15: Acceso directo al slug de la portada secundaria (`/en/home/`) se redirige a `/en/`,
	 * mientras que el acceso directo a `/en/` cancela la redirección redundante.
	 */
	public function test_redirect_canonical_redirige_slug_portada_secundaria_y_protege_raiz(): void {
		$this->configurar_portada_bilingue();
		$this->resolutor_idioma_actual->set_current_language( 'en' );
		$GLOBALS['wp_test_queried_object_id'] = 8356;

		// 1. Petición a /en/home/ -> WordPress Core calcula home_url('/') y el filtro redirige a http://lab.ecoterra.test/en/.
		$redireccion_desde_slug = $this->resolvedor_portada->filtrar_redirect_canonical(
			'http://lab.ecoterra.test/',
			'http://lab.ecoterra.test/en/home/'
		);
		$this->assertSame( 'http://lab.ecoterra.test/en/', $redireccion_desde_slug );

		// Preservación de query string al redirigir /en/home/?utm_source=test -> /en/?utm_source=test.
		$redireccion_con_query = $this->resolvedor_portada->filtrar_redirect_canonical(
			'http://lab.ecoterra.test/?utm_source=test',
			'http://lab.ecoterra.test/en/home/?utm_source=test'
		);
		$this->assertSame( 'http://lab.ecoterra.test/en/?utm_source=test', $redireccion_con_query );

		// 2. Petición ya en la raíz canónica /en/ -> retorna false para evitar redirigir /en/ hacia /.
		$redireccion_en_raiz = $this->resolvedor_portada->filtrar_redirect_canonical(
			'http://lab.ecoterra.test/',
			'http://lab.ecoterra.test/en/'
		);
		$this->assertFalse( $redireccion_en_raiz );

		// 3. En 404 de idioma secundario (ej. /en/old-blog/), impide redirección adivinada hacia el idioma por defecto.
		$GLOBALS['wp_test_is_404'] = true;
		$redireccion_en_404        = $this->resolvedor_portada->filtrar_redirect_canonical(
			'http://lab.ecoterra.test/old-blog/',
			'http://lab.ecoterra.test/en/old-blog/'
		);
		$this->assertFalse( $redireccion_en_404 );
	}

	/**
	 * Escenario 16: Página común no configurada como portada (incluso con slug `home`) NO se redirige a `/en/`.
	 */
	public function test_pagina_comun_con_slug_home_no_se_redirige_a_raiz(): void {
		$this->configurar_portada_bilingue();
		// Crear una página ordinaria (ID 9999) con slug 'home-landing' o 'home' no perteneciente al grupo de portada.
		$this->crear_pagina( 9999, 'home', 'Other Home Page', 'publish' );
		$grupo_otro = $this->repositorio_grupos->create_group( 'post', 'page', 9998, 'es' );
		$this->repositorio_grupos->add_translation( $grupo_otro->get_id(), 9999, 'en' );

		$this->resolutor_idioma_actual->set_current_language( 'en' );
		$GLOBALS['wp_test_queried_object_id'] = 9999;

		$this->assertFalse( $this->resolvedor_portada->es_portada_de_idioma( 9999, 'en' ) );

		$url_original = 'http://lab.ecoterra.test/en/home/';
		$resultado    = $this->resolvedor_portada->filtrar_redirect_canonical( $url_original, $url_original );
		$this->assertSame( $url_original, $resultado );
	}

	/**
	 * Escenario 17: `is_admin()` no altera `page_on_front` ni normaliza consultas.
	 */
	public function test_is_admin_no_altera_page_on_front(): void {
		$this->configurar_portada_bilingue();
		$this->resolvedor_portada->registrar_ganchos();
		$this->resolutor_idioma_actual->set_current_language( 'en' );

		$GLOBALS['wp_test_is_admin'] = true;

		$this->assertFalse( $this->resolvedor_portada->es_contexto_publico_elegible() );
		$this->assertSame( 197, (int) get_option( 'page_on_front' ) );
	}

	/**
	 * Escenario 18: Petición REST (`wp_is_json_request()`) no altera `page_on_front`.
	 */
	public function test_rest_request_no_altera_page_on_front(): void {
		$this->configurar_portada_bilingue();
		$this->resolvedor_portada->registrar_ganchos();
		$this->resolutor_idioma_actual->set_current_language( 'en' );

		$GLOBALS['wp_test_is_json_request'] = true;

		$this->assertFalse( $this->resolvedor_portada->es_contexto_publico_elegible() );
		$this->assertSame( 197, (int) get_option( 'page_on_front' ) );
	}

	/**
	 * Escenario 19: `wp_doing_cron()` no altera `page_on_front`.
	 */
	public function test_wp_doing_cron_no_altera_page_on_front(): void {
		$this->configurar_portada_bilingue();
		$this->resolvedor_portada->registrar_ganchos();
		$this->resolutor_idioma_actual->set_current_language( 'en' );

		$GLOBALS['wp_test_doing_cron'] = true;

		$this->assertFalse( $this->resolvedor_portada->es_contexto_publico_elegible() );
		$this->assertSame( 197, (int) get_option( 'page_on_front' ) );
	}

	/**
	 * Escenario 20: `wp_doing_ajax()` no altera `page_on_front`.
	 */
	public function test_wp_doing_ajax_no_altera_page_on_front(): void {
		$this->configurar_portada_bilingue();
		$this->resolvedor_portada->registrar_ganchos();
		$this->resolutor_idioma_actual->set_current_language( 'en' );

		$GLOBALS['wp_test_doing_ajax'] = true;

		$this->assertFalse( $this->resolvedor_portada->es_contexto_publico_elegible() );
		$this->assertSame( 197, (int) get_option( 'page_on_front' ) );
	}

	/**
	 * Escenario 21: `WP_CLI` sin habilitación de pruebas y consultas secundarias no alteran `page_on_front` ni `WP_Query`.
	 */
	public function test_wp_cli_y_consultas_secundarias_no_alteran_portada(): void {
		$this->configurar_portada_bilingue();
		$this->resolutor_idioma_actual->set_current_language( 'en' );

		$this->resolvedor_portada->habilitar_en_cli_para_pruebas( false );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			$this->assertFalse( $this->resolvedor_portada->es_contexto_publico_elegible() );
			$this->assertSame( 197, $this->resolvedor_portada->filtrar_opcion_page_on_front( 197 ) );
		}

		$this->resolvedor_portada->habilitar_en_cli_para_pruebas( true );
		$consulta_secundaria                = new WP_Query( array( RewriteManager::QUERY_VAR => 'en' ) );
		$consulta_secundaria->is_main_query = false;
		$consulta_secundaria->is_home       = true;

		$this->resolvedor_portada->normalizar_consulta_principal_portada( $consulta_secundaria );
		$this->assertTrue( $consulta_secundaria->is_home );
		$this->assertFalse( $consulta_secundaria->is_page );

		$consulta_busqueda                = new WP_Query(
			array(
				RewriteManager::QUERY_VAR => 'en',
				's'                       => 'cusco',
			)
		);
		$consulta_busqueda->is_main_query = true;
		$consulta_busqueda->is_search     = true;

		$this->resolvedor_portada->normalizar_consulta_principal_portada( $consulta_busqueda );
		$this->assertFalse( $consulta_busqueda->is_page );
		$this->assertSame( '', $consulta_busqueda->get( 'page_id', '' ) );
	}

	/**
	 * Escenario 22: Inmutabilidad de `page_on_front` y `show_on_front` en opciones persistidas y protección antirrecursión.
	 */
	public function test_inmutabilidad_de_opciones_persistidas_y_proteccion_antirrecursion(): void {
		$this->configurar_portada_bilingue();
		$this->resolvedor_portada->registrar_ganchos();
		$this->resolutor_idioma_actual->set_current_language( 'en' );

		for ( $i = 0; $i < 5; $i++ ) {
			$this->assertSame( 8356, (int) get_option( 'page_on_front' ) );
		}

		$this->assertSame( 197, $GLOBALS['wp_test_options']['page_on_front'] );
		$this->assertSame( 'page', $GLOBALS['wp_test_options']['show_on_front'] );
		$this->assertSame( 197, $this->resolvedor_portada->obtener_id_portada_base() );
	}
}
