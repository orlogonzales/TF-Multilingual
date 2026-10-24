<?php
/**
 * Filtro de Localización de Cadenas en Frontend.
 *
 * @package TF\Multilingual\Domain\Strings
 */

declare( strict_types=1 );

namespace TF\Multilingual\Domain\Strings;

use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Routing\CurrentLanguageResolver;

/**
 * Clase StringFrontendFilter
 *
 * Intercepta y localiza cadenas compartidas de interfaz y temas en el frontend
 * mediante un procesador contextual, acotado y de alto rendimiento.
 *
 * Principios de seguridad lingüística (DT-TFML-093):
 * - Delimita estrictamente nodos de texto visibles y atributos permitidos (alt, title, aria-label).
 * - Protege y preserva intactos bloques opacos técnicos: scripts, JSON-LD, estilos, SVGs y comentarios.
 * - Rechaza la alteración de atributos técnicos: href, src, id, class, name, data-*, style, rel, action.
 * - Cero sobrecarga en el idioma predeterminado (Español): omite el búfer si el idioma es el primario.
 * - No modifica HTML malformado ni entidades, preservando codificación UTF-8 y marcado Elementor.
 * - Previene regresiones en REST API, AJAX administrativo, Cron y CLI.
 */
class StringFrontendFilter {

	/**
	 * Repositorio de cadenas.
	 *
	 * @var StringRepository
	 */
	private StringRepository $repository;

	/**
	 * Registro de idiomas.
	 *
	 * @var LanguageRegistry
	 */
	private LanguageRegistry $language_registry;

	/**
	 * Resolutor de idioma actual.
	 *
	 * @var CurrentLanguageResolver
	 */
	private CurrentLanguageResolver $current_language_resolver;

	/**
	 * Dominios de cadenas elegibles para reemplazo en frontend.
	 *
	 * @var array<string>
	 */
	private array $target_domains = array( 'ecoCMS', 'theme', 'default' );

	/**
	 * Atributos HTML expresamente traducibles permitidos.
	 *
	 * @var array<string>
	 */
	private array $whitelisted_attributes = array( 'alt', 'title', 'aria-label' );

	/**
	 * Indicador de si el búfer fue iniciado.
	 *
	 * @var bool
	 */
	private bool $buffer_started = false;

	/**
	 * Constructor.
	 *
	 * @param StringRepository        $repository                Repositorio de cadenas.
	 * @param LanguageRegistry        $language_registry         Registro de idiomas.
	 * @param CurrentLanguageResolver $current_language_resolver Resolutor de idioma actual.
	 */
	public function __construct(
		StringRepository $repository,
		LanguageRegistry $language_registry,
		CurrentLanguageResolver $current_language_resolver
	) {
		$this->repository                = $repository;
		$this->language_registry         = $language_registry;
		$this->current_language_resolver = $current_language_resolver;
	}

	/**
	 * Registra los ganchos de WordPress.
	 *
	 * @return void
	 */
	public function init_hooks(): void {
		if ( function_exists( 'add_action' ) ) {
			add_action( 'template_redirect', array( $this, 'iniciar_buffer_frontend' ), 100 );
		}
	}

