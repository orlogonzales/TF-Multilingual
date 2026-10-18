<?php
/**
 * Pruebas unitarias para la API pública de traducción y el adaptador heredado de WPML.
 *
 * @package TF\Multilingual\Tests\Unit\Compatibilidad
 */

declare( strict_types=1 );

namespace TF\Multilingual\Tests\Unit\Compatibilidad;

use PHPUnit\Framework\TestCase;
use TF\Multilingual\API\ServicioTraduccionPublico;
use TF\Multilingual\Compatibilidad\AdaptadorCompatibilidadWpml;
use TF\Multilingual\Domain\Language\Language;
use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Domain\Language\SettingsRepository;
use TF\Multilingual\Domain\Translation\ContentTranslationResolver;
use TF\Multilingual\Domain\Translation\TranslationElement;
use TF\Multilingual\Domain\Translation\TranslationGroup;
use TF\Multilingual\Domain\Translation\TranslationGroupRepository;
use TF\Multilingual\Routing\CurrentLanguageResolver;

/**
 * Class AdaptadorCompatibilidadWpmlTest
 */
class AdaptadorCompatibilidadWpmlTest extends TestCase {

	/**
	 * Registro de idiomas.
	 *
	 * @var LanguageRegistry
	 */
	private LanguageRegistry $registry;

	/**
	 * Repositorio de grupos en memoria.
	 *
	 * @var TranslationGroupRepository
	 */
	private TranslationGroupRepository $group_repo;

	/**
	 * Resolvedor de traducciones.
	 *
	 * @var ContentTranslationResolver
	 */
	private ContentTranslationResolver $content_resolver;

	/**
	 * Resolvedor de idioma actual simulado.
	 *
	 * @var CurrentLanguageResolver
	 */
	private CurrentLanguageResolver $current_lang_resolver;

	/**
	 * Testable DB.
	 *
	 * @var \TF\Multilingual\Tests\Doubles\TestableWpdb
	 */
	private \TF\Multilingual\Tests\Doubles\TestableWpdb $db;

	/**
	 * Servicio de API pública.
	 *
	 * @var ServicioTraduccionPublico
	 */
	private ServicioTraduccionPublico $servicio_api;

	/**
	 * Configura el entorno de prueba.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$settings_repo  = new SettingsRepository();
		$this->registry = new LanguageRegistry( $settings_repo );

		$es = new Language( 'es', 'es_ES', 'Spanish', 'Español', true, 1 );
		$en = new Language( 'en', 'en_US', 'English', 'English', true, 2 );
		$fr = new Language( 'fr', 'fr_FR', 'French', 'Français', false, 3 ); // Inactivo.

		$this->registry->add_language( $es, true );
		$this->registry->add_language( $en, false );
		$this->registry->add_language( $fr, false );

		$this->db = new \TF\Multilingual\Tests\Doubles\TestableWpdb();
		$this->db->reset();

		$this->group_repo       = new TranslationGroupRepository( $this->db, $this->registry, new \TF\Multilingual\Domain\Translation\WordPressElementValidator() );
		$this->content_resolver = new ContentTranslationResolver( $this->group_repo, $this->registry );

		$this->current_lang_resolver = $this->createMock( CurrentLanguageResolver::class );
		$this->current_lang_resolver->method( 'get_current_language' )->willReturn( 'es' );

		$this->servicio_api = new ServicioTraduccionPublico(
			$this->registry,
			$this->current_lang_resolver,
			$this->content_resolver
		);
	}

	/**
	 * Limpieza posterior a la prueba.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		AdaptadorCompatibilidadWpml::fijar_instancia_activa( null );
		parent::tearDown();
	}

	/**
	 * Test: La constante ICL_LANGUAGE_CODE NO debe estar definida globalmente (DT-TFML-058).
	 *
	 * @return void
	 */
	public function test_icl_language_code_no_esta_definida_globalmente(): void {
		$this->assertFalse(
			defined( 'ICL_LANGUAGE_CODE' ),
			'Violación crítica de DT-TFML-058: ICL_LANGUAGE_CODE no debe estar definida globalmente.'
		);
	}

