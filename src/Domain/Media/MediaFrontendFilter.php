<?php
/**
 * Media Frontend Filter Component.
 *
 * @package TF\Multilingual\Domain\Media
 */

declare( strict_types=1 );

namespace TF\Multilingual\Domain\Media;

use TF\Multilingual\Routing\CurrentLanguageResolver;
use WP_Post;

/**
 * Class MediaFrontendFilter
 *
 * Integrates TFML localized media metadata seamlessly into WordPress frontend rendering.
 * Applies localized ALT and Caption attributes without duplicating physical media files or attachments.
 */
class MediaFrontendFilter {

	/**
	 * Media translation resolver.
	 *
	 * @var MediaTranslationResolver
	 */
	private MediaTranslationResolver $resolver;

	/**
	 * Current language resolver.
	 *
	 * @var CurrentLanguageResolver
	 */
	private CurrentLanguageResolver $current_language_resolver;

	/**
	 * Media translation repository.
	 *
	 * @var MediaTranslationRepository
	 */
	private MediaTranslationRepository $repository;

	/**
	 * Constructor.
	 *
	 * @param MediaTranslationResolver   $resolver                  Resolver service.
	 * @param CurrentLanguageResolver    $current_language_resolver Current language resolver.
	 * @param MediaTranslationRepository $repository                Repository instance.
	 */
	public function __construct(
		MediaTranslationResolver $resolver,
		CurrentLanguageResolver $current_language_resolver,
		MediaTranslationRepository $repository
	) {
		$this->resolver                  = $resolver;
		$this->current_language_resolver = $current_language_resolver;
		$this->repository                = $repository;
	}

	/**
	 * Registers WordPress action and filter hooks.
	 *
	 * @return void
	 */
	public function init_hooks(): void {
		if ( function_exists( 'add_filter' ) ) {
			add_filter( 'wp_get_attachment_image_attributes', array( $this, 'filter_attachment_image_attributes' ), 10, 3 );
			add_filter( 'wp_get_attachment_caption', array( $this, 'filter_attachment_caption' ), 10, 2 );
		}

		if ( function_exists( 'add_action' ) ) {
			add_action( 'delete_attachment', array( $this, 'on_delete_attachment' ), 10, 1 );
		}
	}

	/**
	 * Removes WordPress action and filter hooks.
	 *
	 * @return void
	 */
	public function remove_hooks(): void {
		if ( function_exists( 'remove_filter' ) ) {
			remove_filter( 'wp_get_attachment_image_attributes', array( $this, 'filter_attachment_image_attributes' ), 10 );
			remove_filter( 'wp_get_attachment_caption', array( $this, 'filter_attachment_caption' ), 10 );
		}

		if ( function_exists( 'remove_action' ) ) {
			remove_action( 'delete_attachment', array( $this, 'on_delete_attachment' ), 10 );
		}
	}

	/**
	 * Filters image attributes produced by wp_get_attachment_image() to inject localized alt text.
	 *
	 * @param array<string, mixed> $attr       HTML attributes array.
	 * @param WP_Post              $attachment Attachment post object.
	 * @param string|array<int>    $size       Requested image size.
	 * @return array<string, mixed> Localized attributes array.
	 */
	public function filter_attachment_image_attributes( array $attr, WP_Post $attachment, string|array $size ): array {
		$lang = $this->current_language_resolver->get_current_language();
		if ( null === $lang || '' === $lang ) {
			return $attr;
		}

		$translation = $this->resolver->resolve( (int) $attachment->ID, $lang );
		// When a TFML variant exists, apply its alt_text (even if explicitly empty for decorative images).
		if ( ! $translation->is_fallback() ) {
			$attr['alt'] = $translation->get_alt_text();
		} elseif ( '' !== $translation->get_alt_text() ) {
			$attr['alt'] = $translation->get_alt_text();
		}

		return $attr;
	}

	/**
	 * Filters attachment caption produced by wp_get_attachment_caption().
	 *
	 * @param string|false $caption Current caption string or false.
	 * @param int          $post_id Attachment post ID.
	 * @return string|false Localized caption string or false.
	 */
	public function filter_attachment_caption( string|false $caption, int $post_id ): string|false {
		if ( $post_id <= 0 ) {
			return $caption;
		}

		$lang = $this->current_language_resolver->get_current_language();
		if ( null === $lang || '' === $lang ) {
			return $caption;
		}

		$translation = $this->resolver->resolve( $post_id, $lang );
		if ( ! $translation->is_fallback() ) {
			return $translation->get_caption();
		}

		return $caption;
	}

	/**
	 * Cleans up all TFML translation records when an attachment is permanently deleted from WordPress.
	 *
	 * @param int $post_id Deleted attachment post ID.
	 * @return void
	 */
	public function on_delete_attachment( int $post_id ): void {
		if ( $post_id <= 0 ) {
			return;
		}

		$this->repository->delete_all_for_attachment( $post_id );
		$this->resolver->flush_cache();
	}
}
