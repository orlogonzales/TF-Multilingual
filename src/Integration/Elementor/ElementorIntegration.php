<?php
/**
 * Elementor & Elementor Pro Integration Component.
 *
 * @package TF\Multilingual\Integration\Elementor
 */

declare( strict_types=1 );

namespace TF\Multilingual\Integration\Elementor;

use TF\Multilingual\Domain\CustomField\CustomFieldPolicy;
use TF\Multilingual\Domain\CustomField\CustomFieldPolicyRegistry;
use TF\Multilingual\Domain\Media\MediaTranslationResolver;
use TF\Multilingual\Domain\Translation\ContentTranslationResolver;
use WP_Post;

/**
 * Class ElementorIntegration
 *
 * Coordinates compatibility between TF Multilingual and Elementor / Elementor Pro.
 * Strictly decoupled: operates safely whether Elementor is active or absent.
 */
class ElementorIntegration {

	/**
	 * Meta key indicating Elementor edit mode ('builder').
	 */
	public const META_EDIT_MODE = '_elementor_edit_mode';

	/**
	 * Meta key storing the serialized Elementor layout JSON tree.
	 */
	public const META_DATA = '_elementor_data';

	/**
	 * Meta key storing template type ('page', 'section', 'header', etc.).
	 */
	public const META_TEMPLATE_TYPE = '_elementor_template_type';

	/**
	 * Meta key storing page/document-level settings (custom CSS, layout, etc.).
	 */
	public const META_PAGE_SETTINGS = '_elementor_page_settings';

	/**
	 * Meta key storing Elementor Core version.
	 */
	public const META_VERSION = '_elementor_version';

	/**
	 * Meta key storing Elementor Pro version.
	 */
	public const META_PRO_VERSION = '_elementor_pro_version';

	/**
	 * Meta key storing page template selection.
	 */
	public const META_PAGE_TEMPLATE = '_wp_page_template';

	/**
	 * Meta key storing generated CSS metadata.
	 */
	public const META_CSS = '_elementor_css';

	/**
	 * Custom field policy registry.
	 *
	 * @var CustomFieldPolicyRegistry
	 */
	private CustomFieldPolicyRegistry $policy_registry;

	/**
	 * Media translation resolver.
	 *
	 * @var MediaTranslationResolver|null
	 */
	private ?MediaTranslationResolver $media_resolver;

	/**
	 * Content translation resolver.
	 *
	 * @var ContentTranslationResolver|null
	 */
	private ?ContentTranslationResolver $content_resolver;

	/**
	 * Constructor.
	 *
	 * @param CustomFieldPolicyRegistry         $policy_registry  Policy registry.
	 * @param MediaTranslationResolver|null    $media_resolver   Optional media resolver.
	 * @param ContentTranslationResolver|null  $content_resolver Optional content translation resolver.
	 */
	public function __construct(
		CustomFieldPolicyRegistry $policy_registry,
		?MediaTranslationResolver $media_resolver = null,
		?ContentTranslationResolver $content_resolver = null
	) {
		$this->policy_registry  = $policy_registry;
		$this->media_resolver   = $media_resolver;
		$this->content_resolver = $content_resolver;
	}

	/**
	 * Registers WordPress action and filter hooks for Elementor compatibility.
	 *
	 * @return void
	 */
	public function init_hooks(): void {
		$this->register_field_policies();

		if ( function_exists( 'add_filter' ) ) {
			add_filter(
				'tfml_initial_translation_post_content',
				array( $this, 'filter_initial_translation_post_content' ),
				10,
				3
			);
		}

		if ( function_exists( 'add_action' ) ) {
			add_action(
				'tfml_post_translation_created',
				array( $this, 'on_post_translation_created' ),
				10,
				3
			);
		}
	}

	/**
	 * Removes WordPress action and filter hooks.
	 *
	 * @return void
	 */
	public function remove_hooks(): void {
		if ( function_exists( 'remove_filter' ) ) {
			remove_filter(
				'tfml_initial_translation_post_content',
				array( $this, 'filter_initial_translation_post_content' ),
				10
			);
		}

		if ( function_exists( 'remove_action' ) ) {
			remove_action(
				'tfml_post_translation_created',
				array( $this, 'on_post_translation_created' ),
				10
			);
		}
	}