	/**
	 * Test: El adaptador está desactivado por defecto.
	 *
	 * @return void
	 */
	public function test_adaptador_desactivado_por_defecto(): void {
		$adaptador = new AdaptadorCompatibilidadWpml( $this->servicio_api, false );
		$this->assertFalse( $adaptador->esta_habilitado() );
		$this->assertNull( AdaptadorCompatibilidadWpml::obtener_instancia_activa() );
	}

	/**
	 * Test: Activación explícita del adaptador.
	 *
	 * @return void
	 */
	public function test_activacion_explicita_del_adaptador(): void {
		$adaptador = new AdaptadorCompatibilidadWpml( $this->servicio_api, false );
		$adaptador->activar();

		$this->assertTrue( $adaptador->esta_habilitado() );
		$this->assertSame( $adaptador, AdaptadorCompatibilidadWpml::obtener_instancia_activa() );

		$adaptador->desactivar();
		$this->assertFalse( $adaptador->esta_habilitado() );
		$this->assertNull( AdaptadorCompatibilidadWpml::obtener_instancia_activa() );
	}

	/**
	 * Test: es_admisible_para_registro retorna false cuando está desactivado.
	 *
	 * @return void
	 */
	public function test_es_admisible_retorna_false_si_desactivado(): void {
		$adaptador = new AdaptadorCompatibilidadWpml( $this->servicio_api, false );
		$this->assertFalse( $adaptador->es_admisible_para_registro() );
		$this->assertStringContainsString( 'desactivado', (string) $adaptador->get_motivo_inhibicion() );
	}

	/**
	 * Test: Detección de presencia de WPML real en el entorno.
	 *
	 * @return void
	 */
	public function test_detectar_presencia_wpml_real(): void {
		$adaptador = new AdaptadorCompatibilidadWpml( $this->servicio_api, false );
		// En el entorno de test unitario aislado no existe SitePress.
		$this->assertFalse( $adaptador->detectar_presencia_wpml_real() );
	}

	/**
	 * Test: estan_envoltorios_registrados refleja el estado exacto.
	 *
	 * @return void
	 */
	public function test_estan_envoltorios_registrados(): void {
		$adaptador_inactivo = new AdaptadorCompatibilidadWpml( $this->servicio_api, false );
		// Si está inactivo, nunca intenta registrar envoltorios.
		$this->assertFalse( $adaptador_inactivo->estan_envoltorios_registrados() );
	}

	/**
	 * Test: API pública nativa (Superficie A) opera sin depender del adaptador.
	 *
	 * @return void
	 */
	public function test_api_publica_nativa_opera_independientemente(): void {
		$this->assertSame( 'es', $this->servicio_api->obtener_idioma_actual() );
		$this->assertSame( 'es', $this->servicio_api->obtener_idioma_por_defecto() );
		$this->assertSame( array( 'es', 'en' ), $this->servicio_api->obtener_idiomas_activos() );
		$this->assertTrue( $this->servicio_api->es_idioma_activo( 'es' ) );
		$this->assertTrue( $this->servicio_api->es_idioma_activo( 'en' ) );
		$this->assertFalse( $this->servicio_api->es_idioma_activo( 'fr' ) ); // Francés inactivo.
	}

	/**
	 * Test: icl_object_id resuelve traducción existente correctamente.
	 *
	 * @return void
	 */
	public function test_icl_object_id_resuelve_traduccion_existente(): void {
		$group = $this->group_repo->create_group( 'post', 'post', 100, 'es', true );
		$this->group_repo->add_translation( (int) $group->get_id(), 200, 'en' );

		$adaptador = new AdaptadorCompatibilidadWpml( $this->servicio_api, true );

		// 1. Solicitar traducción al inglés pasando 'en' explícito.
		$res_en = $adaptador->ejecutar_icl_object_id( 100, 'post', false, 'en' );
		$this->assertSame( 200, $res_en );

		// 2. Solicitar traducción al español pasando 'es'.
		$res_es = $adaptador->ejecutar_icl_object_id( 200, 'post', false, 'es' );
		$this->assertSame( 100, $res_es );

		// 3. Solicitar el mismo elemento en su propio idioma devuelve el mismo ID.
		$res_mismo = $adaptador->ejecutar_icl_object_id( 100, 'post', false, 'es' );
		$this->assertSame( 100, $res_mismo );
	}