	/**
	 * Evalúa si el contexto actual de ejecución es público y elegible para filtrado.
	 *
	 * @return bool True si es elegible.
	 */
	public function es_contexto_publico_elegible(): bool {
		if ( function_exists( 'is_admin' ) && is_admin() ) {
			return false;
		}

		if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
			return false;
		}

		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			return false;
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return false;
		}

		return true;
	}

	/**
	 * Inicia el búfer de salida en frontend si la petición corresponde a un idioma secundario activo.
	 *
	 * @return void
	 */
	public function iniciar_buffer_frontend(): void {
		if ( ! $this->es_contexto_publico_elegible() ) {
			return;
		}

		$current_lang = $this->current_language_resolver->get_current_language();
		if ( null === $current_lang || '' === $current_lang ) {
			return;
		}

		// Si el idioma es el predeterminado soberano, no requiere reemplazos de salida.
		$default_lang = $this->language_registry->get_default()?->get_code() ?? 'es';
		if ( $current_lang === $default_lang ) {
			return;
		}

		// Si el idioma no está activo, omitir.
		if ( ! $this->language_registry->is_active( $current_lang ) ) {
			return;
		}

		if ( ! $this->buffer_started ) {
			$this->buffer_started = true;
			ob_start( array( $this, 'filtrar_salida_buffer' ) );
		}
	}

	/**
	 * Filtra el contenido del búfer reemplazando cadenas originales por sus traducciones gestionadas
	 * exclusivamente en nodos de texto visibles y atributos permitidos (alt, title, aria-label).
	 *
	 * @param string $buffer Contenido HTML de la página.
	 * @return string HTML con las cadenas traducidas contextualmente.
	 */
	public function filtrar_salida_buffer( string $buffer ): string {
		if ( '' === trim( $buffer ) ) {
			return $buffer;
		}

		$current_lang = $this->current_language_resolver->get_current_language();
		if ( null === $current_lang || '' === $current_lang ) {
			return $buffer;
		}

		$default_lang = $this->language_registry->get_default()?->get_code() ?? 'es';
		if ( $current_lang === $default_lang ) {
			return $buffer;
		}

		$pairs = $this->repository->get_translation_pairs_by_language( $current_lang, $this->target_domains );
		if ( empty( $pairs ) ) {
			return $buffer;
		}

		return $this->procesar_html_contextual( $buffer, $pairs );
	}

	/**
	 * Procesa de forma contextual y segura el HTML delimitando bloques opacos, etiquetas y texto visible.
	 *
	 * @param string                $html  Documento HTML completo.
	 * @param array<string, string> $pairs Pares asociativos [original => traducción].
	 * @return string HTML procesado contextualmente.
	 */
	public function procesar_html_contextual( string $html, array $pairs ): string {
		if ( '' === trim( $html ) || empty( $pairs ) ) {
			return $html;
		}

		// 1. Proteger bloques técnicos opacos (scripts, JSON-LD, estilos, SVGs, textareas y comentarios).
		$bloques_protegidos = array();
		$html_protegido     = $this->proteger_bloques_opacos( $html, $bloques_protegidos );

		// 2. Segmentar el documento entre etiquetas HTML y nodos de texto visibles, respetando comillas con >.
		$parts = preg_split( '/(<(?:[^>"\'\s]|"[^"]*"|\'[^\']*\'|[\s])*?>)/s', $html_protegido, -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( false === $parts ) {
			return $html;
		}

		// Patrón estricto: requiere delimitador de inicio de atributo [\s<], impidiendo coincidir con data-title o similares.
		$attr_pattern = '/(?<=[\s<])(' . implode( '|', $this->whitelisted_attributes ) . ')\s*=\s*(?:(["\'])(.*?)\2|([^\s>]+))/is';

		for ( $i = 0, $len = count( $parts ); $i < $len; $i++ ) {
			if ( 1 === ( $i % 2 ) ) {
				// Índice impar: Etiqueta HTML. Solo se traducen atributos explícitamente autorizados.
				$parts[ $i ] = $this->localizar_atributos_etiqueta( $parts[ $i ], $pairs, $attr_pattern );
			} else {
				// Índice par: Nodo de texto visible entre etiquetas.
				if ( '' !== trim( $parts[ $i ] ) ) {
					$parts[ $i ] = strtr( $parts[ $i ], $pairs );
				}
			}
		}

		$html_reconstruido = implode( '', $parts );

		// 3. Restaurar los bloques opacos protegidos en su estado original exacto.
		if ( ! empty( $bloques_protegidos ) ) {
			$html_reconstruido = strtr( $html_reconstruido, $bloques_protegidos );
		}

		return $html_reconstruido;
	}

	/**
	 * Protege bloques no traducibles (script, style, svg, textarea, comentarios) reemplazándolos con marcadores seguros.
	 *
	 * @param string                $html               HTML de entrada.
	 * @param array<string, string> $bloques_protegidos Array por referencia para almacenar los bloques extraídos.
	 * @return string HTML con marcadores de protección.
	 */
	private function proteger_bloques_opacos( string $html, array &$bloques_protegidos ): string {
		$prefijo_marcador = '<!--###TFML_OPAQUE_' . bin2hex( random_bytes( 4 ) ) . '_';
		$indice           = 0;

		$patrones = array(
			'/<!--.*?-->/s',
			'/<script\b[^>]*>.*?<\/script>/is',
			'/<style\b[^>]*>.*?<\/style>/is',
			'/<svg\b[^>]*>.*?<\/svg>/is',
			'/<textarea\b[^>]*>.*?<\/textarea>/is',
		);

		foreach ( $patrones as $patron ) {
			$html = (string) preg_replace_callback(
				$patron,
				static function ( array $coincidencia ) use ( &$bloques_protegidos, $prefijo_marcador, &$indice ): string {
					$marcador                        = $prefijo_marcador . ( ++$indice ) . '###-->';
					$bloques_protegidos[ $marcador ] = $coincidencia[0];
					return $marcador;
				},
				$html
			);
		}

		return $html;
	}

	/**
	 * Localiza exclusivamente los atributos permitidos (alt, title, aria-label) en una etiqueta HTML.
	 *
	 * @param string                $tag          Etiqueta HTML completa (ej. <img alt="Texto" src="url">).
	 * @param array<string, string> $pairs        Pares de traducción.
	 * @param string                $attr_pattern Patrón regex compilado de atributos permitidos.
	 * @return string Etiqueta con atributos localizados si correspondía.
	 */
	private function localizar_atributos_etiqueta( string $tag, array $pairs, string $attr_pattern ): string {
		if ( ! preg_match( $attr_pattern, $tag ) ) {
			return $tag;
		}

		return (string) preg_replace_callback(
			$attr_pattern,
			static function ( array $coincidencia ) use ( $pairs ): string {
				$nombre_attr = $coincidencia[1];
				$comilla     = $coincidencia[2] ?? '';
				$valor_attr  = ( isset( $coincidencia[3] ) && '' !== $coincidencia[3] )
					? $coincidencia[3]
					: ( $coincidencia[4] ?? '' );

				if ( isset( $pairs[ $valor_attr ] ) ) {
					$valor_attr = $pairs[ $valor_attr ];
				} else {
					$valor_attr = strtr( $valor_attr, $pairs );
				}

				return $nombre_attr . '=' . $comilla . $valor_attr . $comilla;
			},
			$tag
		);
	}
}
