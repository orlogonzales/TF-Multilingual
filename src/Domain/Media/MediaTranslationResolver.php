<?php
/**
 * Media Translation Resolver Service.
 *
 * @package TF\Multilingual\Domain\Media
 */

declare( strict_types=1 );

namespace TF\Multilingual\Domain\Media;

use InvalidArgumentException;
use TF\Multilingual\Domain\Language\Exceptions\InvalidLanguageException;
use TF\Multilingual\Domain\Language\Exceptions\LanguageNotFoundException;
use TF\Multilingual\Domain\Language\Language;
use TF\Multilingual\Domain\Language\LanguageRegistry;
use WP_Post;

/**
 * Class MediaTranslationResolver
 *
 * Domain service responsible for resolving localized media metadata for attachments.
 * Implements strict single-attachment fallback to native Core metadata and in-request batch caching.
 */
class MediaTranslationResolver {

	/**
	 * Media translation repository.
	 *
	 * @var MediaTranslationRepository
	 */
	private MediaTranslationRepository $repository;

	/**
	 * Language registry domain service.
	 *
	 * @var LanguageRegistry
	 */
	private LanguageRegistry $language_registry;

	/**
	 * In-request memory cache indexed by [attachment_id][language_code].
	 *
	 * @var array<int, array<string, MediaTranslation>>
	 */
	private array $cache = array();

	/**
	 * Constructor.
	 *
	 * @param MediaTranslationRepository|null $repository        Repository instance.
	 * @param LanguageRegistry|null           $language_registry Language registry instance.
	 */
	public function __construct(
		?MediaTranslationRepository $repository = null,
		?LanguageRegistry $language_registry = null
	) {
		$this->repository        = null !== $repository ? $repository : new MediaTranslationRepository();
		$this->language_registry = null !== $language_registry ? $language_registry : new LanguageRegistry();
	}

	/**
	 * Resolves localized editorial metadata for a specific attachment and target language.
	 *
	 * Core Fallback Rule:
	 * - If a TFML record exists for ($attachment_id, $language_code), it is returned.
	 * - If no TFML record exists, native Core attachment metadata is returned (marked is_fallback=true).
	 * - Strictly avoids fallback to other TFML languages (e.g. EN does not fall back to ES).
	 *
	 * @param int         $attachment_id Attachment post ID.
	 * @param string|null $language_code Target canonical language code (null for default language).
	 * @return MediaTranslation Localized media translation entity or Core fallback.
	 * @throws InvalidArgumentException If attachment ID is invalid.
	 */
	public function resolve( int $attachment_id, ?string $language_code = null ): MediaTranslation {
		if ( $attachment_id <= 0 ) {
			throw new InvalidArgumentException( 'Attachment ID must be a positive integer.' );
		}

		$lang = null !== $language_code ? Language::normalize_code( $language_code ) : '';
		if ( '' === $lang ) {
			$lang = (string) ( $this->language_registry->get_default_code() ?? 'es' );
		}

		if ( '' === $lang ) {
			$lang = 'es';
		}

		// Check in-request runtime cache.
		if ( isset( $this->cache[ $attachment_id ][ $lang ] ) ) {
			return $this->cache[ $attachment_id ][ $lang ];
		}

		// Attempt lookup from sovereign repository.
		$persisted = $this->repository->find( $attachment_id, $lang );
		if ( null !== $persisted ) {
			$this->cache[ $attachment_id ][ $lang ] = $persisted;
			return $persisted;
		}

		// Fallback to native WordPress Core attachment metadata.
		$fallback                               = $this->build_core_fallback( $attachment_id, $lang );
		$this->cache[ $attachment_id ][ $lang ] = $fallback;

		return $fallback;
	}

