<?php
/**
 * Resolvedor Nativo de Portada Multilingüe.
 *
 * @package TF\Multilingual\Routing
 */

declare( strict_types=1 );

namespace TF\Multilingual\Routing;

use TF\Multilingual\Domain\Language\Language;
use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Domain\Translation\ContentTranslationResolver;
use WP_Post;
use WP_Query;

/**
 * Clase ResolvedorPortadaMultilingue
 *
 * Resuelve de manera nativa, genérica y sin mutación de base de datos la portada estática
 * multilingüe en WordPress (`show_on_front = 'page'` y `page_on_front`).
 *
 * Principios Arquitectónicos Soberanos (DT-TFML-063 — Fase 3.33):
 * - WordPress continúa resolviendo WordPress; TFML aporta únicamente la dimensión lingüística.
 * - Cero escritura en base de datos: `page_on_front` y `show_on_front` permanecen intactos en `wp_options`.
 * - Cero IDs o slugs cableados: toda resolución parte del valor crudo de `page_on_front` y del grupo
 *   de traducción en `ContentTranslationResolver`.
 * - En el idioma predeterminado (`/`), sirve la página configurada en `page_on_front`.
 * - En un idioma secundario activo (`/{lang}/`), si existe traducción publicada en el grupo de
 *   la portada base, resuelve dicho post como portada estática (`is_front_page() === true`).
 * - Política explícita de traducción ausente (sin fallback silencioso): si un idioma secundario activo
 *   no posee traducción publicada de la portada estática, mantiene la restricción de idioma para que
 *   la consulta principal produzca un 404 limpio (`is_404() === true`), sin mostrar contenido del
 *   idioma por defecto ni degradar silenciosamente a archivo de entradas.
 * - Normaliza los enlaces permanentes (`page_link`) y la redirección canónica (`redirect_canonical`)
 *   para que la portada de cada idioma activo resuelva siempre a su raíz canónica (`/` o `/{lang}/`),
 *   redirigiendo con HTTP 301 cualquier acceso directo al slug de la portada traducida (`/{lang}/{slug}/`).
 * - Aislamiento estricto de contextos no públicos: `wp-admin`, REST API, Cron, AJAX y WP-CLI quedan
 *   excluidos para preservar intacta la administración de WordPress.
 */
class ResolvedorPortadaMultilingue {

	/**
	 * Variable interna de consulta para garantizar idempotencia en la normalización de WP_Query.
	 */
	public const QUERY_VAR_NORMALIZADA = '_tfml_portada_normalizada';

	/**
	 * Variable interna de consulta que señala ausencia de portada traducida en idioma secundario.
	 */
	public const QUERY_VAR_PORTADA_AUSENTE = '_tfml_portada_traducida_ausente';

	/**
	 * Registro oficial de idiomas.
	 *
	 * @var LanguageRegistry
	 */
	private LanguageRegistry $registro_idiomas;

	/**
	 * Resolvedor del idioma activo de la petición actual.
	 *
	 * @var CurrentLanguageResolver
	 */
	private CurrentLanguageResolver $resolutor_idioma_actual;

	/**
	 * Resolvedor de traducciones de contenido.
	 *
	 * @var ContentTranslationResolver
	 */
	private ContentTranslationResolver $resolvedor_traducciones;

	/**
	 * Generador de URLs localizadas.
	 *
	 * @var LocalizedUrlGenerator
	 */
	private LocalizedUrlGenerator $generador_urls;

	/**
	 * Semáforo antirrecursión para lectura cruda de `page_on_front`.
	 *
	 * @var bool
	 */
	private bool $en_resolucion_opcion = false;

	/**
	 * Semáforo antirrecursión para filtrado de `page_link`.
	 *
	 * @var bool
	 */
	private bool $en_resolucion_enlace = false;

	/**
	 * Indicador para permitir la ejecución en entorno CLI durante pruebas automatizadas.
	 *
	 * @var bool
	 */
	private bool $permitir_en_cli = false;

