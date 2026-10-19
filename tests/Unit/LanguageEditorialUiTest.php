<?php
/**
 * Language Editorial UI Unit Tests.
 *
 * @package TF\Multilingual\Tests\Unit
 */

declare( strict_types=1 );

namespace TF\Multilingual\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TF\Multilingual\Admin\LanguageEditorialUi;
use TF\Multilingual\Domain\Language\Language;
use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Domain\Language\SettingsRepository;
use TF\Multilingual\Migration\RetainedTranslationRegistry;
use TF\Multilingual\Tests\Doubles\TestableWpdb;

/**
 * Class LanguageEditorialUiTest
 */
class LanguageEditorialUiTest extends TestCase {

	/**
	 * Settings repository double.
	 *
	 * @var SettingsRepository
	 */
	private SettingsRepository $repository;

	/**
	 * Language registry.
	 *
	 * @var LanguageRegistry
	 */
	private LanguageRegistry $registry;

	/**
	 * Retained translation registry.
	 *
	 * @var RetainedTranslationRegistry
	 */
	private RetainedTranslationRegistry $retained_registry;

	/**
	 * Language editorial UI under test.
	 *
	 * @var LanguageEditorialUi
	 */
	private LanguageEditorialUi $ui;

	/**
	 * Database double.
	 *
	 * @var TestableWpdb
	 */
	private TestableWpdb $db;

	/**
	 * Sets up test fixtures before each test.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->db                   = new TestableWpdb();
		$GLOBALS['wpdb']            = $this->db;
		$GLOBALS['wp_test_options'] = array();

		$this->repository = new SettingsRepository();
		// Seed standard initial languages: es (default), en, pt.
		$this->repository->save(
			array(
				'languages'        => array(
					'es' => array(
						'code'        => 'es',
						'locale'      => 'es_ES',
						'name'        => 'Spanish',
						'native_name' => 'Español',
						'active'      => true,
						'order'       => 10,
					),
					'en' => array(
						'code'        => 'en',
						'locale'      => 'en_US',
						'name'        => 'English',
						'native_name' => 'English',
						'active'      => true,
						'order'       => 20,
					),
					'pt' => array(
						'code'        => 'pt',
						'locale'      => 'pt_PT',
						'name'        => 'Portuguese',
						'native_name' => 'Português',
						'active'      => true,
						'order'       => 30,
					),
				),
				'default_language' => 'es',
			)
		);

		$this->registry          = new LanguageRegistry( $this->repository );
		$this->retained_registry = new RetainedTranslationRegistry( $this->db );
		$this->ui                = new LanguageEditorialUi( $this->registry, $this->retained_registry );

		// Clean up Superglobals.
		$_GET  = array();
		$_POST = array();
	}

	/**
	 * Tears down test fixtures.
	 */
	protected function tearDown(): void {
		$_GET                       = array();
		$_POST                      = array();
		$GLOBALS['wp_test_options'] = array();
		parent::tearDown();
	}

	/**
	 * Tests get_capability returns default and respects filters.
	 */
	public function test_get_capability_default(): void {
		$this->assertSame( 'manage_options', $this->ui->get_capability() );
	}

	/**
	 * Tests get_predefined_languages returns standard catalog.
	 */
	public function test_get_predefined_languages(): void {
		$predefined = $this->ui->get_predefined_languages();
		$this->assertArrayHasKey( 'es', $predefined );
		$this->assertArrayHasKey( 'en', $predefined );
		$this->assertArrayHasKey( 'pt', $predefined );
		$this->assertArrayHasKey( 'fr', $predefined );
		$this->assertArrayHasKey( 'de', $predefined );
		$this->assertArrayHasKey( 'it', $predefined );
		$this->assertSame( 'French', $predefined['fr']['name'] );
		$this->assertSame( 'fr_FR', $predefined['fr']['locale'] );
	}

	/**
	 * Tests get_flag_html returns img for existing flags and badge for unknown.
	 */
	public function test_get_flag_html(): void {
		$es_flag = $this->ui->get_flag_html( 'es', 'Español' );
		$this->assertStringContainsString( '<img', $es_flag );
		$this->assertStringContainsString( 'es.svg', $es_flag );

		$fr_flag = $this->ui->get_flag_html( 'fr', 'Français' );
		$this->assertStringContainsString( '<img', $fr_flag );
		$this->assertStringContainsString( 'fr.svg', $fr_flag );

		$unknown = $this->ui->get_flag_html( 'xx', 'Unknown' );
		$this->assertStringContainsString( '<span', $unknown );
		$this->assertStringContainsString( 'XX', $unknown );
	}

	/**
	 * Tests handle_form_submission ignores other pages.
	 */
	public function test_handle_form_submission_ignores_unrelated_pages(): void {
		$_GET['page']         = 'options-general.php';
		$_POST['tfml_action'] = 'add_language';
		$_POST['code']        = 'de';

		$this->ui->handle_form_submission();
		$this->assertFalse( $this->registry->has( 'de' ) );
	}

