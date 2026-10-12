<?php
/**
 * Pruebas unitarias para LocalizadorPublicoNativo.
 *
 * @package TF\Multilingual\Tests\Unit\Localizacion
 */

declare( strict_types=1 );

namespace TF\Multilingual\Tests\Unit\Localizacion;

use PHPUnit\Framework\TestCase;
use TF\Multilingual\Core\Plugin;
use TF\Multilingual\Domain\Language\Language;
use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Domain\Language\SettingsRepository;
use TF\Multilingual\Localizacion\LocalizadorPublicoNativo;
use TF\Multilingual\Routing\CurrentLanguageResolver;
use TF\Multilingual\Routing\UrlLanguageResolver;

/**
 * Clase LocalizadorPublicoNativoTest
 */
class LocalizadorPublicoNativoTest extends TestCase {

	/**
	 * Registro de idiomas para pruebas.
	 *
	 * @var LanguageRegistry
	 */
	private LanguageRegistry $registro_idiomas;

	/**
	 * Resolutor de URL.
	 *
	 * @var UrlLanguageResolver
	 */
	private UrlLanguageResolver $resolutor_url;

	/**
	 * Resolutor de idioma actual.
	 *
	 * @var CurrentLanguageResolver
	 */
	private CurrentLanguageResolver $resolutor_idioma;

	/**
	 * Instancia bajo prueba.
	 *
	 * @var LocalizadorPublicoNativo
	 */
	private LocalizadorPublicoNativo $localizador;

	/**
	 * Configuración previa a cada prueba.
	 */
	protected function setUp(): void {
		parent::setUp();

		$settings_repo          = new SettingsRepository();
		$this->registro_idiomas = new LanguageRegistry( $settings_repo );

		// Configurar catálogo bilingüe oficial con español como default soberano.
		$this->registro_idiomas->add_language( new Language( 'es', 'es_ES', 'Español', 'Español', true ), true );
		$this->registro_idiomas->add_language( new Language( 'en', 'en_US', 'English', 'English', true ) );

		$this->resolutor_url    = new UrlLanguageResolver( $this->registro_idiomas, 'http://example.com' );
		$this->resolutor_idioma = new CurrentLanguageResolver( $this->resolutor_url );

		$this->localizador = new LocalizadorPublicoNativo(
			$this->registro_idiomas,
			$this->resolutor_idioma
		);

		// Habilitar ejecución bajo CLI para las pruebas unitarias.
		$this->localizador->habilitar_en_cli_para_pruebas( true );
	}

	/**
	 * Limpieza posterior a cada prueba.
	 */
	protected function tearDown(): void {
		$this->localizador->desregistrar_ganchos();
		$this->resolutor_idioma->reset();
		parent::tearDown();
	}

	/**
	 * Prueba que la portada y rutas en español resuelven el locale nativo es_ES.
	 */
	public function test_resuelve_locale_es_para_portada_espanola(): void {
		$this->resolutor_idioma->set_current_language( 'es' );

		$this->assertSame( 'es_ES', $this->localizador->filtrar_locale( 'en_US' ) );
		$this->assertSame( 'es_ES', $this->localizador->filtrar_determine_locale( 'en_US' ) );
		$this->assertSame( 'es_ES', $this->localizador->obtener_locale_publico_efectivo() );
	}

	/**
	 * Prueba que rutas en inglés resuelven el locale nativo en_US.
	 */
	public function test_resuelve_locale_en_para_solicitud_ingles(): void {
		$this->resolutor_idioma->set_current_language( 'en' );

		$this->assertSame( 'en_US', $this->localizador->filtrar_locale( 'es_ES' ) );
		$this->assertSame( 'en_US', $this->localizador->filtrar_determine_locale( 'es_ES' ) );
		$this->assertSame( 'en_US', $this->localizador->obtener_locale_publico_efectivo() );
	}

	/**
	 * Prueba que un idioma registrado pero inactivo (ej. francés 'fr') no establece locale ni habilita el idioma.
	 */
	public function test_idioma_frances_inactivo_no_establece_locale(): void {
		// Registrar francés explícitamente inactivo.
		$this->registro_idiomas->add_language( new Language( 'fr', 'fr_FR', 'Français', 'Français', false ) );

		$this->resolutor_idioma->set_current_language( 'fr' );

		// Al estar inactivo, debe retornar null en efectivo y preservar el locale original.
		$this->assertNull( $this->localizador->obtener_locale_publico_efectivo() );
		$this->assertSame( 'es_ES', $this->localizador->filtrar_locale( 'es_ES' ) );
		$this->assertSame( 'es_ES', $this->localizador->filtrar_determine_locale( 'es_ES' ) );
	}

	/**
	 * Prueba que un código de idioma no registrado no establece ningún locale ficticio.
	 */
	public function test_idioma_no_registrado_no_establece_locale_ficticio(): void {
		$this->resolutor_idioma->set_current_language( 'de' );

		$this->assertNull( $this->localizador->obtener_locale_publico_efectivo() );
		$this->assertSame( 'en_US', $this->localizador->filtrar_locale( 'en_US' ) );
	}

