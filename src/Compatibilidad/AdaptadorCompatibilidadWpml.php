<?php
/**
 * Adaptador de Compatibilidad Heredada con WPML.
 *
 * @package TF\Multilingual\Compatibilidad
 */

declare( strict_types=1 );

namespace TF\Multilingual\Compatibilidad;

use TF\Multilingual\API\ServicioTraduccionPublico;
use TF\Multilingual\Domain\Strings\StringTranslationService;

/**
 * Class AdaptadorCompatibilidadWpml
 *
 * Superficie B: Adaptador heredado y limitado para entornos con temas o plugins
 * que invocan llamadas clásicas de WPML (como ecoCMS con icl_object_id, icl_get_languages e icl_t).
 *
 * GESTIÓN DE COMPATIBILIDAD (DT-TFML-058 / DT-TFML-092):
 * - Este adaptador NO define globalmente ICL_LANGUAGE_CODE en la carga de archivos, preservando
 *   el aislamiento en CLI y PHPUnit. En tiempo de ejecución web (init/wp), sincroniza ICL_LANGUAGE_CODE
 *   de forma segura con el idioma resuelto para dar soporte a temas heredados (traducir() de ecoCMS).
 * - Permanece DESACTIVADO POR DEFECTO y solo se activa de manera explícita.
 * - Soporta resolución de objetos (icl_object_id), listado (icl_get_languages) y cadenas (icl_t / wpml_translate_single_string).
 */
class AdaptadorCompatibilidadWpml {

	/**
	 * Instancia activa del adaptador para consumo de funciones globales.
	 *
	 * @var self|null
	 */
	private static ?self $instancia_activa = null;

	/**
	 * Servicio de API pública de TF Multilingual.
	 *
	 * @var ServicioTraduccionPublico
	 */
	private ServicioTraduccionPublico $servicio_api;

	/**
	 * Servicio de traducción de cadenas de TF Multilingual.
	 *
	 * @var StringTranslationService|null
	 */
	private ?StringTranslationService $servicio_cadenas = null;

	/**
	 * Estado de habilitación del adaptador.
	 *
	 * @var bool
	 */
	private bool $habilitado = false;

	/**
	 * Indica si los envoltorios globales fueron efectivamente registrados en este proceso PHP.
	 *
	 * @var bool
	 */
	private bool $envoltorios_registrados = false;

	/**
	 * Motivo de inhibición si se detectó colisión o presencia de WPML real.
	 *
	 * @var string|null
	 */
	private ?string $motivo_inhibicion = null;

	/**
	 * Constructor.
	 *
	 * @param ServicioTraduccionPublico      $servicio_api     Servicio público de traducción.
	 * @param bool                           $habilitado       Estado inicial (por defecto inactivo).
	 * @param StringTranslationService|null $servicio_cadenas Servicio opcional de traducción de cadenas.
	 */
	public function __construct(
		ServicioTraduccionPublico $servicio_api,
		bool $habilitado = false,
		?StringTranslationService $servicio_cadenas = null
	) {
		$this->servicio_api     = $servicio_api;
		$this->habilitado       = $habilitado;
		$this->servicio_cadenas = $servicio_cadenas;

		if ( $this->habilitado ) {
			$this->registrar_envoltorios_globales();
		}
	}

	/**
	 * Obtiene la instancia activa para funciones globales.
	 *
	 * @return self|null
	 */
	public static function obtener_instancia_activa(): ?self {
		return self::$instancia_activa;
	}

	/**
	 * Establece la instancia activa.
	 *
	 * @param self|null $instancia Instancia a fijar.
	 * @return void
	 */
	public static function fijar_instancia_activa( ?self $instancia ): void {
		self::$instancia_activa = $instancia;
	}

	/**
	 * Indica si el adaptador se encuentra habilitado y operando.
	 *
	 * @return bool
	 */
	public function esta_habilitado(): bool {
		return $this->habilitado;
	}

