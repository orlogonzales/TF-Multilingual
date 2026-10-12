<?php
/**
 * Servicio de API Pública de TF Multilingual.
 *
 * @package TF\Multilingual\API
 */

declare( strict_types=1 );

namespace TF\Multilingual\API;

use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Domain\Translation\ContentTranslationResolver;
use TF\Multilingual\Routing\CurrentLanguageResolver;
use TF\Multilingual\Routing\RewriteManager;

/**
 * Class ServicioTraduccionPublico
 *
 * Superficie A: API pública soberana de TF Multilingual.
 * Expone operaciones de resolución de idiomas y traducciones desacopladas
 * de cualquier estándar o símbolo externo (como WPML o Polylang).
 */
class ServicioTraduccionPublico {

	/**
	 * Registro de idiomas configurados.
	 *
	 * @var LanguageRegistry
	 */
	private LanguageRegistry $registro_idiomas;

	/**
	 * Resolvedor de idioma del contexto actual.
	 *
	 * @var CurrentLanguageResolver
	 */
	private CurrentLanguageResolver $resolvedor_idioma_actual;

	/**
	 * Resolvedor de traducciones de contenido.
	 *
	 * @var ContentTranslationResolver
	 */
	private ContentTranslationResolver $resolvedor_traducciones;

	/**
	 * Gestor de reescritura y enrutamiento (opcional).
	 *
	 * @var RewriteManager|null
	 */
	private ?RewriteManager $gestor_reescritura;

	/**
	 * Constructor.
	 *
	 * @param LanguageRegistry            $registro_idiomas           Registro de idiomas.
	 * @param CurrentLanguageResolver     $resolvedor_idioma_actual   Resolvedor de idioma actual.
	 * @param ContentTranslationResolver  $resolvedor_traducciones    Resolvedor de traducciones.
	 * @param RewriteManager|null         $gestor_reescritura         Gestor de reescritura opcional.
	 */
	public function __construct(
		LanguageRegistry $registro_idiomas,
		CurrentLanguageResolver $resolvedor_idioma_actual,
		ContentTranslationResolver $resolvedor_traducciones,
		?RewriteManager $gestor_reescritura = null
	) {
		$this->registro_idiomas         = $registro_idiomas;
		$this->resolvedor_idioma_actual = $resolvedor_idioma_actual;
		$this->resolvedor_traducciones  = $resolvedor_traducciones;
		$this->gestor_reescritura       = $gestor_reescritura;
	}

	/**
	 * Retorna el código ISO del idioma actual de la petición (ej. 'es', 'en').
	 *
	 * @return string|null Código de idioma o null si no se pudo determinar.
	 */
	public function obtener_idioma_actual(): ?string {
		return $this->resolvedor_idioma_actual->get_current_language();
	}

	/**
	 * Retorna el código del idioma predeterminado configurado.
	 *
	 * @return string|null Código de idioma predeterminado o null si no está configurado.
	 */
	public function obtener_idioma_por_defecto(): ?string {
		$default = $this->registro_idiomas->get_default();
		return $default ? $default->get_code() : null;
	}

	/**
	 * Retorna la lista de códigos de los idiomas activos en el sistema.
	 *
	 * @return array<int, string> Lista de códigos de idiomas activos (ej. ['es', 'en']).
	 */
	public function obtener_idiomas_activos(): array {
		$activos = array();
		foreach ( $this->registro_idiomas->active() as $idioma ) {
			$activos[] = $idioma->get_code();
		}
		return $activos;
	}

	/**
	 * Determina si un código de idioma está registrado y activo.
	 *
	 * @param string $codigo_idioma Código a evaluar.
	 * @return bool True si está registrado y activo.
	 */
	public function es_idioma_activo( string $codigo_idioma ): bool {
		return $this->registro_idiomas->is_active( $codigo_idioma );
	}