	/**
	 * Test: icl_object_id ante traducción inexistente respeta $return_original_if_missing.
	 *
	 * @return void
	 */
	public function test_icl_object_id_traduccion_inexistente_respeta_parametro_fallback(): void {
		$adaptador = new AdaptadorCompatibilidadWpml( $this->servicio_api, true );

		// Sin traducción y return_original = false -> null.
		$res_null = $adaptador->ejecutar_icl_object_id( 999, 'post', false, 'en' );
		$this->assertNull( $res_null );

		// Sin traducción y return_original = true -> ID original (999).
		$res_orig = $adaptador->ejecutar_icl_object_id( 999, 'post', true, 'en' );
		$this->assertSame( 999, $res_orig );
	}

	/**
	 * Test: icl_object_id ante idioma inactivo (francés) no produce fallback silencioso a otro idioma.
	 *
	 * @return void
	 */
	public function test_icl_object_id_idioma_inactivo_no_produce_fallback_silencioso(): void {
		$group = $this->group_repo->create_group( 'post', 'post', 100, 'es', true );
		$this->group_repo->add_translation( (int) $group->get_id(), 200, 'en' );

		$adaptador = new AdaptadorCompatibilidadWpml( $this->servicio_api, true );

		// Francés está inactivo: no debe retornar 100 ni 200 cuando return_original_if_missing es false.
		$res_fr = $adaptador->ejecutar_icl_object_id( 100, 'post', false, 'fr' );
		$this->assertNull( $res_fr );

		// Si return_original_if_missing es true, devuelve el ID original solicitado sin engañar con otro idioma.
		$res_fr_orig = $adaptador->ejecutar_icl_object_id( 100, 'post', true, 'fr' );
		$this->assertSame( 100, $res_fr_orig );
	}

	/**
	 * Test: icl_object_id soporta tipos CPT de tema (ej. 'ova_framework_hf_el') y términos.
	 *
	 * @return void
	 */
	public function test_icl_object_id_soporta_cpts_y_terminos(): void {
		// 1. CPT del tema ecoCMS ('ova_framework_hf_el').
		$group_hf = $this->group_repo->create_group( 'post', 'ova_framework_hf_el', 50, 'es', true );
		$this->group_repo->add_translation( (int) $group_hf->get_id(), 51, 'en' );

		$adaptador = new AdaptadorCompatibilidadWpml( $this->servicio_api, true );

		$hf_res = $adaptador->ejecutar_icl_object_id( 50, 'ova_framework_hf_el', false, 'en' );
		$this->assertSame( 51, $hf_res );

		// 2. Término de taxonomía ('category').
		$group_term = $this->group_repo->create_group( 'term', 'category', 10, 'es', true );
		$this->group_repo->add_translation( (int) $group_term->get_id(), 11, 'en' );

		$term_res = $adaptador->ejecutar_icl_object_id( 10, 'category', false, 'en' );
		$this->assertSame( 11, $term_res );
	}

	/**
	 * Test: icl_object_id con entradas inválidas retorna null de forma segura.
	 *
	 * @return void
	 */
	public function test_icl_object_id_entradas_invalidas(): void {
		$adaptador = new AdaptadorCompatibilidadWpml( $this->servicio_api, true );

		$this->assertNull( $adaptador->ejecutar_icl_object_id( null ) );
		$this->assertNull( $adaptador->ejecutar_icl_object_id( 0 ) );
		$this->assertNull( $adaptador->ejecutar_icl_object_id( -5 ) );
		$this->assertNull( $adaptador->ejecutar_icl_object_id( 'invalido' ) );
	}

