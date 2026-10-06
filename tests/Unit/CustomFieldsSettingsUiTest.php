<?php
/**
 * CustomFieldsSettingsUi Unit Tests.
 *
 * @package TF\Multilingual\Tests\Unit
 */

declare( strict_types=1 );

namespace TF\Multilingual\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TF\Multilingual\Admin\CustomFieldsSettingsUi;
use TF\Multilingual\Domain\CustomField\CustomFieldPolicy;
use TF\Multilingual\Domain\CustomField\CustomFieldPolicyRegistry;
use TF\Multilingual\Domain\Language\SettingsRepository;

/**
 * Class CustomFieldsSettingsUiTest
 */
class CustomFieldsSettingsUiTest extends TestCase {

	/**
	 * Settings repository.
	 *
	 * @var SettingsRepository
	 */
	private SettingsRepository $repository;

	/**
	 * Policy registry.
	 *
	 * @var CustomFieldPolicyRegistry
	 */
	private CustomFieldPolicyRegistry $registry;

	/**
	 * UI component under test.
	 *
	 * @var CustomFieldsSettingsUi
	 */
	private CustomFieldsSettingsUi $ui;

	/**
	 * Set up test environment before each test.
	 */
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['wp_test_options'] = array();
		$GLOBALS['wp_test_caps']    = array( 'manage_options' => true );
		$_GET                       = array();
		$_POST                      = array();
		$_SERVER['REQUEST_METHOD']  = 'GET';

		$this->repository = new SettingsRepository();
		$this->registry   = new CustomFieldPolicyRegistry( $this->repository );
		$this->ui         = new CustomFieldsSettingsUi( $this->registry );
	}

	/**
	 * Tear down test environment after each test.
	 */
	protected function tearDown(): void {
		$GLOBALS['wp_test_options'] = array();
		$GLOBALS['wp_test_caps']    = array();
		$_GET                       = array();
		$_POST                      = array();
		parent::tearDown();
	}

	/**
	 * Tests form submission saves policy.
	 */
	public function test_handle_form_submission_saves_policy(): void {
		$_GET['page']              = CustomFieldsSettingsUi::PAGE_SLUG;
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST['tfml_action']      = 'save_policy';
		$_POST['meta_key']         = '_tour_price';
		$_POST['policy']           = CustomFieldPolicy::SHARE;

		$this->ui->handle_form_submission();

		$this->assertSame( CustomFieldPolicy::SHARE, $this->registry->get_policy( '_tour_price' ) );

		// Verify persisted to repository.
		$fresh = new CustomFieldPolicyRegistry( $this->repository );
		$this->assertSame( CustomFieldPolicy::SHARE, $fresh->get_policy( '_tour_price' ) );
	}

	/**
	 * Tests form submission with empty key does not save.
	 */
	public function test_handle_form_submission_with_empty_key_fails(): void {
		$_GET['page']              = CustomFieldsSettingsUi::PAGE_SLUG;
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST['tfml_action']      = 'save_policy';
		$_POST['meta_key']         = '   ';
		$_POST['policy']           = CustomFieldPolicy::SHARE;

		$this->ui->handle_form_submission();

		$this->assertEmpty( $this->registry->get_all_policies() );
	}

	/**
	 * Tests delete action removes policy.
	 */
	public function test_handle_form_submission_deletes_policy(): void {
		$this->registry->set_policy( '_temp_key', CustomFieldPolicy::TRANSLATE );
		$this->registry->persist();

		$_GET['page']     = CustomFieldsSettingsUi::PAGE_SLUG;
		$_GET['action']   = 'delete';
		$_GET['meta_key'] = '_temp_key';

		$this->ui->handle_form_submission();

		$this->assertSame( CustomFieldPolicy::IGNORE, $this->registry->get_policy( '_temp_key' ) );
		$this->assertArrayNotHasKey( '_temp_key', $this->registry->get_all_policies() );
	}

	/**
	 * Tests rendering output contains essential elements.
	 */
	public function test_render_page_outputs_html(): void {
		$this->registry->set_policy( '_sku', CustomFieldPolicy::SHARE );

		ob_start();
		$this->ui->render_page();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'TF Multilingual — Políticas de Custom Fields', $output );
		$this->assertStringContainsString( '_sku', $output );
		$this->assertStringContainsString( 'Compartir (sincronizar)', $output );
		$this->assertStringContainsString( 'Regla Soberana de Adopción Progresiva', $output );
	}
}