	/**
	 * Registers default custom field policies for Elementor metadata in the sovereign registry.
	 *
	 * Defaults:
	 * - _elementor_edit_mode => SHARE (so translations retain builder mode)
	 * - _elementor_template_type => SHARE (preserves document type, e.g. 'page' or 'section')
	 * - _elementor_version => SHARE
	 * - _elementor_pro_version => SHARE
	 * - _wp_page_template => SHARE (preserves template choice like 'elementor_header_footer')
	 * - _elementor_data => TRANSLATE (strictly independent per-language element tree)
	 * - _elementor_page_settings => TRANSLATE (strictly independent per-language page settings/CSS)
	 * - _elementor_css => IGNORE (regenerated dynamically by Elementor per post ID)
	 *
	 * Does not overwrite existing administrator-configured policies.
	 *
	 * @return void
	 */
	public function register_field_policies(): void {
		$defaults = array(
			self::META_EDIT_MODE     => CustomFieldPolicy::SHARE,
			self::META_TEMPLATE_TYPE => CustomFieldPolicy::SHARE,
			self::META_VERSION       => CustomFieldPolicy::SHARE,
			self::META_PRO_VERSION   => CustomFieldPolicy::SHARE,
			self::META_PAGE_TEMPLATE => CustomFieldPolicy::SHARE,
			self::META_DATA          => CustomFieldPolicy::TRANSLATE,
			self::META_PAGE_SETTINGS => CustomFieldPolicy::TRANSLATE,
			self::META_CSS           => CustomFieldPolicy::IGNORE,
		);

		foreach ( $defaults as $meta_key => $policy ) {
			if ( ! $this->policy_registry->has_policy( $meta_key ) ) {
				$this->policy_registry->set_policy( $meta_key, $policy );
			}
		}
	}

	/**
	 * Checks whether Elementor Core is active in the environment.
	 *
	 * @return bool True if Elementor is active.
	 */
	public function is_elementor_active(): bool {
		return defined( 'ELEMENTOR_VERSION' ) || class_exists( '\Elementor\Plugin' );
	}

	/**
	 * Checks whether Elementor Pro is active in the environment.
	 *
	 * @return bool True if Elementor Pro is active.
	 */
	public function is_elementor_pro_active(): bool {
		return defined( 'ELEMENTOR_PRO_VERSION' ) || class_exists( '\ElementorPro\Plugin' );
	}