	/**
	 * Test: icl_get_languages devuelve únicamente idiomas activos y excluye francés inactivo.
	 *
	 * @return void
	 */
	public function test_icl_get_languages_retorna_solo_activos_y_excluye_frances(): void {
		$adaptador = new AdaptadorCompatibilidadWpml( $this->servicio_api, true );

		$languages = $adaptador->ejecutar_icl_get_languages( 'skip_missing=0&orderby=custom' );

		$this->assertIsArray( $languages );
		$this->assertArrayHasKey( 'es', $languages );
		$this->assertArrayHasKey( 'en', $languages );
		$this->assertArrayNotHasKey( 'fr', $languages, 'DT-TFML-058: Francés no debe figurar en los idiomas activos.' );

		// Estructura esperada por ecoCMS.
		$es = $languages['es'];
		$this->assertSame( 1, $es['active'] );
		$this->assertSame( 'Español', $es['native_name'] );
		$this->assertSame( 'es', $es['language_code'] );
		$this->assertSame( 'es_ES', $es['default_locale'] );
		$this->assertStringContainsString( 'es.svg', $es['country_flag_url'] );
		$this->assertSame( 1, $es['has_country_flag'] );
		$this->assertSame( 'ES', $es['language_badge'] );

		$en = $languages['en'];
		$this->assertSame( 0, $en['active'] );
		$this->assertSame( 'English', $en['native_name'] );
		$this->assertSame( 'en', $en['language_code'] );
		$this->assertSame( 'en_US', $en['default_locale'] );
		$this->assertStringContainsString( 'en.svg', $en['country_flag_url'] );
	}

	/**
	 * Test: Funciones globales envolventes (icl_object_id e icl_get_languages).
	 *
	 * @return void
	 */
	public function test_funciones_globales_envolventes_operan_correctamente(): void {
		$group = $this->group_repo->create_group( 'post', 'post', 300, 'es', true );
		$this->group_repo->add_translation( (int) $group->get_id(), 301, 'en' );

		$adaptador = new AdaptadorCompatibilidadWpml( $this->servicio_api, true );

		$this->assertTrue( function_exists( 'icl_object_id' ) );
		$this->assertTrue( function_exists( 'icl_get_languages' ) );

		// Invocación directa a función global como lo hace ecoCMS.
		$res_global = icl_object_id( 300, 'post', false, 'en' );
		$this->assertSame( 301, $res_global );

		$langs_global = icl_get_languages( 'skip_missing=0' );
		$this->assertCount( 2, $langs_global );
		$this->assertArrayNotHasKey( 'fr', $langs_global );

		// Al desactivar el adaptador, las funciones globales se degradan de forma segura sin fatales.
		$adaptador->desactivar();
		$this->assertNull( icl_object_id( 300, 'post', false, 'en' ) );
		$this->assertSame( 300, icl_object_id( 300, 'post', true, 'en' ) );
		$this->assertSame( array(), icl_get_languages() );
	}

	/**
	 * Test: Banderas locales existentes (es, en, pt) devuelven URL verificable y un idioma sin archivo devuelve ''.
	 *
	 * @return void
	 */
	public function test_banderas_locales_existentes_e_inexistentes(): void {
		$adaptador = new AdaptadorCompatibilidadWpml( $this->servicio_api, true );

		foreach ( array( 'es', 'en', 'pt' ) as $codigo ) {
			$url = $adaptador->obtener_url_bandera( $codigo );
			$this->assertNotEmpty( $url );
			$this->assertStringEndsWith( '/' . $codigo . '.svg', $url );
		}

		// Un idioma sin archivo físico en assets/images/flags/ nunca debe devolver una URL rota 404.
		$url_inexistente = $adaptador->obtener_url_bandera( 'de' );
		$this->assertSame( '', $url_inexistente );
	}