	/**
	 * Detecta si WPML real se encuentra instalado o activo en el entorno runtime.
	 *
	 * @return bool True si se detecta SitePress o constantes oficiales de WPML.
	 */
	public function detectar_presencia_wpml_real(): bool {
		if ( class_exists( 'SitePress' ) ) {
			return true;
		}
		if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
			return true;
		}
		if ( defined( 'WPML_PLUGIN_PATH' ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Detecta si los símbolos globales heredados ya fueron declarados externamente.
	 *
	 * @return bool True si las funciones icl_* ya existen y no fueron registradas por TFML.
	 */
	public function detectar_colision_simbolos(): bool {
		if ( ( function_exists( 'icl_object_id' ) || function_exists( 'icl_get_languages' ) )
			&& ! defined( 'TFML_WPML_FUNCTIONS_LOADED' ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Evalúa si el contexto y entorno son admisibles para registrar las funciones globales.
	 *
	 * Reglas de seguridad (DT-TFML-059):
	 * 1. El adaptador debe estar explícitamente habilitado.
	 * 2. No debe detectarse WPML real en el entorno.
	 * 3. No deben existir colisiones de símbolos globales previos.
	 *
	 * @return bool True si es seguro proceder con el registro.
	 */
	public function es_admisible_para_registro(): bool {
		if ( $this->envoltorios_registrados ) {
			return true;
		}

		if ( $this->detectar_presencia_wpml_real() ) {
			$this->motivo_inhibicion = 'WPML real detectado en el runtime. Adaptador inhibido para evitar interferencias.';
			return false;
		}

		if ( $this->detectar_colision_simbolos() ) {
			$this->motivo_inhibicion = 'Colisión de símbolos globales: icl_object_id o icl_get_languages ya declarados externamente.';
			return false;
		}

		if ( ! $this->habilitado ) {
			if ( null === $this->motivo_inhibicion ) {
				$this->motivo_inhibicion = 'Adaptador desactivado por configuración o filtro.';
			}
			return false;
		}

		$this->motivo_inhibicion = null;
		return true;
	}

	/**
	 * Obtiene el motivo por el cual el adaptador fue inhibido, si corresponde.
	 *
	 * @return string|null
	 */
	public function get_motivo_inhibicion(): ?string {
		return $this->motivo_inhibicion;
	}

	/**
	 * Registra de manera segura e idempotente las funciones envolventes globales.
	 *
	 * @return bool True si las funciones fueron registradas o ya estaban activas.
	 */
	public function registrar_envoltorios_globales(): bool {
		if ( $this->envoltorios_registrados ) {
			return true;
		}

		if ( ! $this->es_admisible_para_registro() ) {
			$this->habilitado = false;
			return false;
		}

		self::$instancia_activa = $this;

		// Cargar funciones envolventes globales únicamente tras superar todas las salvaguardas.
		require_once __DIR__ . '/funciones_wpml.php';

		if ( function_exists( 'add_action' ) ) {
			add_action( 'init', array( $this, 'sincronizar_constante_idioma_wpml' ), 1 );
			add_action( 'wp', array( $this, 'sincronizar_constante_idioma_wpml' ), 1 );
		}

		if ( function_exists( 'add_filter' ) ) {
			add_filter( 'do_shortcode_tag', array( $this, 'filtrar_salida_shortcode_selector_heredado' ), 10, 2 );
			add_filter( 'wpml_translate_single_string', array( $this, 'filtrar_wpml_translate_single_string' ), 10, 5 );
		}

		$this->envoltorios_registrados = true;
		return true;
	}

	/**
	 * Sincroniza de manera acotada y segura la constante heredada ICL_LANGUAGE_CODE durante el ciclo de vida web.
	 *
	 * Permite que funciones del tema ecoCMS (como traducir()) reconozcan el idioma activo resuelto por
	 * TF Multilingual en peticiones web sin romper el aislamiento en CLI o PHPUnit.
	 *
	 * @return void
	 */
	public function sincronizar_constante_idioma_wpml(): void {
		if ( ! $this->habilitado ) {
			return;
		}

		if ( ! defined( 'ICL_LANGUAGE_CODE' ) ) {
			$codigo = $this->servicio_api->obtener_idioma_actual()
				?? $this->servicio_api->obtener_idioma_por_defecto()
				?? 'es';

			define( 'ICL_LANGUAGE_CODE', $codigo );
		}
	}

	/**
	 * Ejecuta la traducción compatible de icl_t.
	 *
	 * @param string $context Contexto o dominio.
	 * @param string $name    Nombre o clave de la cadena.
	 * @param string $value   Valor por defecto.
	 * @return string Valor traducido o valor por defecto.
	 */
	public function ejecutar_icl_t( string $context, string $name, string $value ): string {
		if ( ! $this->habilitado ) {
			return $value;
		}

		if ( null !== $this->servicio_cadenas ) {
			return $this->servicio_cadenas->translate( $context, $name, $value );
		}

		return $value;
	}

	/**
	 * Filtra la llamada extensible wpml_translate_single_string delegando en StringTranslationService.
	 *
	 * @param mixed       $original_value Valor original o por defecto.
	 * @param string      $context        Contexto o dominio.
	 * @param string      $name           Nombre o clave de la cadena.
	 * @param string|null $translated     Traducción previa (si existe).
	 * @param string|null $lang           Código de idioma solicitado.
	 * @return mixed Valor traducido o valor original.
	 */
	public function filtrar_wpml_translate_single_string(
		mixed $original_value,
		string $context = '',
		string $name = '',
		?string $translated = null,
		?string $lang = null
	): mixed {
		if ( ! $this->habilitado || ! is_string( $original_value ) ) {
			return $original_value;
		}

		if ( null !== $this->servicio_cadenas && '' !== trim( $context ) && '' !== trim( $name ) ) {
			return $this->servicio_cadenas->translate( $context, $name, $original_value, $lang );
		}

		return $original_value;
	}

	/**
	 * Saneamiento no intrusivo de la salida de shortcodes heredados de selector de idiomas
	 * (top-idiomas / top-header) cuando el adaptador está habilitado:
	 * En la navegación pública, oculta opciones de idioma que no dispongan de una traducción válida
	 * y publicada en el contexto actual (missing=1, url=''), eliminando la etiqueta de enlace vacía
	 * y separadores adyacentes para prevenir la exposición de rutas o enlaces no operativos (DT-TFML-091).
	 *
	 * @param mixed  $salida Salida generada por el shortcode.
	 * @param string $tag    Nombre del shortcode ejecutado.
	 * @return mixed Salida saneada.
	 */
	public function filtrar_salida_shortcode_selector_heredado( mixed $salida, string $tag ): mixed {
		if ( ! $this->habilitado || ! is_string( $salida ) || '' === $salida ) {
			return $salida;
		}

		if ( ! in_array( $tag, array( 'top-idiomas', 'top-header' ), true ) ) {
			return $salida;
		}

		// DT-TFML-091: Ocultar selectores sin traducción válida eliminando enlaces vacíos o placeholders.
		$saneada = preg_replace(
			'/(?:&nbsp;|\s)*<a(\s+[^>]*)?(?:href=""|data-tfml-missing="1"|aria-disabled="true")([^>]*)>.*?<\/a>(?:&nbsp;|\s)*/is',
			'',
			$salida
		);

		return is_string( $saneada ) ? $saneada : $salida;
	}

	/**
	 * Activa el adaptador y registra las funciones globales si el entorno es admisible.
	 *
	 * @return bool True si el adaptador quedó habilitado y los envoltorios registrados.
	 */
	public function activar(): bool {
		$this->habilitado = true;
		return $this->registrar_envoltorios_globales();
	}

	/**
	 * Desactiva la delegación del adaptador en memoria.
	 *
	 * Advertencia de ciclo de vida PHP (DT-TFML-059):
	 * Las funciones globales en PHP no pueden eliminarse del runtime una vez declaradas.
	 * Desactivar el adaptador desacopla la instancia activa, degradando las llamadas
	 * existentes a respuestas neutras (null/array vacío), pero NO elimina los símbolos
	 * de la tabla de funciones de PHP. Por seguridad arquitectónica, la activación
	 * debe determinarse en el arranque del request y no alternarse dinámicamente.
	 *
	 * @return void
	 */
	public function desactivar(): void {
		$this->habilitado = false;
		if ( self::$instancia_activa === $this ) {
			self::$instancia_activa = null;
		}
	}

	/**
	 * Indica si los envoltorios globales fueron efectivamente registrados en este proceso PHP.
	 *
	 * @return bool
	 */
	public function estan_envoltorios_registrados(): bool {
		return $this->envoltorios_registrados;
	}

	/**
	 * Ejecuta la lógica del contrato compatible de icl_object_id().
	 *
	 * @param mixed       $id                          Identificador del post o término.
	 * @param string      $type                        Tipo de elemento (ej. 'post', 'page', 'ova_framework_hf_el', 'category').
	 * @param bool        $return_original_if_missing  Si es true, retorna el ID original si falta la traducción.
	 * @param string|null $lang                        Código de idioma solicitado (o null para idioma actual).
	 * @return int|null ID traducido, ID original (si se pidió fallback), o null.
	 */
	public function ejecutar_icl_object_id(
		mixed $id,
		string $type = 'post',
		bool $return_original_if_missing = false,
		?string $lang = null
	): ?int {
		if ( null === $id || ! is_numeric( $id ) || (int) $id <= 0 ) {
			return null;
		}

		$id_entero = (int) $id;

		// Determinar el idioma destino solicitado.
		$idioma_destino = ( null !== $lang && '' !== trim( $lang ) )
			? strtolower( trim( $lang ) )
			: ( $this->servicio_api->obtener_idioma_actual() ?? $this->servicio_api->obtener_idioma_por_defecto() );

		// Si no hay idioma destino o el idioma destino está inactivo (ej. 'fr'),
		// no se permite fallback a un idioma incorrecto.
		if ( null === $idioma_destino || ! $this->servicio_api->es_idioma_activo( $idioma_destino ) ) {
			return $return_original_if_missing ? $id_entero : null;
		}

		$id_resuelto = $this->servicio_api->resolver_traduccion_elemento( $type, $id_entero, $idioma_destino );

		if ( null !== $id_resuelto && $id_resuelto > 0 ) {
			return $id_resuelto;
		}

		return $return_original_if_missing ? $id_entero : null;
	}

	/**
	 * Ejecuta la lógica del contrato compatible de icl_get_languages().
	 *
	 * Distingue entre idioma activo en LanguageRegistry y traducción realmente
	 * disponible y publicada para el objeto/contexto actual (DT-TFML-066):
	 * - Si skip_missing=1, omite los idiomas donde missing=1.
	 * - Si skip_missing=0, incluye los idiomas activos pero marca missing=1 y url=''
	 *   cuando no existe traducción publicada real (evitando enlaces engañosos a 404).
	 *
	 * @param string|array<string, mixed> $args Argumentos en formato query string o array.
	 * @return array<string, array<string, mixed>> Lista de idiomas activos.
	 */
	public function ejecutar_icl_get_languages( mixed $args = '' ): array {
		$parsed_args  = $this->parsear_argumentos( $args );
		$skip_missing = $this->debe_omitir_traducciones_faltantes( $parsed_args );

		$idiomas_info  = $this->servicio_api->obtener_informacion_idiomas();
		$idioma_actual = $this->servicio_api->obtener_idioma_actual() ?? $this->servicio_api->obtener_idioma_por_defecto() ?? 'es';

		$resultado = array();
		$indice    = 1;

		foreach ( $idiomas_info as $codigo => $info ) {
			// DT-TFML-058: Nunca incluir idiomas inactivos (como francés).
			if ( ! $this->servicio_api->es_idioma_activo( $codigo ) ) {
				continue;
			}

			$disponible = isset( $info['disponible'] ) ? (bool) $info['disponible'] : ( 0 === (int) ( $info['missing'] ?? 0 ) );
			$missing    = $disponible ? 0 : 1;

			if ( $skip_missing && 1 === $missing ) {
				continue;
			}

			$es_activo  = ( $codigo === $idioma_actual ) ? 1 : 0;
			$flag_url   = $this->obtener_url_bandera( $codigo );
			$url_idioma = $disponible ? (string) ( $info['url'] ?? '' ) : '';
			$accesible  = $this->obtener_representacion_accesible_idioma(
				$codigo,
				(string) ( $info['nombre_nativo'] ?? '' ),
				(string) ( $info['nombre_traducido'] ?? '' )
			);

			$resultado[ $codigo ] = array(
				'id'               => $indice,
				'active'           => $es_activo,
				'native_name'      => $info['nombre_nativo'],
				'translated_name'  => $info['nombre_traducido'],
				'language_code'    => $codigo,
				'default_locale'   => $info['locale'],
				'country_flag_url' => $flag_url,
				'has_country_flag' => ( '' !== $flag_url ) ? 1 : 0,
				'language_badge'   => $accesible['sigla'],
				'accessible_label' => $accesible['etiqueta'],
				'accessible_html'  => $accesible['html'],
				'url'              => $url_idioma,
				'missing'          => $missing,
			);

			++$indice;
		}

		return $resultado;
	}

	/**
	 * Resuelve una URL válida, local y verificada en disco para el icono lingüístico del idioma.
	 *
	 * Reglas de endurecimiento (DT-TFML-066):
	 * - Usa activos propios versionados del plugin en assets/images/flags/{codigo}.svg (o .png).
	 * - Verifica existencia física en disco antes de emitir una URL; si no existe archivo local,
	 *   retorna cadena vacía ('') para no provocar solicitudes HTTP 404.
	 * - Permite personalización mediante el filtro 'tfml_wpml_compat_country_flag_url' únicamente
	 *   hacia recursos locales o data URIs seguras, rechazando dominios externos.
	 *
	 * @param string $codigo Código de idioma (ej. 'es', 'en', 'pt').
	 * @return string URL local verificada o '' si no existe icono gráfico.
	 */
	public function obtener_url_bandera( string $codigo ): string {
		$codigo_limpio = strtolower( (string) preg_replace( '/[^a-zA-Z0-9_-]/', '', trim( $codigo ) ) );
		if ( '' === $codigo_limpio ) {
			return '';
		}

		$plugin_root = dirname( __DIR__, 2 );
		$flags_dir   = $plugin_root . '/assets/images/flags';
		$flag_base   = function_exists( 'plugins_url' )
			? plugins_url( 'assets/images/flags/', $plugin_root . '/tf-multilingual.php' )
			: '/wp-content/plugins/tf-multilingual/assets/images/flags/';

		$url_defecto = '';
		foreach ( array( 'svg', 'png' ) as $extension ) {
			$ruta_candidata = $flags_dir . '/' . $codigo_limpio . '.' . $extension;
			if ( is_file( $ruta_candidata ) && is_readable( $ruta_candidata ) ) {
				$url_defecto = rtrim( $flag_base, '/' ) . '/' . $codigo_limpio . '.' . $extension;
				break;
			}
		}

		/**
		 * Permite personalizar la URL del icono lingüístico para compatibilidad con temas específicos.
		 *
		 * @param string $url_defecto   URL local verificada o '' si no existe archivo.
		 * @param string $codigo_limpio Código de idioma normalizado.
		 */
		$url_filtrada = (string) ( function_exists( 'apply_filters' )
			? apply_filters( 'tfml_wpml_compat_country_flag_url', $url_defecto, $codigo_limpio )
			: $url_defecto );

		$url_filtrada = trim( $url_filtrada );
		if ( '' === $url_filtrada ) {
			return '';
		}

		if ( ! $this->es_url_icono_local_permitida( $url_filtrada, $flag_base ) ) {
			return $url_defecto;
		}

		return $url_filtrada;
	}

	/**
	 * Obtiene la representación textual accesible de un idioma (sigla, etiqueta y HTML accesible)
	 * para selectores sin icono gráfico o como alternativa semántica.
	 *
	 * @param string $codigo           Código de idioma (ej. 'es', 'en', 'pt').
	 * @param string $nombre_nativo    Nombre nativo del idioma.
	 * @param string $nombre_traducido Nombre traducido del idioma.
	 * @return array{sigla: string, nombre_nativo: string, etiqueta: string, html: string}
	 */
	public function obtener_representacion_accesible_idioma(
		string $codigo,
		string $nombre_nativo = '',
		string $nombre_traducido = ''
	): array {
		$codigo_limpio = strtolower( (string) preg_replace( '/[^a-zA-Z0-9_-]/', '', trim( $codigo ) ) );
		$sigla         = strtoupper( $codigo_limpio );
		$nombre        = '' !== trim( $nombre_nativo )
			? trim( $nombre_nativo )
			: ( '' !== trim( $nombre_traducido ) ? trim( $nombre_traducido ) : $sigla );
		$etiqueta      = ( '' !== $nombre && $nombre !== $sigla )
			? sprintf( '%s (%s)', $nombre, $sigla )
			: $sigla;

		return array(
			'sigla'         => $sigla,
			'nombre_nativo' => $nombre,
			'etiqueta'      => $etiqueta,
			'html'          => $this->renderizar_etiqueta_idioma_accesible( $codigo_limpio, $nombre ),
		);
	}

	/**
	 * Renderiza una insignia HTML textual accesible y autónoma para representar un idioma sin imagen.
	 *
	 * @param string $codigo        Código de idioma.
	 * @param string $nombre_nativo Nombre nativo o descriptivo del idioma.
	 * @return string Fragmento HTML seguro y accesible.
	 */
	public function renderizar_etiqueta_idioma_accesible( string $codigo, string $nombre_nativo = '' ): string {
		$codigo_limpio = strtolower( (string) preg_replace( '/[^a-zA-Z0-9_-]/', '', trim( $codigo ) ) );
		$sigla         = strtoupper( $codigo_limpio );
		$titulo        = '' !== trim( $nombre_nativo ) ? trim( $nombre_nativo ) : $sigla;

		$attr_lang  = function_exists( 'esc_attr' ) ? esc_attr( $codigo_limpio ) : htmlspecialchars( $codigo_limpio, ENT_QUOTES, 'UTF-8' );
		$attr_title = function_exists( 'esc_attr' ) ? esc_attr( $titulo ) : htmlspecialchars( $titulo, ENT_QUOTES, 'UTF-8' );
		$html_sigla = function_exists( 'esc_html' ) ? esc_html( $sigla ) : htmlspecialchars( $sigla, ENT_QUOTES, 'UTF-8' );

		return sprintf(
			'<span class="tfml-lang-badge" lang="%1$s" aria-label="%2$s"><abbr title="%2$s">%3$s</abbr></span>',
			$attr_lang,
			$attr_title,
			$html_sigla
		);
	}

	/**
	 * Determina si el argumento skip_missing solicita omitir idiomas sin traducción disponible.
	 *
	 * @param array<string, mixed> $parsed_args Argumentos parseados.
	 * @return bool True si skip_missing está activo (1, '1', true, 'true').
	 */
	private function debe_omitir_traducciones_faltantes( array $parsed_args ): bool {
		if ( ! array_key_exists( 'skip_missing', $parsed_args ) ) {
			return false;
		}

		$valor = $parsed_args['skip_missing'];
		if ( is_bool( $valor ) ) {
			return $valor;
		}
		if ( is_numeric( $valor ) ) {
			return 1 === (int) $valor;
		}
		if ( is_string( $valor ) ) {
			$limpio = strtolower( trim( $valor ) );
			return in_array( $limpio, array( '1', 'true', 'yes' ), true );
		}

		return false;
	}

	/**
	 * Verifica que una URL de icono personalizada sea local o data URI segura, rechazando hosts externos.
	 *
	 * @param string $url       URL candidata.
	 * @param string $flag_base URL base local del plugin.
	 * @return bool True si es segura y local.
	 */
	private function es_url_icono_local_permitida( string $url, string $flag_base ): bool {
		if ( str_starts_with( $url, 'data:image/svg+xml' ) || str_starts_with( $url, 'data:image/png' ) ) {
			return true;
		}

		if ( str_starts_with( $url, '/' ) && ! str_starts_with( $url, '//' ) ) {
			return true;
		}

		$parsed = parse_url( $url );
		if ( false === $parsed || empty( $parsed['host'] ) ) {
			return false;
		}

		$host_candidato = strtolower( (string) $parsed['host'] );
		$hosts_locales  = array( 'localhost', '127.0.0.1' );

		if ( function_exists( 'home_url' ) ) {
			$home_host = parse_url( (string) home_url(), PHP_URL_HOST );
			if ( is_string( $home_host ) && '' !== $home_host ) {
				$hosts_locales[] = strtolower( $home_host );
			}
		}

		$base_host = parse_url( $flag_base, PHP_URL_HOST );
		if ( is_string( $base_host ) && '' !== $base_host ) {
			$hosts_locales[] = strtolower( $base_host );
		}

		return in_array( $host_candidato, array_unique( $hosts_locales ), true );
	}

	/**
	 * Parsea argumentos ya sea que vengan como query string o como array.
	 *
	 * @param mixed $args Parámetros recibidos.
	 * @return array<string, mixed>
	 */
	private function parsear_argumentos( mixed $args ): array {
		if ( is_array( $args ) ) {
			return $args;
		}

		if ( is_string( $args ) && function_exists( 'wp_parse_args' ) ) {
			return (array) wp_parse_args( $args );
		}

		if ( is_string( $args ) && trim( $args ) !== '' ) {
			$resultado = array();
			parse_str( $args, $resultado );
			return $resultado;
		}

		return array();
	}
}
