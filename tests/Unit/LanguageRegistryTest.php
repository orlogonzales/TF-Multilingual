<?php
/**
 * LanguageRegistry Unit Tests.
 *
 * @package TF\Multilingual\Tests\Unit
 */

declare( strict_types=1 );

namespace TF\Multilingual\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TF\Multilingual\Domain\Language\Language;
use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Domain\Language\SettingsRepository;
use TF\Multilingual\Domain\Language\Exceptions\DefaultLanguageException;
use TF\Multilingual\Domain\Language\Exceptions\DuplicateLanguageException;
use TF\Multilingual\Domain\Language\Exceptions\LanguageNotFoundException;

/**
 * Class LanguageRegistryTest
 */
class LanguageRegistryTest extends TestCase {

	/**
	 * Settings repository.
	 *
	 * @var SettingsRepository
	 */
	private SettingsRepository $repository;

	/**
	 * Registry under test.
	 *
	 * @var LanguageRegistry
	 */
	private LanguageRegistry $registry;

	/**
	 * Setup before each test.
	 */
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['wp_test_options'] = array();
		$this->repository           = new SettingsRepository();
		$this->registry             = new LanguageRegistry( $this->repository );
	}

	/**
	 * Tear down after each test.
	 */
	protected function tearDown(): void {
		parent::tearDown();
		$GLOBALS['wp_test_options'] = array();
	}

	/**
	 * Caso 1: Tests empty registry state on clean install.
	 */
	public function test_case_1_empty_registry_is_not_configured(): void {
		$this->assertFalse( $this->registry->is_configured() );
		$this->assertEmpty( $this->registry->all() );
		$this->assertEmpty( $this->registry->active() );
		$this->assertNull( $this->registry->get_default() );
		$this->assertNull( $this->registry->get_default_code() );
	}

	/**
	 * Caso 2: Tests registering first active language retains NOT_CONFIGURED and default = null.
	 */
	public function test_case_2_register_first_active_language_retains_not_configured(): void {
		$es = Language::create( 'es', 'es_ES', 'Spanish', 'Español', true, 10 );
		$this->registry->add_language( $es );

		$this->assertFalse( $this->registry->is_configured() );
		$this->assertNull( $this->registry->get_default() );
		$this->assertNull( $this->registry->get_default_code() );
		$this->assertCount( 1, $this->registry->all() );
		$this->assertTrue( $this->registry->has( 'es' ) );
	}

	/**
	 * Caso 3: Tests registering multiple languages retains NOT_CONFIGURED and default = null.
	 */
	public function test_case_3_register_multiple_languages_retains_not_configured(): void {
		$es = Language::create( 'es', 'es_ES', 'Spanish', 'Español', true, 10 );
		$en = Language::create( 'en', 'en_US', 'English', 'English', true, 20 );

		$this->registry->add_language( $es );
		$this->registry->add_language( $en );

		$this->assertFalse( $this->registry->is_configured() );
		$this->assertNull( $this->registry->get_default() );
		$this->assertNull( $this->registry->get_default_code() );
		$this->assertCount( 2, $this->registry->all() );
		$this->assertCount( 2, $this->registry->active() );
	}

	/**
	 * Caso 4: Tests explicit set_default establishes CONFIGURED state.
	 */
	public function test_case_4_explicit_set_default_configures_registry(): void {
		$es = Language::create( 'es', 'es_ES', 'Spanish', 'Español', true, 10 );
		$en = Language::create( 'en', 'en_US', 'English', 'English', true, 20 );

		$this->registry->add_language( $es );
		$this->registry->add_language( $en );
		$this->assertFalse( $this->registry->is_configured() );

		$this->registry->set_default( 'es' );

		$this->assertTrue( $this->registry->is_configured() );
		$this->assertSame( 'es', $this->registry->get_default_code() );
		$this->assertNotNull( $this->registry->get_default() );
		$this->assertSame( 'Spanish', $this->registry->get_default()->get_name() );

		// Switch default to en.
		$this->registry->set_default( 'en' );
		$this->assertSame( 'en', $this->registry->get_default_code() );
		$this->assertTrue( $this->registry->is_configured() );
	}

	/**
	 * Caso 5: Tests setting non-existent language as default throws LanguageNotFoundException.
	 */
	public function test_case_5_set_default_non_existent_throws_exception(): void {
		$es = Language::create( 'es', 'es_ES', 'Spanish', 'Español', true, 10 );
		$this->registry->add_language( $es );

		$this->expectException( LanguageNotFoundException::class );
		$this->registry->set_default( 'fr' );
	}

	/**
	 * Caso 6: Tests setting inactive language as default throws DefaultLanguageException.
	 */
	public function test_case_6_set_default_inactive_throws_exception(): void {
		$es = Language::create( 'es', 'es_ES', 'Spanish', 'Español', true, 10 );
		$fr = Language::create( 'fr', 'fr_FR', 'French', 'Français', false, 30 );

		$this->registry->add_language( $es );
		$this->registry->add_language( $fr );

		$this->expectException( DefaultLanguageException::class );
		$this->registry->set_default( 'fr' );
	}

	/**
	 * Caso 7: Tests persisting and reloading registry without default remains NOT_CONFIGURED.
	 */
	public function test_case_7_persist_and_reload_without_default_remains_not_configured(): void {
		$es = Language::create( 'es', 'es_ES', 'Spanish', 'Español', true, 10 );
		$en = Language::create( 'en', 'en_US', 'English', 'English', true, 20 );

		$this->registry->add_language( $es );
		$this->registry->add_language( $en );
		$this->registry->persist();

		$reloaded_registry = new LanguageRegistry( $this->repository );
		$this->assertFalse( $reloaded_registry->is_configured() );
		$this->assertNull( $reloaded_registry->get_default_code() );
		$this->assertNull( $reloaded_registry->get_default() );
		$this->assertCount( 2, $reloaded_registry->all() );
		$this->assertTrue( $reloaded_registry->has( 'es' ) );
		$this->assertTrue( $reloaded_registry->has( 'en' ) );
	}

	/**
	 * Tests adding language with explicit is_default flag.
	 */
	public function test_add_language_with_explicit_is_default_flag(): void {
		$es = Language::create( 'es', 'es_ES', 'Spanish', 'Español', true, 10 );
		$this->registry->add_language( $es, true );

		$this->assertTrue( $this->registry->is_configured() );
		$this->assertSame( 'es', $this->registry->get_default_code() );
	}

	/**
	 * Tests adding duplicate language code throws exception.
	 */
	public function test_add_duplicate_throws_exception(): void {
		$es1 = Language::create( 'es', 'es_ES', 'Spanish', 'Español', true, 10 );
		$es2 = Language::create( 'ES', 'es_ES', 'Castilian', 'Castellano', true, 20 );

		$this->registry->add_language( $es1 );

		$this->expectException( DuplicateLanguageException::class );
		$this->registry->add_language( $es2 );
	}

	/**
	 * Tests cannot deactivate sovereign default language.
	 */
	public function test_cannot_deactivate_default_language(): void {
		$es = Language::create( 'es', 'es_ES', 'Spanish', 'Español', true, 10 );
		$this->registry->add_language( $es, true );

		$this->expectException( DefaultLanguageException::class );
		$this->registry->deactivate( 'es' );
	}

	/**
	 * Tests cannot remove sovereign default language.
	 */
	public function test_cannot_remove_default_language(): void {
		$es = Language::create( 'es', 'es_ES', 'Spanish', 'Español', true, 10 );
		$this->registry->add_language( $es, true );

		$this->expectException( DefaultLanguageException::class );
		$this->registry->remove_language( 'es' );
	}

	/**
	 * Tests activating and deactivating secondary language.
	 */
	public function test_activate_and_deactivate_secondary_language(): void {
		$es = Language::create( 'es', 'es_ES', 'Spanish', 'Español', true, 10 );
		$en = Language::create( 'en', 'en_US', 'English', 'English', true, 20 );

		$this->registry->add_language( $es, true );
		$this->registry->add_language( $en );

		$this->assertTrue( $this->registry->is_active( 'en' ) );

		$this->registry->deactivate( 'en' );
		$this->assertFalse( $this->registry->is_active( 'en' ) );
		$this->assertCount( 1, $this->registry->active() );
		$this->assertCount( 2, $this->registry->all() );

		$this->registry->activate( 'en' );
		$this->assertTrue( $this->registry->is_active( 'en' ) );
		$this->assertCount( 2, $this->registry->active() );
	}

	/**
	 * Tests removing secondary language.
	 */
	public function test_remove_secondary_language(): void {
		$es = Language::create( 'es', 'es_ES', 'Spanish', 'Español', true, 10 );
		$en = Language::create( 'en', 'en_US', 'English', 'English', true, 20 );

		$this->registry->add_language( $es, true );
		$this->registry->add_language( $en );

		$this->assertTrue( $this->registry->has( 'en' ) );
		$this->registry->remove_language( 'en' );
		$this->assertFalse( $this->registry->has( 'en' ) );
	}

	/**
	 * Tests get_or_fail throws LanguageNotFoundException.
	 */
	public function test_get_or_fail_throws_for_missing(): void {
		$this->expectException( LanguageNotFoundException::class );
		$this->registry->get_or_fail( 'non_existent' );
	}

	/**
	 * Tests reordering languages.
	 */
	public function test_reorder_languages(): void {
		$es = Language::create( 'es', 'es_ES', 'Spanish', 'Español', true, 10 );
		$en = Language::create( 'en', 'en_US', 'English', 'English', true, 20 );
		$fr = Language::create( 'fr', 'fr_FR', 'French', 'Français', true, 30 );

		$this->registry->add_language( $es );
		$this->registry->add_language( $en );
		$this->registry->add_language( $fr );

		$this->registry->reorder( array( 'fr', 'es', 'en' ) );

		$all = array_values( $this->registry->all() );
		$this->assertSame( 'fr', $all[0]->get_code() );
		$this->assertSame( 10, $all[0]->get_order() );
		$this->assertSame( 'es', $all[1]->get_code() );
		$this->assertSame( 20, $all[1]->get_order() );
		$this->assertSame( 'en', $all[2]->get_code() );
		$this->assertSame( 30, $all[2]->get_order() );
	}

	/**
	 * Tests updating language properties.
	 */
	public function test_update_language(): void {
		$es = Language::create( 'es', 'es_ES', 'Spanish', 'Español', true, 10 );
		$this->registry->add_language( $es );

		$this->registry->update_language(
			'es',
			array(
				'name'        => 'Castilian Spanish',
				'native_name' => 'Castellano',
			)
		);

		$updated = $this->registry->get( 'es' );
		$this->assertNotNull( $updated );
		$this->assertSame( 'Castilian Spanish', $updated->get_name() );
		$this->assertSame( 'Castellano', $updated->get_native_name() );
	}

	/**
	 * Tests update_language cannot deactivate default language.
	 */
	public function test_update_language_cannot_deactivate_default(): void {
		$es = Language::create( 'es', 'es_ES', 'Spanish', 'Español', true, 10 );
		$this->registry->add_language( $es, true );

		$this->expectException( DefaultLanguageException::class );
		$this->registry->update_language( 'es', array( 'active' => false ) );
	}

	/**
	 * Tests persist saves state to repository and reloads accurately when configured.
	 */
	public function test_persist_and_reload_when_configured(): void {
		$es = Language::create( 'es', 'es_ES', 'Spanish', 'Español', true, 10 );
		$en = Language::create( 'en', 'en_US', 'English', 'English', true, 20 );

		$this->registry->add_language( $es, true );
		$this->registry->add_language( $en );
		$this->registry->persist();

		$new_registry = new LanguageRegistry( $this->repository );
		$this->assertTrue( $new_registry->is_configured() );
		$this->assertSame( 'es', $new_registry->get_default_code() );
		$this->assertCount( 2, $new_registry->all() );
		$this->assertTrue( $new_registry->has( 'es' ) );
		$this->assertTrue( $new_registry->has( 'en' ) );
	}

	/**
	 * Tests inconsistent persisted default (non-existent code) degrades to NOT_CONFIGURED.
	 */
	public function test_inconsistent_persisted_default_degrades_to_not_configured(): void {
		$this->repository->save(
			array(
				'default_language' => 'non_existent',
				'languages'        => array(
					'es' => array(
						'code'        => 'es',
						'locale'      => 'es_ES',
						'name'        => 'Spanish',
						'native_name' => 'Español',
						'active'      => true,
						'order'       => 10,
					),
				),
			)
		);

		$registry = new LanguageRegistry( $this->repository );
		$this->assertFalse( $registry->is_configured() );
		$this->assertNull( $registry->get_default_code() );
		$this->assertNull( $registry->get_default() );
		$this->assertCount( 1, $registry->all() );
	}

	/**
	 * Tests corrupt entries in persistence are skipped gracefully.
	 */
	public function test_corrupted_entries_in_persistence_are_skipped(): void {
		$this->repository->save(
			array(
				'default_language' => 'es',
				'languages'        => array(
					'es'      => array(
						'code'        => 'es',
						'locale'      => 'es_ES',
						'name'        => 'Spanish',
						'native_name' => 'Español',
						'active'      => true,
						'order'       => 10,
					),
					'corrupt' => array(
						'code'   => '!!!', // Invalid code format.
						'locale' => 'invalid',
					),
				),
			)
		);

		$fresh_registry = new LanguageRegistry( $this->repository );

		$this->assertTrue( $fresh_registry->is_configured() );
		$this->assertCount( 1, $fresh_registry->all() );
		$this->assertTrue( $fresh_registry->has( 'es' ) );
		$this->assertFalse( $fresh_registry->has( 'corrupt' ) );
	}
}