	/**
	 * Test: Representación textual accesible funciona cuando no hay bandera gráfica.
	 *
	 * @return void
	 */
	public function test_representacion_textual_accesible_sin_bandera(): void {
		$de = new Language( 'de', 'de_DE', 'German', 'Deutsch', true, 4 );
		$this->registry->add_language( $de, false );

		$adaptador = new AdaptadorCompatibilidadWpml( $this->servicio_api, true );
		$languages = $adaptador->ejecutar_icl_get_languages( 'skip_missing=0' );

		$this->assertArrayHasKey( 'de', $languages );
		$this->assertSame( '', $languages['de']['country_flag_url'] );
		$this->assertSame( 0, $languages['de']['has_country_flag'] );
		$this->assertSame( 'DE', $languages['de']['language_badge'] );
		$this->assertSame( 'Deutsch (DE)', $languages['de']['accessible_label'] );
		$this->assertStringContainsString( 'lang="de"', $languages['de']['accessible_html'] );
		$this->assertStringContainsString( '>DE</abbr>', $languages['de']['accessible_html'] );
	}

	/**
	 * Test: Filtro tfml_wpml_compat_country_flag_url permite ruta local o data URI y rechaza dominios externos.
	 *
	 * @return void
	 */
	public function test_filtro_bandera_permite_local_y_rechaza_dominio_externo(): void {
		$adaptador = new AdaptadorCompatibilidadWpml( $this->servicio_api, true );

		$cb_local = static function ( string $url, string $codigo ): string {
			return '/wp-content/uploads/custom-flags/' . $codigo . '.svg';
		};
		add_filter( 'tfml_wpml_compat_country_flag_url', $cb_local, 10, 2 );
		$this->assertSame( '/wp-content/uploads/custom-flags/es.svg', $adaptador->obtener_url_bandera( 'es' ) );
		remove_filter( 'tfml_wpml_compat_country_flag_url', $cb_local, 10 );

		$cb_externo = static function (): string {
			return 'https://cdn.external-flags.invalid/es.png';
		};
		add_filter( 'tfml_wpml_compat_country_flag_url', $cb_externo, 10, 2 );
		$url_protegida = $adaptador->obtener_url_bandera( 'es' );
		$this->assertStringNotContainsString( 'external-flags.invalid', $url_protegida );
		$this->assertStringEndsWith( '/es.svg', $url_protegida );
		remove_filter( 'tfml_wpml_compat_country_flag_url', $cb_externo, 10 );
	}