	/**
	 * Constructor.
	 *
	 * @param LanguageRegistry           $registro_idiomas         Registro oficial de idiomas.
	 * @param CurrentLanguageResolver    $resolutor_idioma_actual Resolutor de idioma actual.
	 * @param ContentTranslationResolver $resolvedor_traducciones  Resolvedor de traducciones.
	 * @param LocalizedUrlGenerator      $generador_urls           Generador de URLs localizadas.
	 */
	public function __construct(
		LanguageRegistry $registro_idiomas,
		CurrentLanguageResolver $resolutor_idioma_actual,
		ContentTranslationResolver $resolvedor_traducciones,
		LocalizedUrlGenerator $generador_urls
	) {
		$this->registro_idiomas        = $registro_idiomas;
		$this->resolutor_idioma_actual = $resolutor_idioma_actual;
		$this->resolvedor_traducciones = $resolvedor_traducciones;
		$this->generador_urls          = $generador_urls;
	}

	/**
	 * Registra los ganchos nativos de WordPress para resolución de portada multilingüe.
	 *
	 * @return void
	 */
	public function registrar_ganchos(): void {
		if ( function_exists( 'add_filter' ) ) {
			add_filter( 'option_page_on_front', array( $this, 'filtrar_opcion_page_on_front' ), 10, 1 );
			add_filter( 'page_link', array( $this, 'filtrar_enlace_pagina' ), 10, 3 );
			add_filter( 'redirect_canonical', array( $this, 'filtrar_redirect_canonical' ), 10, 2 );
		}

		if ( function_exists( 'add_action' ) ) {
			add_action( 'parse_query', array( $this, 'normalizar_consulta_principal_portada' ), 5, 1 );
			add_action( 'pre_get_posts', array( $this, 'normalizar_consulta_principal_portada' ), 5, 1 );
		}
	}

	/**
	 * Desregistra los ganchos de WordPress (útil en pruebas unitarias y limpieza de estado).
	 *
	 * @return void
	 */
	public function desregistrar_ganchos(): void {
		if ( function_exists( 'remove_filter' ) ) {
			remove_filter( 'option_page_on_front', array( $this, 'filtrar_opcion_page_on_front' ), 10 );
			remove_filter( 'page_link', array( $this, 'filtrar_enlace_pagina' ), 10 );
			remove_filter( 'redirect_canonical', array( $this, 'filtrar_redirect_canonical' ), 10 );
		}

		if ( function_exists( 'remove_action' ) ) {
			remove_action( 'parse_query', array( $this, 'normalizar_consulta_principal_portada' ), 5 );
			remove_action( 'pre_get_posts', array( $this, 'normalizar_consulta_principal_portada' ), 5 );
		}
	}

	/**
	 * Habilita o deshabilita la resolución en entorno CLI (exclusivo para pruebas automatizadas).
	 *
	 * @param bool $habilitar True para permitir ejecución bajo WP_CLI en pruebas.
	 * @return void
	 */
	public function habilitar_en_cli_para_pruebas( bool $habilitar ): void {
		$this->permitir_en_cli = $habilitar;
	}

	/**
	 * Determina si el contexto de ejecución actual es público y elegible para resolución de portada.
	 *
	 * Excluye explícitamente administración (wp-admin), peticiones REST, tareas Cron,
	 * peticiones AJAX y ejecuciones WP-CLI (salvo habilitación explícita de pruebas).
	 *
	 * @return bool True si el contexto es público elegible.
	 */
	public function es_contexto_publico_elegible(): bool {
		if ( function_exists( 'is_admin' ) && is_admin() ) {
			return false;
		}

		if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( function_exists( 'wp_is_json_request' ) && wp_is_json_request() ) ) {
			return false;
		}