	/**
	 * Prueba que cuando la bandera CLI está apagada, se preserva el entorno de línea de comandos.
	 */
	public function test_preserva_contexto_cli_por_defecto(): void {
		$this->localizador->habilitar_en_cli_para_pruebas( false );
		$this->resolutor_idioma->set_current_language( 'es' );

		// Si WP_CLI estuviese definido, no sería elegible; comprobamos método de elegibilidad.
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			$this->assertFalse( $this->localizador->es_contexto_publico_elegible() );
			$this->assertNull( $this->localizador->obtener_locale_publico_efectivo() );
			$this->assertSame( 'en_US', $this->localizador->filtrar_locale( 'en_US' ) );
		} else {
			$this->assertTrue( $this->localizador->es_contexto_publico_elegible() );
		}
	}

	/**
	 * Prueba que el semáforo antirrecusión previene llamadas circulares infinitas.
	 */
	public function test_proteccion_antirrecursion_previene_bucles(): void {
		$this->resolutor_idioma->set_current_language( 'es' );

		// Simular una llamada anidada a filtrar_locale dentro del proceso.
		$llamada_recursiva_detectada = false;

		$resultado_normal = $this->localizador->filtrar_locale( 'en_US' );
		$this->assertSame( 'es_ES', $resultado_normal );

		// Comprobar que en estado normal el semáforo queda liberado.
		$reflector = new \ReflectionClass( $this->localizador );
		$propiedad = $reflector->getProperty( 'en_resolucion' );
		$propiedad->setAccessible( true );

		$this->assertFalse( $propiedad->getValue( $this->localizador ) );

		// Si el semáforo estuviese activo, debe retornar null / original sin lanzar excepción.
		$propiedad->setValue( $this->localizador, true );
		$this->assertSame( 'en_US', $this->localizador->filtrar_locale( 'en_US' ) );
		$this->assertNull( $this->localizador->obtener_locale_publico_efectivo() );
	}

	/**
	 * Prueba que registrar_ganchos y desregistrar_ganchos gestionan los ganchos de WordPress correctamente.
	 */
	public function test_registro_y_desregistro_de_ganchos(): void {
		$this->localizador->registrar_ganchos();

		$this->assertNotFalse( has_filter( 'locale', array( $this->localizador, 'filtrar_locale' ) ) );
		$this->assertNotFalse( has_filter( 'determine_locale', array( $this->localizador, 'filtrar_determine_locale' ) ) );

		$this->localizador->desregistrar_ganchos();

		$this->assertFalse( has_filter( 'locale', array( $this->localizador, 'filtrar_locale' ) ) );
		$this->assertFalse( has_filter( 'determine_locale', array( $this->localizador, 'filtrar_determine_locale' ) ) );
	}

	/**
	 * Prueba que rutas públicas específicas en español (portada, página, post, tour) resuelven es_ES.
	 */
	public function test_resolucion_rutas_especificas_espanolas(): void {
		$rutas_espanolas = array(
			'/',
			'/old-blog/',
			'/blog/recomendaciones-para-disfrutar-machu-picchu/',
			'/tours/camino-inca-clasico/',
			'/category/blog/',
			'/tag/cusco/',
		);

		foreach ( $rutas_espanolas as $ruta ) {
			$_SERVER['REQUEST_URI'] = $ruta;
			$this->resolutor_idioma->reset();

			$this->assertSame( 'es', $this->resolutor_idioma->get_current_language(), "Fallo en resolución de idioma para ruta: {$ruta}" );
			$this->assertSame( 'es_ES', $this->localizador->filtrar_locale( 'en_US' ), "Fallo en locale para ruta: {$ruta}" );
		}
	}

	/**
	 * Prueba que rutas públicas específicas en inglés (tour inglés, páginas) resuelven en_US.
	 */
	public function test_resolucion_rutas_especificas_inglesas(): void {
		$rutas_inglesas = array(
			'/en/',
			'/en/tours/classic-inca-trail/',
			'/en/about-us/',
			'/en/category/travel/',
		);

		foreach ( $rutas_inglesas as $ruta ) {
			$_SERVER['REQUEST_URI'] = $ruta;
			$this->resolutor_idioma->reset();

			$this->assertSame( 'en', $this->resolutor_idioma->get_current_language(), "Fallo en resolución de idioma para ruta: {$ruta}" );
			$this->assertSame( 'en_US', $this->localizador->filtrar_locale( 'es_ES' ), "Fallo en locale para ruta: {$ruta}" );
		}
	}

	/**
	 * Prueba que el localizador preserva intacta la opción WPLANG en base de datos.
	 */
	public function test_ausencia_de_cambios_en_opcion_wplang(): void {
		$wplang_antes = get_option( 'WPLANG', '' );

		$this->resolutor_idioma->set_current_language( 'es' );
		$this->localizador->filtrar_locale( 'en_US' );

		$this->resolutor_idioma->set_current_language( 'en' );
		$this->localizador->filtrar_locale( 'es_ES' );

		$wplang_despues = get_option( 'WPLANG', '' );
		$this->assertSame( $wplang_antes, $wplang_despues, 'La opción WPLANG en base de datos no debe ser modificada en ningún momento.' );
	}

	/**
	 * Prueba que el orquestador principal Plugin expone la instancia del LocalizadorPublicoNativo.
	 */
	public function test_integracion_con_plugin_orquestador(): void {
		$plugin = Plugin::get_instance();
		$plugin->init();

		$localizador_plugin = $plugin->get_localizador_publico_nativo();
		$this->assertInstanceOf( LocalizadorPublicoNativo::class, $localizador_plugin );
	}
}