	/**
	 * Tests adding a new language successfully.
	 */
	public function test_process_add_language_success(): void {
		$_GET['page']         = LanguageEditorialUi::PAGE_SLUG;
		$_POST['tfml_action'] = 'add_language';
		$_POST['code']        = 'de';
		$_POST['name']        = 'German';
		$_POST['native_name'] = 'Deutsch';
		$_POST['locale']      = 'de_DE';
		$_POST['order']       = '40';
		$_POST['active']      = '1';

		$this->ui->handle_form_submission();

		$this->assertTrue( $this->registry->has( 'de' ) );
		$lang = $this->registry->get( 'de' );
		$this->assertNotNull( $lang );
		$this->assertSame( 'Deutsch', $lang->get_native_name() );
		$this->assertSame( 'de_DE', $lang->get_locale() );
		$this->assertTrue( $lang->is_active() );
		$this->assertSame( 40, $lang->get_order() );
	}

	/**
	 * Tests adding duplicate language produces error notice without crashing.
	 */
	public function test_process_add_language_duplicate_fails_gracefully(): void {
		$_GET['page']         = LanguageEditorialUi::PAGE_SLUG;
		$_POST['tfml_action'] = 'add_language';
		$_POST['code']        = 'es'; // Already exists
		$_POST['name']        = 'Spanish duplicate';
		$_POST['native_name'] = 'Español';
		$_POST['locale']      = 'es_ES';

		$this->ui->handle_form_submission();

		// es is not overwritten with wrong name
		$this->assertSame( 'Spanish', $this->registry->get( 'es' )->get_name() );
	}

	/**
	 * Tests toggling active state of a non-default language.
	 */
	public function test_process_toggle_active_success(): void {
		$this->assertTrue( $this->registry->is_active( 'en' ) );

		$_GET['page']        = LanguageEditorialUi::PAGE_SLUG;
		$_GET['tfml_action'] = 'toggle_active';
		$_GET['code']        = 'en';

		$this->ui->handle_form_submission();
		$this->assertFalse( $this->registry->is_active( 'en' ) );

		// Toggle back to active
		$this->ui->handle_form_submission();
		$this->assertTrue( $this->registry->is_active( 'en' ) );
	}

	/**
	 * Tests invariant: Sovereign default language cannot be deactivated.
	 */
	public function test_process_toggle_active_default_language_invariance(): void {
		$this->assertTrue( $this->registry->is_active( 'es' ) );
		$this->assertSame( 'es', $this->registry->get_default_code() );

		$_GET['page']        = LanguageEditorialUi::PAGE_SLUG;
		$_GET['tfml_action'] = 'toggle_active';
		$_GET['code']        = 'es';

		$this->ui->handle_form_submission();

		// es MUST remain active!
		$this->assertTrue( $this->registry->is_active( 'es' ) );
	}

	/**
	 * Tests setting a new sovereign default language.
	 */
	public function test_process_set_default_success(): void {
		$this->assertSame( 'es', $this->registry->get_default_code() );

		$_GET['page']        = LanguageEditorialUi::PAGE_SLUG;
		$_GET['tfml_action'] = 'set_default';
		$_GET['code']        = 'en';

		$this->ui->handle_form_submission();

		$this->assertSame( 'en', $this->registry->get_default_code() );
	}

	/**
	 * Tests deleting a non-default language.
	 */
	public function test_process_delete_language_success(): void {
		$this->assertTrue( $this->registry->has( 'pt' ) );

		$_GET['page']        = LanguageEditorialUi::PAGE_SLUG;
		$_GET['tfml_action'] = 'delete_language';
		$_GET['code']        = 'pt';

		$this->ui->handle_form_submission();

		$this->assertFalse( $this->registry->has( 'pt' ) );
	}

	/**
	 * Tests invariant: Sovereign default language cannot be deleted.
	 */
	public function test_process_delete_default_language_invariance(): void {
		$this->assertTrue( $this->registry->has( 'es' ) );
		$this->assertSame( 'es', $this->registry->get_default_code() );

		$_GET['page']        = LanguageEditorialUi::PAGE_SLUG;
		$_GET['tfml_action'] = 'delete_language';
		$_GET['code']        = 'es';

		$this->ui->handle_form_submission();

		// es MUST remain registered!
		$this->assertTrue( $this->registry->has( 'es' ) );
	}

	/**
	 * Tests updating language properties.
	 */
	public function test_process_update_language_success(): void {
		$_GET['page']         = LanguageEditorialUi::PAGE_SLUG;
		$_POST['tfml_action'] = 'update_language';
		$_POST['code']        = 'en';
		$_POST['name']        = 'English (US)';
		$_POST['native_name'] = 'American English';
		$_POST['locale']      = 'en_US';
		$_POST['order']       = '25';

		$this->ui->handle_form_submission();

		$lang = $this->registry->get( 'en' );
		$this->assertSame( 'English (US)', $lang->get_name() );
		$this->assertSame( 'American English', $lang->get_native_name() );
		$this->assertSame( 25, $lang->get_order() );
	}

	/**
	 * Tests render_page outputs HTML with language table, badges, form, and matrix.
	 */
	public function test_render_page_outputs_html(): void {
		ob_start();
		$this->ui->render_page();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Idiomas — TF Multilingual', $output );
		$this->assertStringContainsString( 'Español', $output );
		$this->assertStringContainsString( 'English', $output );
		$this->assertStringContainsString( 'Português', $output );
		$this->assertStringContainsString( 'Predeterminado', $output );
		$this->assertStringContainsString( 'Añadir nuevo idioma', $output );
		$this->assertStringContainsString( 'Matriz de Módulos', $output );
	}
}