	/**
	 * Checks whether a given post is managed by Elementor.
	 *
	 * @param int $post_id Post ID.
	 * @return bool True if post is built or edited with Elementor.
	 */
	public function is_elementor_post( int $post_id ): bool {
		if ( $post_id <= 0 ) {
			return false;
		}

		if ( function_exists( 'get_post_meta' ) ) {
			$edit_mode = get_post_meta( $post_id, self::META_EDIT_MODE, true );
			if ( 'builder' === $edit_mode ) {
				return true;
			}

			$data = get_post_meta( $post_id, self::META_DATA, true );
			if ( is_string( $data ) && '' !== trim( $data ) && '[]' !== trim( $data ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Retrieves and decodes the Elementor element tree for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array<int, array<string, mixed>> Decoded element tree, or empty array on failure.
	 */
	public function get_elementor_data( int $post_id ): array {
		if ( $post_id <= 0 || ! function_exists( 'get_post_meta' ) ) {
			return array();
		}

		$raw = get_post_meta( $post_id, self::META_DATA, true );
		if ( is_array( $raw ) ) {
			return $raw;
		}

		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return array();
		}

		$decoded = json_decode( $raw, true );

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Recursively extracts all attachment IDs referenced in an Elementor element tree.
	 *
	 * Safely inspects media controls and repeater arrays without string regex on serialized JSON.
	 *
	 * @param array<int, array<string, mixed>> $elements Element tree.
	 * @return array<int> Unique list of numeric attachment IDs.
	 */
	public function extract_media_ids( array $elements ): array {
		$ids = array();

		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			if ( ! empty( $element['settings'] ) && is_array( $element['settings'] ) ) {
				$this->extract_media_ids_from_settings( $element['settings'], $ids );
			}

			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$child_ids = $this->extract_media_ids( $element['elements'] );
				foreach ( $child_ids as $cid ) {
					$ids[] = $cid;
				}
			}
		}

		return array_values( array_unique( array_filter( $ids, fn( $id ) => $id > 0 ) ) );
	}

	/**
	 * Extracts media IDs from a settings array (supporting nested repeaters and galleries).
	 *
	 * @param array<string, mixed> $settings Settings array.
	 * @param array<int>           $ids      Accumulator array passed by reference.
	 * @return void
	 */
	private function extract_media_ids_from_settings( array $settings, array &$ids ): void {
		foreach ( $settings as $value ) {
			if ( ! is_array( $value ) ) {
				continue;
			}

			// Single media control: ['id' => 123, 'url' => '...'].
			if ( isset( $value['id'] ) && is_numeric( $value['id'] ) && isset( $value['url'] ) ) {
				$int_id = (int) $value['id'];
				if ( $int_id > 0 ) {
					$ids[] = $int_id;
				}
				continue;
			}

			// Gallery or repeater collection: numerically indexed array.
			if ( isset( $value[0] ) && is_array( $value[0] ) ) {
				foreach ( $value as $item ) {
					if ( is_array( $item ) ) {
						// Single gallery item: ['id' => 123, 'url' => '...'].
						if ( isset( $item['id'] ) && is_numeric( $item['id'] ) ) {
							$int_id = (int) $item['id'];
							if ( $int_id > 0 ) {
								$ids[] = $int_id;
							}
						} else {
							// Repeater item containing nested controls.
							$this->extract_media_ids_from_settings( $item, $ids );
						}
					}
				}
			}
		}
	}

	/**
	 * Resolves the localized attachment ID for a target language.
	 *
	 * @param int    $attachment_id   Source attachment ID.
	 * @param string $target_language Target language code.
	 * @return int Localized attachment ID, or original if unchanged.
	 */
	public function localize_media_id( int $attachment_id, string $target_language ): int {
		if ( $attachment_id <= 0 ) {
			return $attachment_id;
		}

		$localized_id = $attachment_id;
		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * Filters the localized attachment ID for Elementor media replacement.
			 *
			 * @param int    $attachment_id   Original attachment ID.
			 * @param string $target_language Target language code.
			 */
			$mapped = (int) apply_filters( 'tfml_localize_attachment_id', $attachment_id, $target_language );
			if ( $mapped > 0 ) {
				$localized_id = $mapped;
			}
		}

		return $localized_id;
	}

	/**
	 * Localizes an internal URL if it references a post that has a translation in the target language.
	 *
	 * @param string $url             Source URL.
	 * @param string $target_language Target language code.
	 * @return string Localized URL or original URL.
	 */
	public function localize_link_url( string $url, string $target_language ): string {
		$trimmed = trim( $url );
		if ( '' === $trimmed || str_starts_with( $trimmed, '#' ) || str_starts_with( $trimmed, 'mailto:' ) || str_starts_with( $trimmed, 'tel:' ) ) {
			return $url;
		}

		// Allow custom hook to override/localize URL.
		if ( function_exists( 'apply_filters' ) ) {
			$filtered = (string) apply_filters( 'tfml_elementor_localize_url', $trimmed, $target_language );
			if ( $filtered !== $trimmed ) {
				return $filtered;
			}
		}

		// Attempt internal post resolution if ContentTranslationResolver is available.
		if ( null !== $this->content_resolver && function_exists( 'url_to_postid' ) && function_exists( 'get_permalink' ) ) {
			$post_id = url_to_postid( $trimmed );
			if ( $post_id > 0 ) {
				$translated_id = $this->content_resolver->resolve_element_id( 'post', $post_id, $target_language );
				if ( null !== $translated_id && $translated_id > 0 && $translated_id !== $post_id ) {
					$translated_url = get_permalink( $translated_id );
					if ( is_string( $translated_url ) && '' !== $translated_url ) {
						return $translated_url;
					}
				}
			}
		}

		return $url;
	}

	/**
	 * Localizes settings within an Elementor element or page settings.
	 *
	 * Safely processes media arrays, galleries, link controls, template references, and repeaters.
	 *
	 * @param array<string, mixed> $settings        Settings array.
	 * @param string               $target_language Target language code.
	 * @return array<string, mixed> Localized settings array.
	 */
	public function localize_settings( array $settings, string $target_language ): array {
		foreach ( $settings as $key => $value ) {
			if ( ! is_array( $value ) ) {
				// Check for template reference ID settings in Elementor Pro (e.g. 'template_id' or 'templateID').
				if ( ( 'template_id' === $key || 'templateID' === $key ) && is_numeric( $value ) && null !== $this->content_resolver ) {
					$tmpl_id = (int) $value;
					if ( $tmpl_id > 0 ) {
						$translated_tmpl = $this->content_resolver->resolve_element_id( 'post', $tmpl_id, $target_language );
						if ( null !== $translated_tmpl && $translated_tmpl > 0 ) {
							$settings[ $key ] = (string) $translated_tmpl;
						}
					}
				}
				continue;
			}

			// Single media control: ['id' => 123, 'url' => '...'].
			if ( isset( $value['id'] ) && is_numeric( $value['id'] ) && isset( $value['url'] ) ) {
				$orig_id = (int) $value['id'];
				if ( $orig_id > 0 ) {
					$new_id = $this->localize_media_id( $orig_id, $target_language );
					if ( $new_id !== $orig_id ) {
						$value['id'] = $new_id;
						if ( function_exists( 'wp_get_attachment_url' ) ) {
							$new_url = wp_get_attachment_url( $new_id );
							if ( is_string( $new_url ) && '' !== $new_url ) {
								$value['url'] = $new_url;
							}
						}
					}
				}
				$settings[ $key ] = $value;
				continue;
			}

			// Link control: ['url' => '...', 'is_external' => '...', ...].
			if ( isset( $value['url'] ) && is_string( $value['url'] ) && ! isset( $value['id'] ) ) {
				$value['url']     = $this->localize_link_url( $value['url'], $target_language );
				$settings[ $key ] = $value;
				continue;
			}

			// Multi-item collection (gallery or repeater): numerically indexed array.
			if ( isset( $value[0] ) && is_array( $value[0] ) ) {
				foreach ( $value as $idx => $item ) {
					if ( is_array( $item ) ) {
						// Single gallery item: ['id' => 123, 'url' => '...'].
						if ( isset( $item['id'] ) && is_numeric( $item['id'] ) && isset( $item['url'] ) ) {
							$orig_id = (int) $item['id'];
							if ( $orig_id > 0 ) {
								$new_id = $this->localize_media_id( $orig_id, $target_language );
								if ( $new_id !== $orig_id ) {
									$item['id'] = $new_id;
									if ( function_exists( 'wp_get_attachment_url' ) ) {
										$new_url = wp_get_attachment_url( $new_id );
										if ( is_string( $new_url ) && '' !== $new_url ) {
											$item['url'] = $new_url;
										}
									}
								}
							}
							$value[ $idx ] = $item;
						} else {
							// Repeater item containing nested controls.
							$value[ $idx ] = $this->localize_settings( $item, $target_language );
						}
					}
				}
				$settings[ $key ] = $value;
			}
		}

		return $settings;
	}

	/**
	 * Recursively localizes an Elementor element tree.
	 *
	 * Preserves internal technical element IDs while localizing media, links, and nested structures.
	 *
	 * @param array<int, array<string, mixed>> $elements        Element tree array.
	 * @param string                           $target_language Target language code.
	 * @return array<int, array<string, mixed>> Localized element tree.
	 */
	public function localize_elementor_tree( array $elements, string $target_language ): array {
		$localized = array();

		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			// Localize element settings.
			if ( ! empty( $element['settings'] ) && is_array( $element['settings'] ) ) {
				$element['settings'] = $this->localize_settings( $element['settings'], $target_language );
			}

			// Recursively process child elements.
			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$element['elements'] = $this->localize_elementor_tree( $element['elements'], $target_language );
			}

			$localized[] = $element;
		}