		if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
			return false;
		}

		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			return false;
		}

		if ( defined( 'WP_CLI' ) && WP_CLI && ! $this->permitir_en_cli ) {
			return false;
		}

		return true;
	}

	/**
	 * Verifica si WordPress está configurado para mostrar una página estática en la portada.
	 *
	 * @return bool True si `show_on_front === 'page'` y existe un `page_on_front` base mayor que cero.
	 */
	public function es_modo_portada_estatica(): bool {
		$mostrar_en_portada = function_exists( 'get_option' ) ? get_option( 'show_on_front', 'posts' ) : 'posts';
		if ( 'page' !== $mostrar_en_portada ) {
			return false;
		}

		return $this->obtener_id_portada_base() > 0;
	}

	/**
	 * Obtiene el ID crudo de `page_on_front` almacenado en WordPress sin activar recursión.
	 *
	 * @return int ID numérico de la portada base configurada o 0 si no está definida.
	 */
	public function obtener_id_portada_base(): int {
		if ( $this->en_resolucion_opcion ) {
			$crudo = $GLOBALS['wp_test_options']['page_on_front'] ?? 0;
			return is_numeric( $crudo ) ? max( 0, (int) $crudo ) : 0;
		}

		$this->en_resolucion_opcion = true;
		try {
			$valor = function_exists( 'get_option' ) ? get_option( 'page_on_front', 0 ) : 0;
			return is_numeric( $valor ) ? max( 0, (int) $valor ) : 0;
		} finally {
			$this->en_resolucion_opcion = false;
		}
	}

	/**
	 * Resuelve el ID de la página de portada publicada para un idioma específico.
	 *
	 * Reglas:
	 * - El idioma debe estar registrado y activo en `LanguageRegistry`.
	 * - El sitio debe estar en modo portada estática (`show_on_front === 'page'`).
	 * - Para el idioma por defecto: retorna el ID base si está publicado y pertenece al idioma
	 *   por defecto (o no está asignado a otro idioma).
	 * - Para un idioma secundario activo: resuelve la traducción vinculada al ID base mediante
	 *   `ContentTranslationResolver` y verifica que el post destino esté publicado (`publish`).
	 * - Si no existe traducción o no está publicada, retorna `null` (cero fallback silencioso).
	 *
	 * @param string   $codigo_idioma     Código de idioma destino (ej. 'es', 'en').
	 * @param int|null $id_base_explicito ID base opcional cuando ya fue leído por el llamador.
	 * @return int|null ID del post de portada para ese idioma, o null si no existe/no es elegible.
	 */
	public function obtener_id_portada_para_idioma( string $codigo_idioma, ?int $id_base_explicito = null ): ?int {
		if ( ! $this->registro_idiomas->is_configured() ) {
			return null;
		}

		$codigo = Language::normalize_code( $codigo_idioma );
		if ( '' === $codigo || ! $this->registro_idiomas->has( $codigo ) || ! $this->registro_idiomas->is_active( $codigo ) ) {
			return null;
		}

		$mostrar_en_portada = function_exists( 'get_option' ) ? get_option( 'show_on_front', 'posts' ) : 'posts';
		if ( 'page' !== $mostrar_en_portada ) {
			return null;
		}

		$id_base = null !== $id_base_explicito ? $id_base_explicito : $this->obtener_id_portada_base();
		if ( $id_base <= 0 ) {
			return null;
		}

		$idioma_defecto = $this->registro_idiomas->get_default_code();

		if ( $codigo === $idioma_defecto ) {
			if ( ! $this->es_post_publicado( $id_base ) ) {
				return null;
			}

			$idioma_asignado = $this->resolvedor_traducciones->language_of( 'post', $id_base );
			if ( null !== $idioma_asignado && $idioma_asignado !== $idioma_defecto ) {
				$id_en_defecto = $this->resolvedor_traducciones->resolve_element_id( 'post', $id_base, $idioma_defecto );
				if ( null !== $id_en_defecto && $id_en_defecto > 0 && $this->es_post_publicado( $id_en_defecto ) ) {
					return $id_en_defecto;
				}
				return null;
			}

			return $id_base;
		}

		try {
			$id_traducido = $this->resolvedor_traducciones->resolve_element_id( 'post', $id_base, $codigo );
		} catch ( \Throwable $e ) {
			return null;
		}

		if ( null === $id_traducido || $id_traducido <= 0 ) {
			return null;
		}

		if ( ! $this->es_post_publicado( $id_traducido ) ) {
			return null;
		}

		return $id_traducido;
	}

	/**
	 * Obtiene el ID de la portada correspondiente al idioma público actual.
	 *
	 * @return int|null ID de la portada para el idioma actual o null si no existe.
	 */
	public function obtener_id_portada_actual(): ?int {
		$idioma_actual = $this->resolutor_idioma_actual->get_current_language()
			?? $this->registro_idiomas->get_default_code();

		if ( null === $idioma_actual || '' === $idioma_actual ) {
			return null;
		}

		return $this->obtener_id_portada_para_idioma( $idioma_actual );
	}

	/**
	 * Determina si un ID de post corresponde a la portada estática de un idioma específico (o del idioma actual).
	 *
	 * @param int         $post_id       ID del post a verificar.
	 * @param string|null $codigo_idioma Código de idioma opcional. Si es null, evalúa el idioma propio del post.
	 * @return bool True si el post es la portada estática publicada de dicho idioma activo.
	 */
	public function es_portada_de_idioma( int $post_id, ?string $codigo_idioma = null ): bool {
		if ( $post_id <= 0 ) {
			return false;
		}

		if ( null !== $codigo_idioma && '' !== trim( $codigo_idioma ) ) {
			$id_esperado = $this->obtener_id_portada_para_idioma( $codigo_idioma );
			return null !== $id_esperado && $id_esperado === $post_id;
		}

		return null !== $this->obtener_idioma_de_portada( $post_id );
	}

	/**
	 * Si el post indicado es la portada estática publicada de algún idioma activo, retorna su código de idioma.
	 *
	 * @param int $post_id ID del post a inspeccionar.
	 * @return string|null Código de idioma activo del cual este post es portada, o null si no es portada.
	 */
	public function obtener_idioma_de_portada( int $post_id ): ?string {
		if ( $post_id <= 0 || ! $this->registro_idiomas->is_configured() ) {
			return null;
		}

		$mostrar_en_portada = function_exists( 'get_option' ) ? get_option( 'show_on_front', 'posts' ) : 'posts';
		if ( 'page' !== $mostrar_en_portada ) {
			return null;
		}

		$id_base = $this->obtener_id_portada_base();
		if ( $id_base <= 0 ) {
			return null;
		}

		$idioma_defecto = $this->registro_idiomas->get_default_code();

		if ( $post_id === $id_base && null !== $idioma_defecto ) {
			$id_efectivo_defecto = $this->obtener_id_portada_para_idioma( $idioma_defecto, $id_base );
			if ( $id_efectivo_defecto === $post_id ) {
				return $idioma_defecto;
			}
		}

		try {
			$idioma_post = $this->resolvedor_traducciones->language_of( 'post', $post_id );
		} catch ( \Throwable $e ) {
			return null;
		}

		if ( null === $idioma_post || ! $this->registro_idiomas->is_active( $idioma_post ) ) {
			return null;
		}

		$id_portada_idioma = $this->obtener_id_portada_para_idioma( $idioma_post, $id_base );
		if ( null !== $id_portada_idioma && $id_portada_idioma === $post_id ) {
			return $idioma_post;
		}

		return null;
	}

	/**
	 * Filtra en tiempo de ejecución la opción `page_on_front` en contextos públicos elegibles.
	 *
	 * Cuando el idioma activo es secundario y cuenta con traducción publicada de la portada base,
	 * retorna el ID traducido para que `is_front_page()` y `WP_Query` operen de forma nativa.
	 * Si no existe traducción publicada para el idioma secundario activo, conserva el valor original
	 * para que `QueryLanguageFilter` impida cualquier fallback silencioso y produzca HTTP 404.
	 *
	 * @param mixed $valor Valor original de `page_on_front`.
	 * @return mixed Valor efectivo de `page_on_front` para el contexto actual.
	 */
	public function filtrar_opcion_page_on_front( mixed $valor ): mixed {
		if ( $this->en_resolucion_opcion ) {
			return $valor;
		}

		if ( ! $this->es_contexto_publico_elegible() ) {
			return $valor;
		}

		if ( ! $this->registro_idiomas->is_configured() ) {
			return $valor;
		}

		$id_base = is_numeric( $valor ) ? (int) $valor : 0;
		if ( $id_base <= 0 ) {
			return $valor;
		}

		$this->en_resolucion_opcion = true;
		try {
			$idioma_actual = $this->resolutor_idioma_actual->get_current_language();
			if ( null === $idioma_actual || '' === $idioma_actual || ! $this->registro_idiomas->is_active( $idioma_actual ) ) {
				return $valor;
			}

			$idioma_defecto = $this->registro_idiomas->get_default_code();
			if ( $idioma_actual === $idioma_defecto ) {
				return $valor;
			}

			$id_traducido = $this->obtener_id_portada_para_idioma( $idioma_actual, $id_base );
			if ( null !== $id_traducido && $id_traducido > 0 ) {
				return is_string( $valor ) ? (string) $id_traducido : $id_traducido;
			}

			// Sin traducción publicada en el idioma secundario: conservar $valor (ID del idioma por defecto)
			// para que el filtro SQL por idioma rechace la coincidencia y WordPress emita 404 limpio.
			return $valor;
		} finally {
			$this->en_resolucion_opcion = false;
		}
	}

	/**
	 * Normaliza la consulta principal (`WP_Query`) cuando se solicita la raíz de un idioma (`/` o `/{lang}/`).
	 *
	 * En WordPress Core (`WP_Query::parse_query()`), la condición nativa que convierte la raíz del sitio
	 * en consulta de portada estática (`is_page = true`, `page_id = page_on_front`) comprueba que `$this->query`
	 * solo contenga `preview`, `page`, `paged` o `cpage`. En rutas prefijadas (`/{lang}/`), la presencia
	 * de `tfml_lang` impide que WordPress ejecute esa promoción automáticamente.
	 *
	 * Este método restaura dicho comportamiento nativo en la consulta principal:
	 * - Si existe portada publicada para el idioma solicitado: asigna `page_id = $id_portada_idioma`,
	 *   `is_page = true`, `is_singular = true`, `is_home = false`.
	 * - Si el idioma secundario activo carece de portada publicada: asigna `page_id = $id_base` y marca
	 *   `QUERY_VAR_PORTADA_AUSENTE = true`, garantizando que `QueryLanguageFilter` produzca 0 resultados
	 *   y WordPress responda HTTP 404 sin fallback al español ni archivo de posts.
	 *
	 * @param WP_Query $consulta Instancia de consulta de WordPress.
	 * @return void
	 */
	public function normalizar_consulta_principal_portada( WP_Query $consulta ): void {
		if ( ! $consulta->is_main_query() ) {
			return;
		}

		if ( true === $consulta->get( self::QUERY_VAR_NORMALIZADA ) ) {
			return;
		}

		if ( ! $this->es_contexto_publico_elegible() ) {
			return;
		}

		if ( ! $this->registro_idiomas->is_configured() || ! $this->es_modo_portada_estatica() ) {
			return;
		}

		if ( $consulta->is_preview() || ( function_exists( 'is_sitemap' ) && is_sitemap() ) ) {
			return;
		}

		$id_base = $this->obtener_id_portada_base();
		if ( $id_base <= 0 ) {
			return;
		}

		if ( ! $this->es_peticion_raiz_de_idioma( $consulta, $id_base ) ) {
			return;
		}

		$idioma_consulta = $consulta->get( RewriteManager::QUERY_VAR );
		$codigo_idioma   = ( is_string( $idioma_consulta ) && '' !== $idioma_consulta )
			? Language::normalize_code( $idioma_consulta )
			: ( $this->resolutor_idioma_actual->get_current_language() ?? $this->registro_idiomas->get_default_code() );

		if ( null === $codigo_idioma || '' === $codigo_idioma || ! $this->registro_idiomas->is_active( $codigo_idioma ) ) {
			return;
		}

		// Sincronizar CurrentLanguageResolver si la variable tfml_lang viene en la consulta.
		if ( $this->resolutor_idioma_actual->get_current_language() !== $codigo_idioma ) {
			$this->resolutor_idioma_actual->set_current_language( $codigo_idioma );
		}

		$id_portada = $this->obtener_id_portada_para_idioma( $codigo_idioma, $id_base );

		$consulta->is_page     = true;
		$consulta->is_home     = false;
		$consulta->is_singular = true;

		$paged = $consulta->get( 'paged' );
		if ( ! empty( $paged ) ) {
			$consulta->set( 'page', $paged );
			$consulta->set( 'paged', '' );
		}

		if ( null !== $id_portada && $id_portada > 0 ) {
			$consulta->set( 'page_id', $id_portada );
			$consulta->set( self::QUERY_VAR_PORTADA_AUSENTE, false );
		} else {
			// Política de ausencia de traducción (DT-TFML-063 Sección 7):
			// Asignar el ID base para que QueryLanguageFilter (AND tfml_ge.language_code = '{lang}')
			// produzca 0 filas y WordPress active su flujo 404 nativo sin servir contenido de otro idioma.
			$consulta->set( 'page_id', $id_base );
			$consulta->set( self::QUERY_VAR_PORTADA_AUSENTE, true );
		}

		$consulta->set( self::QUERY_VAR_NORMALIZADA, true );
	}

	/**
	 * Filtra `page_link` para que el enlace permanente de cualquier portada estática activa
	 * sea siempre la raíz canónica de su idioma (`/` o `/{lang}/`), nunca `/{lang}/{slug}/`.
	 *
	 * @param string $enlace  URL generada por WordPress Core.
	 * @param int    $post_id ID de la página.
	 * @param bool   $muestra True si se solicita un enlace de muestra en el editor administrativo.
	 * @return string URL canónica de portada o enlace original.
	 */
	public function filtrar_enlace_pagina( string $enlace, int $post_id, bool $muestra = false ): string {
		if ( $muestra || $this->en_resolucion_enlace ) {
			return $enlace;
		}

		if ( $post_id <= 0 || ! $this->registro_idiomas->is_configured() ) {
			return $enlace;
		}

		$this->en_resolucion_enlace = true;
		try {
			$idioma_portada = $this->obtener_idioma_de_portada( $post_id );
			if ( null === $idioma_portada ) {
				return $enlace;
			}

			return $this->generador_urls->home_url( $idioma_portada );
		} catch ( \Throwable $e ) {
			return $enlace;
		} finally {
			$this->en_resolucion_enlace = false;
		}
	}

	/**
	 * Filtra `redirect_canonical` para garantizar:
	 * 1. Que la raíz de una portada secundaria (`/{lang}/`) NO sea redirigida erróneamente a `/`
	 *    cuando WordPress Core evalúa `get_queried_object_id() === (int) get_option('page_on_front')`.
	 * 2. Que el acceso directo al slug de una portada secundaria (`/{lang}/{slug}/`, ej. `/en/home/`)
	 *    redirija limpiamente con HTTP 301 hacia la raíz canónica de su idioma (`/{lang}/`, ej. `/en/`),
	 *    preservando parámetros de consulta (query string) si existen y evitando bucles.
	 * 3. Que páginas ordinarias no vinculadas a `page_on_front` conserven su comportamiento normal.
	 *
	 * @param string|false $url_redireccion URL de redirección calculada por WordPress Core.
	 * @param string       $url_solicitada  URL original solicitada por el cliente.
	 * @return string|false URL de redirección canónica ajustada, o false para cancelar redirección redundante.
	 */
	public function filtrar_redirect_canonical( string|false $url_redireccion, string $url_solicitada ): string|false {
		if ( false === $url_redireccion || '' === trim( $url_redireccion ) ) {
			return $url_redireccion;
		}

		if ( ! $this->es_contexto_publico_elegible() || ! $this->registro_idiomas->is_configured() || ! $this->es_modo_portada_estatica() ) {
			return $url_redireccion;
		}

		if ( function_exists( 'is_404' ) && is_404() ) {
			$idioma_actual  = $this->resolutor_idioma_actual->get_current_language();
			$idioma_defecto = $this->registro_idiomas->get_default_code();
			if ( null !== $idioma_actual && $idioma_actual !== $idioma_defecto ) {
				return false;
			}
			return $url_redireccion;
		}

		$id_objeto = function_exists( 'get_queried_object_id' ) ? (int) get_queried_object_id() : 0;
		if ( $id_objeto <= 0 ) {
			return $url_redireccion;
		}

		$idioma_portada = $this->obtener_idioma_de_portada( $id_objeto );
		if ( null === $idioma_portada ) {
			return $url_redireccion;
		}

		$idioma_defecto = $this->registro_idiomas->get_default_code();
		if ( $idioma_portada === $idioma_defecto ) {
			return $url_redireccion;
		}

		// El objeto consultado es la portada estática de un idioma secundario activo (ej. 8356 en 'en').
		// WordPress Core en canonical.php línea 267 asigna $redirect_url = home_url('/') sin prefijo de idioma.
		// Localizamos la URL de destino al idioma de la portada preservando paginación y query string.
		$pagina_actual = function_exists( 'get_query_var' ) ? max( (int) get_query_var( 'page', 0 ), (int) get_query_var( 'paged', 0 ) ) : 0;

		if ( $pagina_actual <= 1 ) {
			$url_canonica_idioma = $this->generador_urls->home_url( $idioma_portada );
			$partes_redireccion  = parse_url( $url_redireccion );
			if ( is_array( $partes_redireccion ) && ! empty( $partes_redireccion['query'] ) ) {
				$url_canonica_idioma .= '?' . $partes_redireccion['query'];
			}
		} else {
			$url_canonica_idioma = $this->generador_urls->localize_url( $url_redireccion, $idioma_portada );
		}

		if ( $this->urls_equivalentes_sin_fragmento( $url_canonica_idioma, $url_solicitada ) ) {
			return false;
		}

		return $url_canonica_idioma;
	}

	/**
	 * Determina si la consulta principal corresponde a la solicitud de la raíz de un idioma (`/` o `/{lang}/`).
	 *
	 * @param WP_Query $consulta Instancia de consulta.
	 * @param int      $id_base  ID base de `page_on_front`.
	 * @return bool True si la consulta representa la raíz de un idioma sin otros filtros de contenido.
	 */
	private function es_peticion_raiz_de_idioma( WP_Query $consulta, int $id_base ): bool {
		if ( $consulta->is_search() || $consulta->is_archive() || ( isset( $consulta->is_404 ) && $consulta->is_404 ) || ( isset( $consulta->is_feed ) && $consulta->is_feed ) ) {
			return false;
		}

		$claves_permitidas = array(
			RewriteManager::QUERY_VAR,
			'preview',
			'page',
			'paged',
			'cpage',
		);

		// 1. Si WP_Query contiene el arreglo original $consulta->query (comportamiento estándar de WordPress Core).
		if ( isset( $consulta->query ) && is_array( $consulta->query ) && ! empty( $consulta->query ) ) {
			$copia_query = $consulta->query;
			if ( isset( $copia_query['pagename'] ) && '' === $copia_query['pagename'] ) {
				unset( $copia_query['pagename'] );
			}
			unset( $copia_query['embed'] );

			$claves_extra = array_diff( array_keys( $copia_query ), $claves_permitidas );
			if ( ! empty( $claves_extra ) ) {
				return false;
			}
		}

		// 2. Inspeccionar $consulta->query_vars para descartar consultas singulares, taxonómicas o archivos.
		$variables_excluyentes = array(
			'p',
			'name',
			'pagename',
			'post_type',
			'category_name',
			'cat',
			'tag',
			'tag_id',
			'tax_query',
			'taxonomy',
			'term',
			's',
			'author',
			'author_name',
			'year',
			'monthnum',
			'day',
			'm',
			'feed',
			'sitemap',
			'attachment',
			'attachment_id',
			'error',
		);

		foreach ( $variables_excluyentes as $clave ) {
			$valor = $consulta->get( $clave, '' );
			if ( '' !== $valor && 0 !== $valor && array() !== $valor && null !== $valor ) {
				return false;
			}
		}

		$page_id_actual = (int) $consulta->get( 'page_id', 0 );
		if ( $page_id_actual > 0 && $page_id_actual !== $id_base ) {
			return false;
		}

		return true;
	}

	/**
	 * Verifica si un post existe y tiene estado `publish`.
	 *
	 * @param int $post_id ID del post.
	 * @return bool True si el post está publicado.
	 */
	private function es_post_publicado( int $post_id ): bool {
		if ( $post_id <= 0 ) {
			return false;
		}

		if ( isset( $GLOBALS['wp_test_posts'][ $post_id ] ) && $GLOBALS['wp_test_posts'][ $post_id ] instanceof WP_Post ) {
			return 'publish' === $GLOBALS['wp_test_posts'][ $post_id ]->post_status;
		}

		if ( function_exists( 'get_post_status' ) ) {
			$estado = get_post_status( $post_id );
			if ( false !== $estado ) {
				return 'publish' === $estado;
			}
		}

		if ( function_exists( 'get_post' ) ) {
			$post = get_post( $post_id );
			return $post instanceof WP_Post && 'publish' === $post->post_status;
		}

		return false;
	}

	/**
	 * Compara dos URLs ignorando fragmentos (`#...`).
	 *
	 * @param string $url_a Primera URL.
	 * @param string $url_b Segunda URL.
	 * @return bool True si son idénticas sin considerar el fragmento.
	 */
	private function urls_equivalentes_sin_fragmento( string $url_a, string $url_b ): bool {
		$limpia_a = preg_replace( '/#.*$/', '', trim( $url_a ) ) ?? trim( $url_a );
		$limpia_b = preg_replace( '/#.*$/', '', trim( $url_b ) ) ?? trim( $url_b );

		return $limpia_a === $limpia_b;
	}
}
