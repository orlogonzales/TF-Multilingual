<?php
/**
 * Custom Field Policy Registry Domain Service.
 *
 * @package TF\Multilingual\Domain\CustomField
 */

declare( strict_types=1 );

namespace TF\Multilingual\Domain\CustomField;

use InvalidArgumentException;
use TF\Multilingual\Domain\Language\SettingsRepository;

/**
 * Class CustomFieldPolicyRegistry
 *
 * Manages configuration and lookup of custom field policies.
 * Strictly defaults any unconfigured or unknown meta key to CustomFieldPolicy::IGNORE.
 */
class CustomFieldPolicyRegistry {

	/**
	 * Settings repository for persistence.
	 *
	 * @var SettingsRepository
	 */
	private SettingsRepository $repository;

	/**
	 * In-memory map of meta_key => policy.
	 *
	 * @var array<string, string>
	 */
	private array $policies = array();

	/**
	 * Whether policies have been loaded from repository.
	 *
	 * @var bool
	 */
	private bool $loaded = false;

	/**
	 * Constructor.
	 *
	 * @param SettingsRepository|null $repository Optional settings repository.
	 */
	public function __construct( ?SettingsRepository $repository = null ) {
		$this->repository = null !== $repository ? $repository : new SettingsRepository();
	}

	/**
	 * Gets the policy for a given meta key.
	 *
	 * Strictly returns CustomFieldPolicy::IGNORE for any unknown, unset, or empty key.
	 * Never guesses based on key name heuristics.
	 *
	 * @param string $meta_key Meta key name.
	 * @return string CustomFieldPolicy value.
	 */
	public function get_policy( string $meta_key ): string {
		$this->ensure_loaded();

		$trimmed = trim( $meta_key );
		if ( '' === $trimmed || ! isset( $this->policies[ $trimmed ] ) ) {
			return CustomFieldPolicy::default();
		}

		return $this->policies[ $trimmed ];
	}

	/**
	 * Sets the policy for a given meta key.
	 *
	 * @param string $meta_key Meta key name.
	 * @param string $policy   Policy ('translate', 'share', 'ignore').
	 * @return void
	 * @throws InvalidArgumentException If meta_key is empty or policy is invalid.
	 */
	public function set_policy( string $meta_key, string $policy ): void {
		$this->ensure_loaded();

		$trimmed_key = trim( $meta_key );
		if ( '' === $trimmed_key ) {
			throw new InvalidArgumentException( 'Meta key cannot be empty.' );
		}

		$normalized_policy = CustomFieldPolicy::normalize( $policy );

		$this->policies[ $trimmed_key ] = $normalized_policy;
	}

	/**
	 * Removes a configured policy for a meta key.
	 *
	 * @param string $meta_key Meta key name.
	 * @return void
	 */
	public function remove_policy( string $meta_key ): void {
		$this->ensure_loaded();

		$trimmed_key = trim( $meta_key );
		unset( $this->policies[ $trimmed_key ] );
	}

	/**
	 * Checks whether a policy has been explicitly configured for a meta key.
	 *
	 * @param string $meta_key Meta key name.
	 * @return bool True if explicitly configured in the registry.
	 */
	public function has_policy( string $meta_key ): bool {
		$this->ensure_loaded();

		$trimmed = trim( $meta_key );
		return '' !== $trimmed && isset( $this->policies[ $trimmed ] );
	}

	/**
	 * Retrieves all configured policies indexed by meta key.
	 *
	 * @return array<string, string>
	 */
	public function get_all_policies(): array {
		$this->ensure_loaded();

		return $this->policies;
	}

	/**
	 * Checks if a meta key is marked for translation.
	 *
	 * @param string $meta_key Meta key name.
	 * @return bool
	 */
	public function is_translatable( string $meta_key ): bool {
		return CustomFieldPolicy::TRANSLATE === $this->get_policy( $meta_key );
	}

	/**
	 * Checks if a meta key is marked for synchronization (shared).
	 *
	 * @param string $meta_key Meta key name.
	 * @return bool
	 */
	public function is_shared( string $meta_key ): bool {
		return CustomFieldPolicy::SHARE === $this->get_policy( $meta_key );
	}

	/**
	 * Checks if a meta key is ignored (or defaults to ignored).
	 *
	 * @param string $meta_key Meta key name.
	 * @return bool
	 */
	public function is_ignored( string $meta_key ): bool {
		return CustomFieldPolicy::IGNORE === $this->get_policy( $meta_key );
	}

	/**
	 * Persists configured policies to settings repository.
	 *
	 * @return bool True on success.
	 */
	public function persist(): bool {
		$this->ensure_loaded();

		$current                  = $this->repository->load();
		$current['custom_fields'] = array(
			'policies' => $this->policies,
		);

		return $this->repository->save( $current );
	}

	/**
	 * Resets in-memory state.
	 *
	 * @return void
	 */
	public function reset(): void {
		$this->policies = array();
		$this->loaded   = false;
	}

	/**
	 * Ensures policies are loaded from repository.
	 *
	 * @return void
	 */
	private function ensure_loaded(): void {
		if ( $this->loaded ) {
			return;
		}

		$settings = $this->repository->load();
		if ( isset( $settings['custom_fields']['policies'] ) && is_array( $settings['custom_fields']['policies'] ) ) {
			foreach ( $settings['custom_fields']['policies'] as $key => $policy ) {
				if ( is_string( $key ) && '' !== trim( $key ) && is_string( $policy ) && CustomFieldPolicy::is_valid( $policy ) ) {
					$this->policies[ trim( $key ) ] = CustomFieldPolicy::normalize( $policy );
				}
			}
		}

		$this->loaded = true;
	}
}
