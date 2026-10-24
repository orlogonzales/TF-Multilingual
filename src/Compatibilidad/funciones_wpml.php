<?php
/**
 * Funciones globales de compatibilidad heredada con WPML.
 *
 * @package TF\Multilingual\Compatibilidad
 */

declare( strict_types=1 );

use TF\Multilingual\Compatibilidad\AdaptadorCompatibilidadWpml;

if ( ! defined( 'TFML_WPML_FUNCTIONS_LOADED' ) ) {
	define( 'TFML_WPML_FUNCTIONS_LOADED', true );
}

if ( ! function_exists( 'icl_object_id' ) ) {
	/**
	 * Emulación acotada y segura de la función clásica icl_object_id().
	 *
	 * Contrato auditado de ecoCMS:
	 * - $post_id_header = icl_object_id($post_id_header, 'ova_framework_hf_el', false);
	 * - $post_id_footer = icl_object_id($post_id_footer, 'ova_framework_hf_el', false);
	 *
	 * @param mixed       $id                          Identificador del elemento.
	 * @param string      $type                        Tipo o taxonomía (ej. 'post', 'ova_framework_hf_el', 'category').
	 * @param bool        $return_original_if_missing  Si retorna el original si falta la traducción.
	 * @param string|null $lang                        Código de idioma destino solicitado.
	 * @return int|null Identificador traducido, original (si se pidió), o null.
	 */
	function icl_object_id( $id, $type = 'post', $return_original_if_missing = false, $lang = null ) {
		$adaptador = AdaptadorCompatibilidadWpml::obtener_instancia_activa();

		if ( null !== $adaptador && $adaptador->esta_habilitado() ) {
			return $adaptador->ejecutar_icl_object_id(
				$id,
				(string) $type,
				(bool) $return_original_if_missing,
				null !== $lang ? (string) $lang : null
			);
		}

		return $return_original_if_missing ? ( is_numeric( $id ) ? (int) $id : null ) : null;
	}
}

if ( ! function_exists( 'icl_get_languages' ) ) {
	/**
	 * Emulación acotada y segura de la función clásica icl_get_languages().
	 *
	 * Contrato auditado de ecoCMS:
	 * - $languages = icl_get_languages( 'skip_missing=0&orderby=custom' );
	 *
	 * @param string|array<string, mixed> $args Argumentos de filtrado y ordenación.
	 * @return array<string, array<string, mixed>> Lista estructurada de idiomas activos.
	 */
	function icl_get_languages( $args = '' ) {
		$adaptador = AdaptadorCompatibilidadWpml::obtener_instancia_activa();

		if ( null !== $adaptador && $adaptador->esta_habilitado() ) {
			return $adaptador->ejecutar_icl_get_languages( $args );
		}

		return array();
	}
}

if ( ! function_exists( 'icl_t' ) ) {
	/**
	 * Emulación acotada y segura de la función clásica icl_t().
	 *
	 * @param string $context Contexto o dominio de la cadena.
	 * @param string $name    Nombre o clave de la cadena.
	 * @param string $value   Valor original o por defecto.
	 * @return string Cadena traducida o valor original.
	 */
	function icl_t( $context, $name, $value ) {
		$adaptador = AdaptadorCompatibilidadWpml::obtener_instancia_activa();

		if ( null !== $adaptador && $adaptador->esta_habilitado() ) {
			return $adaptador->ejecutar_icl_t( (string) $context, (string) $name, (string) $value );
		}

		return (string) $value;
	}
}
