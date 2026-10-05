<?php
/**
 * URL Language Resolution Value Object.
 *
 * @package TF\Multilingual\Routing
 */

declare( strict_types=1 );

namespace TF\Multilingual\Routing;

use TF\Multilingual\Domain\Language\Language;

/**
 * Class UrlLanguageResolution
 *
 * Represents the typed outcome of resolving a URL or path.
 *
 * Distinguishes between:
 * - ACTIVE: Active language resolved (prefixed secondary or unprefixed default).
 * - INACTIVE: Registered TFML language prefix found, but currently inactive.
 * - DEFAULT_INACTIVE: Fallback to default failed because default language is inactive or missing.
 * - NOT_CONFIGURED: TFML is not configured.
 * - EXCLUDED: Path matches an excluded system endpoint (wp-admin, wp-json, etc.).
 */
final class UrlLanguageResolution {

	/**
	 * Active language resolved successfully.
	 *
	 * @var string
	 */
	public const STATUS_ACTIVE = 'active';

	/**
	 * Known TFML language found in prefix, but the language is inactive.
	 *
	 * @var string
	 */
	public const STATUS_INACTIVE = 'inactive';

	/**
	 * Default language cannot be used because it is inactive or not found.
	 *
	 * @var string
	 */
	public const STATUS_DEFAULT_INACTIVE = 'default_inactive';

	/**
	 * TFML is not yet configured.
	 *
	 * @var string
	 */
	public const STATUS_NOT_CONFIGURED = 'not_configured';

	/**
	 * Path matches an excluded system prefix (e.g. wp-admin, wp-json).
	 *
	 * @var string
	 */
	public const STATUS_EXCLUDED = 'excluded';

	/**
	 * Resolution status.
	 *
	 * @var string
	 */
	private string $status;

	/**
	 * Resolved or detected language code.
	 *
	 * @var string|null
	 */
	private ?string $language_code;

	/**
	 * Evaluated path.
	 *
	 * @var string
	 */
	private string $path;

	/**
	 * Whether the URL had an explicit language prefix segment.
	 *
	 * @var bool
	 */
	private bool $has_explicit_prefix;

	/**
	 * Constructor.
	 *
	 * @param string      $status              Resolution status.
	 * @param string|null $language_code       Language code if applicable.
	 * @param string      $path                Evaluated normalized path.
	 * @param bool        $has_explicit_prefix Whether an explicit language prefix was matched.
	 */
	public function __construct(
		string $status,
		?string $language_code = null,
		string $path = '',
		bool $has_explicit_prefix = false
	) {
		$this->status              = $status;
		$this->language_code       = null !== $language_code && '' !== $language_code ? Language::normalize_code( $language_code ) : null;
		$this->path                = $path;
		$this->has_explicit_prefix = $has_explicit_prefix;
	}

	/**
	 * Factory: active language resolution.
	 *
	 * @param string $language_code       Canonical language code.
	 * @param string $path                Evaluated path.
	 * @param bool   $has_explicit_prefix True if resolved from an explicit prefix segment.
	 * @return self
	 */
	public static function active( string $language_code, string $path = '', bool $has_explicit_prefix = false ): self {
		return new self( self::STATUS_ACTIVE, $language_code, $path, $has_explicit_prefix );
	}

	/**
	 * Factory: registered TFML language found, but inactive.
	 *
	 * @param string $language_code Inactive language code.
	 * @param string $path          Evaluated path.
	 * @return self
	 */
	public static function inactive( string $language_code, string $path = '' ): self {
		return new self( self::STATUS_INACTIVE, $language_code, $path, true );
	}

	/**
	 * Factory: default language is missing or inactive.
	 *
	 * @param string $path Evaluated path.
	 * @return self
	 */
	public static function default_inactive( string $path = '' ): self {
		return new self( self::STATUS_DEFAULT_INACTIVE, null, $path, false );
	}

	/**
	 * Factory: TFML is not configured.
	 *
	 * @param string $path Evaluated path.
	 * @return self
	 */
	public static function not_configured( string $path = '' ): self {
		return new self( self::STATUS_NOT_CONFIGURED, null, $path, false );
	}

	/**
	 * Factory: path matches an excluded system route.
	 *
	 * @param string $path Evaluated path.
	 * @return self
	 */
	public static function excluded( string $path = '' ): self {
		return new self( self::STATUS_EXCLUDED, null, $path, false );
	}

	/**
	 * Returns resolution status.
	 *
	 * @return string
	 */
	public function get_status(): string {
		return $this->status;
	}

	/**
	 * Returns the language code if available.
	 *
	 * @return string|null
	 */
	public function get_language_code(): ?string {
		return $this->language_code;
	}

	/**
	 * Returns the evaluated path.
	 *
	 * @return string
	 */
	public function get_path(): string {
		return $this->path;
	}

	/**
	 * Returns whether the path contained an explicit language prefix segment.
	 *
	 * @return bool
	 */
	public function has_explicit_prefix(): bool {
		return $this->has_explicit_prefix;
	}

	/**
	 * Checks if an active language was resolved.
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		return self::STATUS_ACTIVE === $this->status;
	}

	/**
	 * Checks if a recognized but inactive language was found.
	 *
	 * @return bool
	 */
	public function is_inactive(): bool {
		return self::STATUS_INACTIVE === $this->status;
	}

	/**
	 * Checks if resolution failed due to inactive/corrupt default language.
	 *
	 * @return bool
	 */
	public function is_default_inactive(): bool {
		return self::STATUS_DEFAULT_INACTIVE === $this->status;
	}

	/**
	 * Checks if TFML was not configured during resolution.
	 *
	 * @return bool
	 */
	public function is_not_configured(): bool {
		return self::STATUS_NOT_CONFIGURED === $this->status;
	}

	/**
	 * Checks if TFML is configured.
	 *
	 * @return bool
	 */
	public function is_configured(): bool {
		return self::STATUS_NOT_CONFIGURED !== $this->status;
	}

	/**
	 * Checks if path was excluded.
	 *
	 * @return bool
	 */
	public function is_excluded(): bool {
		return self::STATUS_EXCLUDED === $this->status;
	}

	/**
	 * Checks if a valid, usable active language is present.
	 *
	 * @return bool
	 */
	public function is_valid(): bool {
		return self::STATUS_ACTIVE === $this->status && null !== $this->language_code;
	}
}
