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
 * mediante un búfer de salida no intrusivo y de alto rendimiento.
 *
 * Características:
 * - Se activa únicamente en peticiones públicas elegibles de frontend.
 * - Cero sobrecarga en el idioma por defecto (Español): omite el búfer si el idioma es el predeterminado.
 * - Traduce cadenas compartidas de ecoCMS y Elementor registradas en StringTranslationService.
 * - Respeta estrictamente los valores administrables desde TF Multilingual -> Cadenas de texto.
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
	 * Filtra el contenido del búfer reemplazando cadenas originales por sus traducciones gestionadas.
	 *
	 * @param string $buffer Contenido HTML de la página.
	 * @return string HTML con las cadenas traducidas.
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

		return strtr( $buffer, $pairs );
	}
}