	/**
	 * Test: Disponibilidad real en portada estática (197 ES, 8356 EN, PT activo sin traducción, FR retenido 8839).
	 *
	 * @return void
	 */
	public function test_disponibilidad_real_en_portada_bilingue_y_portugues_sin_traduccion(): void {
		$pt = new Language( 'pt', 'pt_PT', 'Portuguese', 'Português', true, 4 );
		$this->registry->add_language( $pt, false );

		$GLOBALS['wp_test_options']['show_on_front'] = 'page';
		$GLOBALS['wp_test_options']['page_on_front'] = 197;

		$post_es              = new \WP_Post();
		$post_es->ID          = 197;
		$post_es->post_type   = 'page';
		$post_es->post_status = 'publish';

		$post_en              = new \WP_Post();
		$post_en->ID          = 8356;
		$post_en->post_type   = 'page';
		$post_en->post_status = 'publish';

		$post_fr              = new \WP_Post();
		$post_fr->ID          = 8839;
		$post_fr->post_type   = 'page';
		$post_fr->post_status = 'publish';

		$GLOBALS['wp_test_posts'][197]  = $post_es;
		$GLOBALS['wp_test_posts'][8356] = $post_en;
		$GLOBALS['wp_test_posts'][8839] = $post_fr;

		$group = $this->group_repo->create_group( 'post', 'page', 197, 'es', true );
		$this->group_repo->add_translation( (int) $group->get_id(), 8356, 'en' );

		$retained_registry = new \TF\Multilingual\Migration\RetainedTranslationRegistry( $this->db );
		$retained_registry->register( 'wpml', 197, (int) $group->get_id(), 'post', 'page', array( 'fr' => 8839 ) );

		$url_resolver  = new \TF\Multilingual\Routing\UrlLanguageResolver( $this->registry, 'http://lab.ecoterra.test' );
		$url_generator = new \TF\Multilingual\Routing\LocalizedUrlGenerator(
			$this->registry,
			$url_resolver,
			$this->content_resolver,
			'http://lab.ecoterra.test'
		);

		$servicio = new ServicioTraduccionPublico(
			$this->registry,
			$this->current_lang_resolver,
			$this->content_resolver,
			null,
			$url_generator,
			null,
			$retained_registry
		);

		$adaptador = new AdaptadorCompatibilidadWpml( $servicio, true );

		// 1. Con skip_missing=0: incluye es, en y pt, pero pt tiene missing=1 y url='' (nunca http://lab.ecoterra.test/pt/).
		$langs_all = $adaptador->ejecutar_icl_get_languages( 'skip_missing=0&orderby=custom' );
		$this->assertArrayHasKey( 'es', $langs_all );
		$this->assertArrayHasKey( 'en', $langs_all );
		$this->assertArrayHasKey( 'pt', $langs_all );
		$this->assertArrayNotHasKey( 'fr', $langs_all );

		$this->assertSame( 0, $langs_all['es']['missing'] );
		$this->assertSame( 'http://lab.ecoterra.test/', $langs_all['es']['url'] );

		$this->assertSame( 0, $langs_all['en']['missing'] );
		$this->assertSame( 'http://lab.ecoterra.test/en/', $langs_all['en']['url'] );

		$this->assertSame( 1, $langs_all['pt']['missing'] );
		$this->assertSame( '', $langs_all['pt']['url'] );

		// 2. Con skip_missing=1: omite pt porque no tiene traducción publicada en la portada.
		$langs_available = $adaptador->ejecutar_icl_get_languages( 'skip_missing=1&orderby=custom' );
		$this->assertArrayHasKey( 'es', $langs_available );
		$this->assertArrayHasKey( 'en', $langs_available );
		$this->assertArrayNotHasKey( 'pt', $langs_available );
		$this->assertArrayNotHasKey( 'fr', $langs_available );

		// 3. El post francés retenido 8839 no se resuelve como traducción ni tiene idioma activo.
		$this->assertNull( $servicio->resolver_traduccion_elemento( 'page', 197, 'fr' ) );
		$this->assertNull( $servicio->obtener_idioma_de_elemento( 'page', 8839 ) );

		unset( $GLOBALS['wp_test_options']['show_on_front'], $GLOBALS['wp_test_options']['page_on_front'] );
	}

	/**
	 * Test: Una traducción en borrador (draft) se marca como missing=1 y url='' y se omite con skip_missing=1.
	 *
	 * @return void
	 */
	public function test_traduccion_en_borrador_no_se_expone_como_disponible(): void {
		$post_es              = new \WP_Post();
		$post_es->ID          = 500;
		$post_es->post_type   = 'page';
		$post_es->post_status = 'publish';

		$post_en_draft              = new \WP_Post();
		$post_en_draft->ID          = 501;
		$post_en_draft->post_type   = 'page';
		$post_en_draft->post_status = 'draft';

		$GLOBALS['wp_test_posts'][500] = $post_es;
		$GLOBALS['wp_test_posts'][501] = $post_en_draft;

		$group = $this->group_repo->create_group( 'post', 'page', 500, 'es', true );
		$this->group_repo->add_translation( (int) $group->get_id(), 501, 'en' );

		$this->servicio_api->establecer_contexto_evaluacion( 'page', 500 );
		$adaptador = new AdaptadorCompatibilidadWpml( $this->servicio_api, true );

		$langs_all = $adaptador->ejecutar_icl_get_languages( 'skip_missing=0' );
		$this->assertSame( 0, $langs_all['es']['missing'] );
		$this->assertSame( 1, $langs_all['en']['missing'] );
		$this->assertSame( '', $langs_all['en']['url'] );

		$langs_skip = $adaptador->ejecutar_icl_get_languages( 'skip_missing=1' );
		$this->assertArrayHasKey( 'es', $langs_skip );
		$this->assertArrayNotHasKey( 'en', $langs_skip );

		$this->servicio_api->establecer_contexto_evaluacion( null, null );
	}
}
