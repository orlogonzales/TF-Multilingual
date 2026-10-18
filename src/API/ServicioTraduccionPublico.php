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
use TF\Multilingual\Migration\RetainedTranslationRegistry;
use TF\Multilingual\Routing\CurrentLanguageResolver;
use TF\Multilingual\Routing\LocalizedUrlGenerator;
use TF\Multilingual\Routing\ResolvedorPortadaMultilingue;
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
	 * Generador de URLs localizadas (opcional).
	 *
	 * @var LocalizedUrlGenerator|null
	 */
	private ?LocalizedUrlGenerator $generador_urls;

	/**
	 * Resolvedor de portada multilingüe (opcional).
	 *
	 * @var ResolvedorPortadaMultilingue|null
	 */
	private ?ResolvedorPortadaMultilingue $resolvedor_portada;

	/**
	 * Registro de traducciones retenidas (opcional).
	 *
	 * @var RetainedTranslationRegistry|null
	 */
	private ?RetainedTranslationRegistry $registro_retenidas;

	/**
	 * Tipo de elemento de contexto explícito opcional para evaluación.
	 *
	 * @var string|null
	 */
	private ?string $tipo_contexto_explicito = null;

	/**
	 * ID de elemento de contexto explícito opcional para evaluación.
	 *
	 * @var int|null
	 */
	private ?int $id_contexto_explicito = null;

	/**
	 * Constructor.
	 *
	 * @param LanguageRegistry                  $registro_idiomas         Registro de idiomas.
	 * @param CurrentLanguageResolver           $resolvedor_idioma_actual Resolvedor de idioma actual.
	 * @param ContentTranslationResolver        $resolvedor_traducciones  Resolvedor de traducciones.
	 * @param RewriteManager|null               $gestor_reescritura       Gestor de reescritura opcional.
	 * @param LocalizedUrlGenerator|null        $generador_urls           Generador de URLs localizadas opcional.
	 * @param ResolvedorPortadaMultilingue|null $resolvedor_portada       Resolvedor de portada opcional.
	 * @param RetainedTranslationRegistry|null  $registro_retenidas       Registro de traducciones retenidas opcional.
	 */
	public function __construct(
		LanguageRegistry $registro_idiomas,
		CurrentLanguageResolver $resolvedor_idioma_actual,
		ContentTranslationResolver $resolvedor_traducciones,
		?RewriteManager $gestor_reescritura = null,
		?LocalizedUrlGenerator $generador_urls = null,
		?ResolvedorPortadaMultilingue $resolvedor_portada = null,
		?RetainedTranslationRegistry $registro_retenidas = null
	) {
		$this->registro_idiomas         = $registro_idiomas;
		$this->resolvedor_idioma_actual = $resolvedor_idioma_actual;
		$this->resolvedor_traducciones  = $resolvedor_traducciones;
		$this->gestor_reescritura       = $gestor_reescritura;
		$this->generador_urls           = $generador_urls;
		$this->resolvedor_portada       = $resolvedor_portada;
		$this->registro_retenidas       = $registro_retenidas;
	}

	/**
	 * Permite fijar o limpiar un contexto de objeto explícito para evaluar disponibilidad de traducciones.
	 *
	 * @param string|null $tipo_elemento Tipo de elemento ('post', 'page', 'term', etc.) o null para limpiar.
	 * @param int|null    $id_elemento   ID del elemento o null para limpiar.
	 * @return void
	 */
	public function establecer_contexto_evaluacion( ?string $tipo_elemento, ?int $id_elemento ): void {
		$this->tipo_contexto_explicito = $tipo_elemento;
		$this->id_contexto_explicito   = $id_elemento;
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

		$registro_retenidas = $this->obtener_registro_retenidas();
		if ( $registro_retenidas->has_retained_element( $tipo_normalizado, $id_elemento ) ) {
			return null;
		}

		try {
			$idioma_origen = $this->resolvedor_traducciones->language_of( $tipo_normalizado, $id_elemento );
			if ( $destino === $idioma_origen ) {
				return $id_elemento;
			}

			$id_resuelto = $this->resolvedor_traducciones->resolve_element_id( $tipo_normalizado, $id_elemento, $destino );
			if ( null !== $id_resuelto && $id_resuelto > 0 ) {
				if ( $registro_retenidas->has_retained_element( $tipo_normalizado, $id_resuelto ) ) {
					return null;
				}
				return $id_resuelto;
			}

			return null;
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

		if ( $this->obtener_registro_retenidas()->has_retained_element( $tipo_normalizado, $id_elemento ) ) {
			return null;
		}

		try {
			return $this->resolvedor_traducciones->language_of( $tipo_normalizado, $id_elemento );
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Retorna la información completa de idiomas activos para selectores o navegación,
	 * distinguiendo entre un idioma activo en LanguageRegistry y la disponibilidad real
	 * de una traducción publicada y no retenida en el contexto actual (DT-TFML-066).
	 *
	 * @param string|null $tipo_contexto Tipo de elemento opcional ('post', 'page', 'term', 'front_page').
	 * @param int|null    $id_contexto   ID del elemento de contexto opcional.
	 * @return array<string, array<string, mixed>> Información detallada indexada por código.
	 */
	public function obtener_informacion_idiomas( ?string $tipo_contexto = null, ?int $id_contexto = null ): array {
		$idioma_actual = $this->obtener_idioma_actual() ?? $this->obtener_idioma_por_defecto() ?? 'es';
		$contexto      = $this->resolver_contexto_evaluacion( $tipo_contexto, $id_contexto );
		$resultado     = array();

		foreach ( $this->registro_idiomas->active() as $idioma ) {
			$codigo = $idioma->get_code();
			if ( ! $this->es_idioma_activo( $codigo ) ) {
				continue;
			}

			$evaluacion = $this->evaluar_disponibilidad_en_contexto( $codigo, $contexto );

			$resultado[ $codigo ] = array(
				'codigo'           => $codigo,
				'locale'           => $idioma->get_locale(),
				'nombre_nativo'    => $idioma->get_native_name(),
				'nombre_traducido' => $idioma->get_name(),
				'activo'           => ( $codigo === $idioma_actual ),
				'disponible'       => $evaluacion['disponible'],
				'missing'          => $evaluacion['missing'],
				'id_traducido'     => $evaluacion['id_traducido'],
				'url'              => $evaluacion['url'],
				'orden'            => $idioma->get_order(),
			);
		}

		return $resultado;
	}

	/**
	 * Determina el contexto de objeto actual para evaluar disponibilidad real de traducciones.
	 *
	 * @param string|null $tipo_contexto Tipo de contexto opcional.
	 * @param int|null    $id_contexto   ID de contexto opcional.
	 * @return array{tipo: string|null, subtipo: string, id: int|null, es_portada: bool}
	 */
	private function resolver_contexto_evaluacion( ?string $tipo_contexto = null, ?int $id_contexto = null ): array {
		$tipo_efectivo = $tipo_contexto ?? $this->tipo_contexto_explicito;
		$id_efectivo   = $id_contexto ?? $this->id_contexto_explicito;

		if ( null !== $id_efectivo && $id_efectivo > 0 ) {
			$es_portada       = ( 'front_page' === $tipo_efectivo ) || $this->es_id_de_portada_estatica( $id_efectivo );
			$tipo_normalizado = ( 'front_page' === $tipo_efectivo )
				? 'post'
				: $this->normalizar_tipo_elemento( (string) ( $tipo_efectivo ?? 'post' ) );

			return array(
				'tipo'       => $tipo_normalizado,
				'subtipo'    => (string) ( $tipo_efectivo ?? $tipo_normalizado ),
				'id'         => $id_efectivo,
				'es_portada' => $es_portada,
			);
		}

		if ( function_exists( 'get_queried_object' ) ) {
			$objeto = get_queried_object();
			if ( $objeto instanceof \WP_Post && (int) $objeto->ID > 0 ) {
				$post_id    = (int) $objeto->ID;
				$es_portada = $this->es_id_de_portada_estatica( $post_id );

				return array(
					'tipo'       => 'post',
					'subtipo'    => (string) ( $objeto->post_type ?? 'post' ),
					'id'         => $post_id,
					'es_portada' => $es_portada,
				);
			}

			if ( $objeto instanceof \WP_Term && (int) $objeto->term_id > 0 ) {
				return array(
					'tipo'       => 'term',
					'subtipo'    => (string) ( $objeto->taxonomy ?? 'category' ),
					'id'         => (int) $objeto->term_id,
					'es_portada' => false,
				);
			}
		}

		if ( $this->es_portada_estatica_configurada() && ! $this->es_consulta_no_portada() ) {
			$id_portada_base = $this->obtener_id_portada_base();
			if ( $id_portada_base > 0 ) {
				return array(
					'tipo'       => 'post',
					'subtipo'    => 'page',
					'id'         => $id_portada_base,
					'es_portada' => true,
				);
			}
		}

		return array(
			'tipo'       => null,
			'subtipo'    => '',
			'id'         => null,
			'es_portada' => false,
		);
	}

	/**
	 * Evalúa si existe una traducción real, publicada y no retenida para el idioma dado en el contexto actual.
	 *
	 * @param string                                                                    $codigo   Código de idioma activo.
	 * @param array{tipo: string|null, subtipo: string, id: int|null, es_portada: bool} $contexto Contexto resuelto.
	 * @return array{disponible: bool, missing: int, id_traducido: int|null, url: string}
	 */
	private function evaluar_disponibilidad_en_contexto( string $codigo, array $contexto ): array {
		if ( null !== $contexto['id'] && $contexto['id'] > 0 && null !== $contexto['tipo'] ) {
			$tipo_elemento = $contexto['tipo'];
			$id_origen     = $contexto['id'];
			$id_traducido  = $this->resolver_traduccion_elemento( $tipo_elemento, $id_origen, $codigo );

			if ( null === $id_traducido ) {
				$idioma_defecto = $this->obtener_idioma_por_defecto() ?? 'es';
				if ( $codigo === $idioma_defecto && null === $this->obtener_idioma_de_elemento( $tipo_elemento, $id_origen ) ) {
					$id_traducido = $id_origen;
				}
			}

			if ( null === $id_traducido || $id_traducido <= 0 ) {
				return array(
					'disponible'   => false,
					'missing'      => 1,
					'id_traducido' => null,
					'url'          => '',
				);
			}

			if ( ! $this->es_elemento_publicado_y_no_retenido( $tipo_elemento, $id_traducido ) ) {
				return array(
					'disponible'   => false,
					'missing'      => 1,
					'id_traducido' => null,
					'url'          => '',
				);
			}

			$url = $this->construir_url_para_elemento(
				$codigo,
				$tipo_elemento,
				$contexto['subtipo'],
				$id_origen,
				$id_traducido,
				$contexto['es_portada']
			);

			if ( '' === $url ) {
				return array(
					'disponible'   => false,
					'missing'      => 1,
					'id_traducido' => null,
					'url'          => '',
				);
			}

			return array(
				'disponible'   => true,
				'missing'      => 0,
				'id_traducido' => $id_traducido,
				'url'          => $url,
			);
		}

		// Sin objeto específico en contexto: si es portada estática pero no entró por rama previa,
		// o contexto genérico sin objeto consultado.
		return array(
			'disponible'   => true,
			'missing'      => 0,
			'id_traducido' => null,
			'url'          => $this->construir_url_para_idioma( $codigo ),
		);
	}

	/**
	 * Verifica que un elemento resuelto no esté retenido y, si es un post, esté publicado.
	 *
	 * @param string $tipo_normalizado 'post' o 'term'.
	 * @param int    $id_elemento      ID del elemento resuelto.
	 * @return bool True si es publicable y no retenido.
	 */
	private function es_elemento_publicado_y_no_retenido( string $tipo_normalizado, int $id_elemento ): bool {
		if ( $id_elemento <= 0 ) {
			return false;
		}

		if ( $this->obtener_registro_retenidas()->has_retained_element( $tipo_normalizado, $id_elemento ) ) {
			return false;
		}

		if ( 'post' === $tipo_normalizado && function_exists( 'get_post_status' ) ) {
			$status = get_post_status( $id_elemento );
			if ( 'publish' !== $status ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Construye la URL localizada real para un elemento traducido verificado.
	 *
	 * @param string $codigo        Código de idioma destino.
	 * @param string $tipo_elemento 'post' o 'term'.
	 * @param string $subtipo       Subtipo o taxonomía.
	 * @param int    $id_origen     ID del elemento origen.
	 * @param int    $id_traducido  ID del elemento traducido.
	 * @param bool   $es_portada    Si corresponde a la portada estática.
	 * @return string URL canónica localizada o cadena vacía si no es resoluble.
	 */
	private function construir_url_para_elemento(
		string $codigo,
		string $tipo_elemento,
		string $subtipo,
		int $id_origen,
		int $id_traducido,
		bool $es_portada
	): string {
		if ( $es_portada ) {
			if ( null !== $this->generador_urls ) {
				try {
					return $this->generador_urls->home_url( $codigo );
				} catch ( \Throwable $e ) {
					return '';
				}
			}
			return $this->construir_url_para_idioma( $codigo );
		}

		if ( null !== $this->generador_urls ) {
			try {
				if ( 'post' === $tipo_elemento ) {
					$url_post = $this->generador_urls->get_post_translation_url( $id_origen, $codigo );
					if ( is_string( $url_post ) && '' !== $url_post ) {
						return $url_post;
					}
				} elseif ( 'term' === $tipo_elemento ) {
					$tax      = ( 'term' !== $subtipo && '' !== $subtipo ) ? $subtipo : '';
					$url_term = $this->generador_urls->get_term_translation_url( $id_origen, $codigo, $tax );
					if ( is_string( $url_term ) && '' !== $url_term ) {
						return $url_term;
					}
				}
			} catch ( \Throwable $e ) {
				return '';
			}
		}

		if ( 'post' === $tipo_elemento && function_exists( 'get_permalink' ) ) {
			$permalink = get_permalink( $id_traducido );
			if ( is_string( $permalink ) && '' !== $permalink ) {
				return $this->localizar_url_basica( $permalink, $codigo );
			}
		}

		if ( 'term' === $tipo_elemento && function_exists( 'get_term_link' ) ) {
			$tax       = ( 'term' !== $subtipo && '' !== $subtipo ) ? $subtipo : '';
			$term_link = get_term_link( $id_traducido, $tax );
			if ( is_string( $term_link ) && '' !== $term_link ) {
				return $this->localizar_url_basica( $term_link, $codigo );
			}
		}

		return $this->construir_url_para_idioma( $codigo );
	}

	/**
	 * Localiza una URL básica cuando no se inyectó LocalizedUrlGenerator (ej. pruebas unitarias aisladas).
	 *
	 * @param string $url    URL base.
	 * @param string $codigo Código de idioma destino.
	 * @return string URL localizada.
	 */
	private function localizar_url_basica( string $url, string $codigo ): string {
		$default = $this->obtener_idioma_por_defecto() ?? 'es';
		if ( $codigo === $default ) {
			return $url;
		}

		$home_url = function_exists( 'home_url' ) ? rtrim( (string) home_url( '/' ), '/' ) : '';
		if ( '' !== $home_url && str_starts_with( $url, $home_url . '/' ) ) {
			$rel = ltrim( substr( $url, strlen( $home_url ) ), '/' );
			if ( $rel === $codigo || str_starts_with( $rel, $codigo . '/' ) ) {
				return $url;
			}
			return $home_url . '/' . $codigo . '/' . $rel;
		}

		return $url;
	}

	/**
	 * Determina si la portada estática está configurada en WordPress.
	 *
	 * @return bool
	 */
	private function es_portada_estatica_configurada(): bool {
		if ( null !== $this->resolvedor_portada ) {
			return $this->resolvedor_portada->es_modo_portada_estatica();
		}

		if ( ! function_exists( 'get_option' ) ) {
			return false;
		}

		return 'page' === (string) get_option( 'show_on_front', 'posts' )
			&& (int) get_option( 'page_on_front', 0 ) > 0;
	}

	/**
	 * Obtiene el ID de la página configurada como portada base.
	 *
	 * @return int
	 */
	private function obtener_id_portada_base(): int {
		if ( null !== $this->resolvedor_portada ) {
			return $this->resolvedor_portada->obtener_id_portada_base();
		}

		return function_exists( 'get_option' ) ? (int) get_option( 'page_on_front', 0 ) : 0;
	}

	/**
	 * Determina si un ID de post corresponde a la portada estática base o alguna de sus traducciones activas.
	 *
	 * @param int $post_id ID del post.
	 * @return bool
	 */
	private function es_id_de_portada_estatica( int $post_id ): bool {
		if ( $post_id <= 0 || ! $this->es_portada_estatica_configurada() ) {
			return false;
		}

		$id_base = $this->obtener_id_portada_base();
		if ( $post_id === $id_base ) {
			return true;
		}

		foreach ( $this->obtener_idiomas_activos() as $codigo ) {
			if ( null !== $this->resolvedor_portada && $this->resolvedor_portada->es_portada_de_idioma( $post_id, $codigo ) ) {
				return true;
			}

			$id_resuelto = $this->resolver_traduccion_elemento( 'post', $id_base, $codigo );
			if ( null !== $id_resuelto && $id_resuelto === $post_id ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Determina si la consulta actual de WordPress es explícitamente una vista de archivo, búsqueda o singular distinta de portada.
	 *
	 * @return bool
	 */
	private function es_consulta_no_portada(): bool {
		if ( function_exists( 'is_search' ) && is_search() ) {
			return true;
		}
		if ( function_exists( 'is_archive' ) && is_archive() ) {
			return true;
		}
		return false;
	}

	/**
	 * Obtiene o instancia perezosamente el registro de traducciones retenidas.
	 *
	 * @return RetainedTranslationRegistry
	 */
	private function obtener_registro_retenidas(): RetainedTranslationRegistry {
		if ( null === $this->registro_retenidas ) {
			$this->registro_retenidas = new RetainedTranslationRegistry();
		}

		return $this->registro_retenidas;
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
