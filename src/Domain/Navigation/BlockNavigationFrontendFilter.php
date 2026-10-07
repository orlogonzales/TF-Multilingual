<?php
/**
 * Block Navigation Frontend Filter (Gutenberg / FSE).
 *
 * @package TF\Multilingual\Domain\Navigation
 */

declare( strict_types=1 );

namespace TF\Multilingual\Domain\Navigation;

use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Domain\Translation\ContentTranslationResolver;
use TF\Multilingual\Routing\CurrentLanguageResolver;
use WP_Block;

/**
 * Class BlockNavigationFrontendFilter
 *
 * Intercepts Gutenberg / FSE core/navigation blocks during frontend rendering:
 * - Dynamically swaps the 'ref' attribute pointing to a wp_navigation post to its translated counterpart.
 * - Ensures rendering is completely ephemeral: no database mutations or block content alterations.
 */
class BlockNavigationFrontendFilter {

	/**
	 * Current language resolver.
	 *
	 * @var CurrentLanguageResolver
	 */
	private CurrentLanguageResolver $current_language_resolver;

	/**
	 * Language registry.
	 *
	 * @var LanguageRegistry
	 */
	private LanguageRegistry $language_registry;

	/**
	 * Content translation resolver.
	 *
	 * @var ContentTranslationResolver
	 */
	private ContentTranslationResolver $translation_resolver;

	/**
	 * Constructor.
	 *
	 * @param CurrentLanguageResolver    $current_language_resolver Current language resolver.
	 * @param LanguageRegistry           $language_registry         Language registry.
	 * @param ContentTranslationResolver $translation_resolver      Content translation resolver.
	 */
	public function __construct(
		CurrentLanguageResolver $current_language_resolver,
		LanguageRegistry $language_registry,
		ContentTranslationResolver $translation_resolver
	) {
		$this->current_language_resolver = $current_language_resolver;
		$this->language_registry         = $language_registry;
		$this->translation_resolver      = $translation_resolver;
	}

	/**
	 * Registers WordPress filter hook.
	 *
	 * @return void
	 */
	public function init_hooks(): void {
		add_filter( 'render_block_data', array( $this, 'filter_render_block_data' ), 20, 3 );
	}

	/**
	 * Filters block data before rendering to translate core/navigation 'ref' attribute.
	 *
	 * @param array<string, mixed> $parsed_block The parsed block data.
	 * @param array<string, mixed> $source_block The original block data.
	 * @param WP_Block|null        $parent_block The parent block instance.
	 * @return array<string, mixed> Filtered parsed block data.
	 */
	public function filter_render_block_data( array $parsed_block, array $source_block, ?WP_Block $parent_block = null ): array {
		if ( 'core/navigation' !== ( $parsed_block['blockName'] ?? '' ) ) {
			return $parsed_block;
		}

		if ( ! $this->language_registry->is_configured() ) {
			return $parsed_block;
		}

		$default_lang = $this->language_registry->get_default_code();
		$current_lang = $this->current_language_resolver->get_current_language() ?? $default_lang;

		if ( $current_lang === $default_lang ) {
			return $parsed_block;
		}

		$ref = $parsed_block['attrs']['ref'] ?? null;
		if ( empty( $ref ) || ! is_numeric( $ref ) ) {
			return $parsed_block;
		}

		$ref_id = (int) $ref;
		if ( $ref_id <= 0 ) {
			return $parsed_block;
		}

		$translated = $this->translation_resolver->resolve( 'post', $ref_id, $current_lang );
		if ( null !== $translated && $translated->get_element_id() > 0 ) {
			$parsed_block['attrs']['ref'] = $translated->get_element_id();
		}

		return $parsed_block;
	}
}