	/**
	 * Resuelve el ID del elemento traducido hacia un idioma específico.
	 *
	 * @param string      $tipo_elemento   Tipo de elemento ('post', 'term' o subtipo específico).
	 * @param int         $id_elemento     ID numérico del elemento original.
	 * @param string|null $idioma_destino  Código de idioma destino. Si es null, usa el idioma actual.
	 * @return int|null ID traducido o null si no existe traducción o el idioma está inactivo.
	 */
	public function resolver_traduccion_elemento( string $tipo_elemento, int $id_elemento, ?string $idioma_destino = null ): ?int {
		if ( $id_elemento <= 0 ) {
			return null;
		}

		$destino = ( null !== $idioma_destino && '' !== $idioma_destino )
			? strtolower( trim( $idioma_destino ) )
			: $this->obtener_idioma_actual();

		if ( null === $destino || ! $this->es_idioma_activo( $destino ) ) {
			return null;
		}

		$tipo_normalizado = $this->normalizar_tipo_elemento( $tipo_elemento );

		try {
			$idioma_origen = $this->resolvedor_traducciones->language_of( $tipo_normalizado, $id_elemento );
			if ( $destino === $idioma_origen ) {
				return $id_elemento;
			}

			return $this->resolvedor_traducciones->resolve_element_id( $tipo_normalizado, $id_elemento, $destino );
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Obtiene el código de idioma asignado a un elemento.
	 *
	 * @param string $tipo_elemento Tipo de elemento.
	 * @param int    $id_elemento   ID del elemento.
	 * @return string|null Código de idioma o null si no tiene traducción asignada.
	 */
	public function obtener_idioma_de_elemento( string $tipo_elemento, int $id_elemento ): ?string {
		if ( $id_elemento <= 0 ) {
			return null;
		}

		$tipo_normalizado = $this->normalizar_tipo_elemento( $tipo_elemento );

		try {
			return $this->resolvedor_traducciones->language_of( $tipo_normalizado, $id_elemento );
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Retorna la información completa de idiomas activos para selectores o navegación.
	 *
	 * @return array<string, array<string, mixed>> Información detallada indexada por código.
	 */
	public function obtener_informacion_idiomas(): array {
		$idioma_actual = $this->obtener_idioma_actual() ?? $this->obtener_idioma_por_defecto() ?? 'es';
		$resultado     = array();

		foreach ( $this->registro_idiomas->active() as $idioma ) {
			$codigo = $idioma->get_code();
			$url    = $this->construir_url_para_idioma( $codigo );

			$resultado[ $codigo ] = array(
				'codigo'           => $codigo,
				'locale'           => $idioma->get_locale(),
				'nombre_nativo'    => $idioma->get_native_name(),
				'nombre_traducido' => $idioma->get_name(),
				'activo'           => ( $codigo === $idioma_actual ),
				'url'              => $url,
				'orden'            => $idioma->get_order(),
			);
		}

		return $resultado;
	}

	/**
	 * Normaliza el tipo de elemento de WordPress a los tipos primarios de TFML ('post' o 'term').
	 *
	 * @param string $tipo Tipo de elemento entrante.
	 * @return string 'post' o 'term'.
	 */
	private function normalizar_tipo_elemento( string $tipo ): string {
		$t = strtolower( trim( $tipo ) );

		if ( 'term' === $t || 'category' === $t || 'post_tag' === $t || 'tag' === $t ) {
			return 'term';
		}

		if ( function_exists( 'taxonomy_exists' ) && taxonomy_exists( $t ) ) {
			return 'term';
		}

		return 'post';
	}

	/**
	 * Construye una URL canónica para un idioma objetivo.
	 *
	 * @param string $codigo Código de idioma destino.
	 * @return string URL construida.
	 */
	private function construir_url_para_idioma( string $codigo ): string {
		$home_url = function_exists( 'home_url' ) ? home_url( '/' ) : '/';
		$default  = $this->obtener_idioma_por_defecto() ?? 'es';

		if ( $codigo === $default ) {
			return $home_url;
		}

		return rtrim( $home_url, '/' ) . '/' . $codigo . '/';
	}
}