		return $localized;
	}

	/**
	 * Duplicates and localizes Elementor data from a source post to a translation target post.
	 *
	 * Pre-warms media cache in a single query ($O(1)$ zero N+1) and persists the localized tree.
	 *
	 * @param int    $source_post_id  Source post ID.
	 * @param int    $target_post_id  Target translation post ID.
	 * @param string $target_language Target language code.
	 * @return bool True if Elementor data was cloned and localized.
	 */
	public function copy_elementor_data_for_translation(
		int $source_post_id,
		int $target_post_id,
		string $target_language
	): bool {
		if ( ! $this->is_elementor_post( $source_post_id ) ) {
			return false;
		}

		$source_elements = $this->get_elementor_data( $source_post_id );
		if ( empty( $source_elements ) ) {
			return false;
		}

		// Pre-warm media cache in a single batch query for zero N+1.
		if ( null !== $this->media_resolver ) {
			$media_ids = $this->extract_media_ids( $source_elements );
			if ( ! empty( $media_ids ) ) {
				$this->media_resolver->prime_cache( $media_ids );
			}
		}

		// Localize element tree preserving IDs and structure.
		$localized_tree = $this->localize_elementor_tree( $source_elements, $target_language );
		$encoded_json   = function_exists( 'wp_json_encode' ) ? wp_json_encode( $localized_tree ) : json_encode( $localized_tree );

		if ( false === $encoded_json ) {
			return false;
		}

		if ( function_exists( 'update_post_meta' ) ) {
			// Save localized Elementor data tree (using wp_slash to prevent WordPress stripslashes_deep corruption).
			$slashed_json = function_exists( 'wp_slash' ) ? wp_slash( $encoded_json ) : $encoded_json;
			update_post_meta( $target_post_id, self::META_DATA, $slashed_json );

			// Mark target post as Elementor builder post.
			update_post_meta( $target_post_id, self::META_EDIT_MODE, 'builder' );

			// Copy template type if present.
			$tmpl_type = get_post_meta( $source_post_id, self::META_TEMPLATE_TYPE, true );
			if ( ! empty( $tmpl_type ) ) {
				update_post_meta( $target_post_id, self::META_TEMPLATE_TYPE, $tmpl_type );
			}

			// Copy version metadata if present.
			$version = get_post_meta( $source_post_id, self::META_VERSION, true );
			if ( ! empty( $version ) ) {
				update_post_meta( $target_post_id, self::META_VERSION, $version );
			}

			$pro_version = get_post_meta( $source_post_id, self::META_PRO_VERSION, true );
			if ( ! empty( $pro_version ) ) {
				update_post_meta( $target_post_id, self::META_PRO_VERSION, $pro_version );
			}

			// Copy page template choice (e.g. elementor_header_footer) if present.
			$page_tmpl = get_post_meta( $source_post_id, self::META_PAGE_TEMPLATE, true );
			if ( ! empty( $page_tmpl ) ) {
				update_post_meta( $target_post_id, self::META_PAGE_TEMPLATE, $page_tmpl );
			}

			// Copy and localize page settings as initial baseline.
			$page_settings = get_post_meta( $source_post_id, self::META_PAGE_SETTINGS, true );
			if ( is_array( $page_settings ) && ! empty( $page_settings ) ) {
				$localized_page_settings = $this->localize_settings( $page_settings, $target_language );
				update_post_meta( $target_post_id, self::META_PAGE_SETTINGS, $localized_page_settings );
			}
		}

		return true;
	}

	/**
	 * Filters the initial post content when creating a translation post.
	 *
	 * If the source post is built with Elementor, initializes the draft with the source
	 * post_content (which acts as the HTML fallback for SEO and search), while Elementor's
	 * builder structure is copied to _elementor_data via on_post_translation_created.
	 *
	 * @param string  $content         Initial content (default empty string).
	 * @param WP_Post $source_post     Source post object.
	 * @param string  $target_language Target language code.
	 * @return string Filtered initial content.
	 */
	public function filter_initial_translation_post_content(
		string $content,
		WP_Post $source_post,
		string $target_language
	): string {
		// If another filter already populated content, respect it.
		if ( '' !== $content ) {
			return $content;
		}

		if ( ! $this->is_elementor_post( $source_post->ID ) ) {
			return $content;
		}

		$should_copy = true;
		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * Allows customizing or disabling initial content copying for Elementor posts.
			 *
			 * @param bool    $should_copy     Whether to copy content (default true).
			 * @param WP_Post $source_post     Source post object.
			 * @param string  $target_language Target language code.
			 */
			$should_copy = (bool) apply_filters(
				'tfml_elementor_copy_content_on_translation',
				true,
				$source_post,
				$target_language
			);
		}

		if ( ! $should_copy ) {
			return $content;
		}

		return (string) ( $source_post->post_content ?? '' );
	}

	/**
	 * Action handler called after a post translation is created.
	 *
	 * Clones and localizes Elementor layout data into the newly created translation post.
	 *
	 * @param int    $new_post_id      Newly created translation post ID.
	 * @param int    $source_post_id   Source post ID.
	 * @param string $target_language  Target language code.
	 * @return void
	 */
	public function on_post_translation_created(
		int $new_post_id,
		int $source_post_id,
		string $target_language
	): void {
		if ( $this->is_elementor_post( $source_post_id ) ) {
			$this->copy_elementor_data_for_translation( $source_post_id, $new_post_id, $target_language );
		}
	}
}
