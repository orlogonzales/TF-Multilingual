<?php
/**
 * Adaptador de Compatibilidad Heredada con WPML.
 *
 * @package TF\Multilingual\Compatibilidad
 */

declare( strict_types=1 );

namespace TF\Multilingual\Compatibilidad;

use TF\Multilingual\API\ServicioTraduccionPublico;

/**
 * Class AdaptadorCompatibilidadWpml
 *
 * Superficie B: Adaptador heredado y limitado para entornos con temas o plugins
 * que invocan llamadas clásicas de WPML (como ecoCMS con icl_object_id y icl_get_languages).
 *
 * RESTRICCIÓN ARQUITECTÓNICA ABSOLUTA (DT-TFML-058):
 * - Este adaptador NO define globalmente la constante ICL_LANGUAGE_CODE. Las constantes en PHP
 *   son inmutables por request, impiden contextos multilingües concurrentes y pueden colisionar
 *   con implementaciones externas.
 * - Este adaptador permanece DESACTIVADO POR DEFECTO y solo se activa de manera explícita.
 * - No reproduce la totalidad de WPML ni garantiza paridad con funciones no auditadas.
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
	 * @param ServicioTraduccionPublico $servicio_api Servicio público de traducción.
	 * @param bool                      $habilitado   Estado inicial (por defecto inactivo).
	 */
	public function __construct( ServicioTraduccionPublico $servicio_api, bool $habilitado = false ) {
		$this->servicio_api = $servicio_api;
		$this->habilitado   = $habilitado;

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

		$this->envoltorios_registrados = true;
		return true;
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
	 * @param string|array<string, mixed> $args Argumentos en formato query string o array.
	 * @return array<string, array<string, mixed>> Lista de idiomas activos disponibles.
	 */
	public function ejecutar_icl_get_languages( mixed $args = '' ): array {
		$parsed_args = $this->parsear_argumentos( $args );

		$idiomas_info  = $this->servicio_api->obtener_informacion_idiomas();
		$idioma_actual = $this->servicio_api->obtener_idioma_actual() ?? $this->servicio_api->obtener_idioma_por_defecto() ?? 'es';

		$resultado = array();
		$indice    = 1;

		foreach ( $idiomas_info as $codigo => $info ) {
			// DT-TFML-058: Nunca incluir idiomas inactivos (como francés).
			if ( ! $this->servicio_api->es_idioma_activo( $codigo ) ) {
				continue;
			}

			$es_activo = ( $codigo === $idioma_actual ) ? 1 : 0;
			$flag_url  = $this->obtener_url_bandera( $codigo );

			$resultado[ $codigo ] = array(
				'id'               => $indice,
				'active'           => $es_activo,
				'native_name'      => $info['nombre_nativo'],
				'translated_name'  => $info['nombre_traducido'],
				'language_code'    => $codigo,
				'default_locale'   => $info['locale'],
				'country_flag_url' => $flag_url,
				'url'              => $info['url'],
				'missing'          => 0,
			);

			++$indice;
		}

		return $resultado;
	}

	/**
	 * Resuelve una URL válida y segura para la bandera del idioma.
	 *
	 * @param string $codigo Código de idioma (ej. 'es', 'en').
	 * @return string URL del recurso de la bandera.
	 */
	public function obtener_url_bandera( string $codigo ): string {
		$flag_base = function_exists( 'plugins_url' )
			? plugins_url( 'assets/images/flags/', dirname( __DIR__, 2 ) . '/tf-multilingual.php' )
			: '/wp-content/plugins/tf-multilingual/assets/images/flags/';

		$url_defecto = rtrim( $flag_base, '/' ) . '/' . $codigo . '.png';

		/**
		 * Permite filtrar la URL de la bandera para compatibilidad con temas específicos.
		 *
		 * @param string $url_defecto URL construida por defecto.
		 * @param string $codigo      Código de idioma.
		 */
		return (string) ( function_exists( 'apply_filters' )
			? apply_filters( 'tfml_wpml_compat_country_flag_url', $url_defecto, $codigo )
			: $url_defecto );
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