	/**
	 * Pre-warms the in-memory cache for a list of attachment IDs in a single query (Zero N+1).
	 *
	 * @param array<int> $attachment_ids Array of attachment IDs.
	 * @return void
	 */
	public function prime_cache( array $attachment_ids ): void {
		$valid_ids = array_values( array_unique( array_filter( array_map( 'intval', $attachment_ids ), fn( $id ) => $id > 0 ) ) );
		if ( empty( $valid_ids ) ) {
			return;
		}

		// Determine which IDs are not yet loaded in cache.
		$uncached_ids = array();
		foreach ( $valid_ids as $id ) {
			if ( ! isset( $this->cache[ $id ] ) ) {
				$uncached_ids[] = $id;
			}
		}

		if ( empty( $uncached_ids ) ) {
			return;
		}

		$batch_data = $this->repository->find_by_attachments( $uncached_ids );
		foreach ( $uncached_ids as $id ) {
			if ( ! isset( $this->cache[ $id ] ) ) {
				$this->cache[ $id ] = array();
			}

			if ( isset( $batch_data[ $id ] ) ) {
				foreach ( $batch_data[ $id ] as $lang => $entity ) {
					$this->cache[ $id ][ $lang ] = $entity;
				}
			}
		}
	}

	/**
	 * Clears the in-memory runtime cache.
	 *
	 * @return void
	 */
	public function flush_cache(): void {
		$this->cache = array();
	}

	/**
	 * Explicitly adopts current Core metadata as the initial TFML variant for a language.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $language_code Target active language code.
	 * @return MediaTranslation The newly persisted translation entity.
	 * @throws InvalidArgumentException If attachment ID is invalid.
	 * @throws LanguageNotFoundException If language is not registered.
	 * @throws InvalidLanguageException If language is inactive.
	 */
	public function adopt_core_metadata( int $attachment_id, string $language_code ): MediaTranslation {
		if ( $attachment_id <= 0 ) {
			throw new InvalidArgumentException( 'Attachment ID must be a positive integer.' );
		}

		$normalized_lang = Language::normalize_code( $language_code );
		if ( ! $this->language_registry->has( $normalized_lang ) ) {
			throw LanguageNotFoundException::for_code( $normalized_lang );
		}

		if ( ! $this->language_registry->is_active( $normalized_lang ) ) {
			throw InvalidLanguageException::for_inactive_language( $normalized_lang );
		}

		$core   = $this->get_core_metadata( $attachment_id );
		$entity = MediaTranslation::create(
			$attachment_id,
			$normalized_lang,
			$core['alt_text'],
			$core['title'],
			$core['caption'],
			$core['description']
		);

		$saved = $this->repository->save( $entity );
		$this->cache[ $attachment_id ][ $normalized_lang ] = $saved;

		return $saved;
	}

	/**
	 * Builds a Core fallback MediaTranslation entity from the WordPress attachment object.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $language_code Target language code.
	 * @return MediaTranslation Fallback entity.
	 */
	private function build_core_fallback( int $attachment_id, string $language_code ): MediaTranslation {
		$core = $this->get_core_metadata( $attachment_id );

		return MediaTranslation::create_fallback(
			$attachment_id,
			$language_code,
			$core['alt_text'],
			$core['title'],
			$core['caption'],
			$core['description']
		);
	}

	/**
	 * Retrieves raw WordPress Core metadata for an attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array{alt_text: string, title: string, caption: string, description: string}
	 */
	private function get_core_metadata( int $attachment_id ): array {
		$alt         = '';
		$title       = '';
		$caption     = '';
		$description = '';

		if ( function_exists( 'get_post_meta' ) ) {
			$alt = (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
		} elseif ( isset( $GLOBALS['wp_test_postmeta'][ $attachment_id ]['_wp_attachment_image_alt'] ) ) {
			$alt = (string) $GLOBALS['wp_test_postmeta'][ $attachment_id ]['_wp_attachment_image_alt'];
		}

		$post = null;
		if ( function_exists( 'get_post' ) ) {
			$post = get_post( $attachment_id );
		} elseif ( isset( $GLOBALS['wp_test_posts'][ $attachment_id ] ) ) {
			$post = $GLOBALS['wp_test_posts'][ $attachment_id ];
		}

		if ( $post instanceof WP_Post || is_object( $post ) ) {
			$title       = (string) ( $post->post_title ?? '' );
			$caption     = (string) ( $post->post_excerpt ?? '' );
			$description = (string) ( $post->post_content ?? '' );
		}

		return array(
			'alt_text'    => $alt,
			'title'       => $title,
			'caption'     => $caption,
			'description' => $description,
		);
	}
}
