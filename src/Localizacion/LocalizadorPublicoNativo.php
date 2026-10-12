<?php
/**
 * Localizador Público Nativo de WordPress.
 *
 * @package TF\Multilingual\Localizacion
 */

declare( strict_types=1 );

namespace TF\Multilingual\Localizacion;

use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Routing\CurrentLanguageResolver;

/**
 * Clase LocalizadorPublicoNativo
 *
 * Gestiona la sincronización soberana y no invasiva del locale público de WordPress
 * con el idioma efectivo resuelto por TF Multilingual.
 *
 * Responsabilidades:
 * - Determinar el idioma público efectivo de la petición mediante CurrentLanguageResolver.
 * - Obtener el código regional canónico (locale, ej. es_ES, en_US) desde LanguageRegistry.
 * - Interceptar limpiamente los filtros nativos 'locale' y 'determine_locale'.
 * - Preservar inalterado el locale administrativo en WP-Admin y preferencias de usuario.
 * - Respetar contextos técnicos (CLI, cron, AJAX administrativo y REST).
 * - Cero mutación de la opción persistida 'WPLANG' en base de datos.
 * - Desacoplamiento total respecto de temas o constantes externas heredadas.
 * - Protección estricta contra llamadas recursivas o efectos secundarios.
 */
class LocalizadorPublicoNativo {

	/**
	 * Registro oficial de idiomas de TF Multilingual.
	 *
	 * @var LanguageRegistry
	 */
	private LanguageRegistry $registro_idiomas;

	/**
	 * Resolutor del idioma activo de la solicitud actual.
	 *
	 * @var CurrentLanguageResolver
	 */
	private CurrentLanguageResolver $resolutor_idioma_actual;

	/**
	 * Semáforo antirrecusión para prevenir bucles en filtros de locale.
	 *
	 * @var bool
	 */
	private bool $en_resolucion = false;

	/**
	 * Indicador para permitir la resolución activa durante pruebas unitarias bajo CLI.
	 *
	 * @var bool
	 */
	private bool $permitir_en_cli = false;

	/**
	 * Constructor del localizador.
	 *
	 * @param LanguageRegistry        $registro_idiomas         Registro oficial de idiomas.
	 * @param CurrentLanguageResolver $resolutor_idioma_actual Resolutor de idioma actual.
	 */
	public function __construct(
		LanguageRegistry $registro_idiomas,
		CurrentLanguageResolver $resolutor_idioma_actual
	) {
		$this->registro_idiomas        = $registro_idiomas;
		$this->resolutor_idioma_actual = $resolutor_idioma_actual;
	}

	/**
	 * Registra los ganchos nativos de localización en WordPress.
	 *
	 * @return void
	 */
	public function registrar_ganchos(): void {
		add_filter( 'locale', array( $this, 'filtrar_locale' ), 10, 1 );
		add_filter( 'determine_locale', array( $this, 'filtrar_determine_locale' ), 10, 1 );
	}

	/**
	 * Desregistra los ganchos de localización (útil en pruebas y teardown).
	 *
	 * @return void
	 */
	public function desregistrar_ganchos(): void {
		remove_filter( 'locale', array( $this, 'filtrar_locale' ), 10 );
		remove_filter( 'determine_locale', array( $this, 'filtrar_determine_locale' ), 10 );
	}

	/**
	 * Determina si el contexto actual de ejecución es público y elegible para filtrado.
	 *
	 * @return bool True si es contexto público elegible, false si debe preservarse WordPress Core.
	 */
	public function es_contexto_publico_elegible(): bool {
		// 1. Preservar estrictamente el panel de administración de WordPress.
		if ( function_exists( 'is_admin' ) && is_admin() ) {
			return false;
		}

		// 2. Preservar tareas en segundo plano del cron de WordPress.
		if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
			return false;
		}

		// 3. Preservar peticiones AJAX originadas en el entorno administrativo.
		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			if ( function_exists( 'is_admin' ) && is_admin() ) {
				return false;
			}
		}

		// 4. Preservar ejecuciones por línea de comandos salvo que se active explícitamente en pruebas.
		if ( defined( 'WP_CLI' ) && WP_CLI && ! $this->permitir_en_cli ) {
			return false;
		}

		return true;
	}

	/**
	 * Resuelve el locale regional correspondiente al idioma público efectivo.
	 *
	 * @return string|null Código de locale (ej. 'es_ES', 'en_US') o null si no aplica.
	 */
	public function obtener_locale_publico_efectivo(): ?string {
		if ( ! $this->es_contexto_publico_elegible() ) {
			return null;
		}

		if ( ! $this->registro_idiomas->is_configured() ) {
			return null;
		}

		if ( $this->en_resolucion ) {
			return null;
		}

		$this->en_resolucion = true;

		try {
			$codigo_idioma = $this->resolutor_idioma_actual->get_current_language();

			if ( null === $codigo_idioma || '' === $codigo_idioma ) {
				return null;
			}

			// Validar que el idioma esté registrado y activo en el catálogo oficial.
			if ( ! $this->registro_idiomas->is_active( $codigo_idioma ) ) {
				return null;
			}

			$entidad_idioma = $this->registro_idiomas->get( $codigo_idioma );
			if ( null === $entidad_idioma ) {
				return null;
			}

			$locale = $entidad_idioma->get_locale();
			if ( '' === $locale ) {
				return null;
			}

			return $locale;
		} finally {
			$this->en_resolucion = false;
		}
	}

	/**
	 * Filtra el hook nativo 'locale' de WordPress.
	 *
	 * @param string $locale_original Locale entregado por WordPress Core.
	 * @return string Locale efectivo.
	 */
	public function filtrar_locale( string $locale_original ): string {
		$locale_publico = $this->obtener_locale_publico_efectivo();

		if ( null !== $locale_publico ) {
			return $locale_publico;
		}

		return $locale_original;
	}

	/**
	 * Filtra el hook nativo 'determine_locale' de WordPress.
	 *
	 * @param string|null $locale_determinado Locale determinado por WordPress Core.
	 * @return string|null Locale determinado final.
	 */
	public function filtrar_determine_locale( ?string $locale_determinado ): ?string {
		$locale_publico = $this->obtener_locale_publico_efectivo();

		if ( null !== $locale_publico ) {
			return $locale_publico;
		}

		return $locale_determinado;
	}

	/**
	 * Habilita o deshabilita la resolución en entorno CLI (exclusivo para testing unitario).
	 *
	 * @param bool $habilitar Estado deseado.
	 * @return void
	 */
	public function habilitar_en_cli_para_pruebas( bool $habilitar ): void {
		$this->permitir_en_cli = $habilitar;
	}
}
