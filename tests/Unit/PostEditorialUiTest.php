<?php
/**
 * Post Editorial UI Component Unit Tests.
 *
 * @package TF\Multilingual\Tests\Unit
 */

declare( strict_types=1 );

namespace TF\Multilingual\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TF\Multilingual\Admin\PostEditorialUi;
use TF\Multilingual\Domain\Language\Language;
use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Domain\Language\SettingsRepository;
use TF\Multilingual\Domain\Translation\TranslationStatus;
use TF\Multilingual\Editorial\Exceptions\EditorialConflictException;
use TF\Multilingual\Editorial\Exceptions\EditorialPermissionException;
use TF\Multilingual\Editorial\Exceptions\EditorialValidationException;
use TF\Multilingual\Editorial\TranslationEditorialService;
use TF\Multilingual\Tests\Doubles\TestableWpdb;
use WP_Post;

/**
 * Class PostEditorialUiTest
 */
class PostEditorialUiTest extends TestCase {

	/**
	 * Database double.
	 *
	 * @var TestableWpdb
	 */
	private TestableWpdb $db;

	/**
	 * Language registry.
	 *
	 * @var LanguageRegistry
	 */
	private LanguageRegistry $language_registry;

	/**
	 * Editorial service mock.
	 *
	 * @var TranslationEditorialService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $editorial_service;

	/**
	 * Component under test.
	 *
	 * @var PostEditorialUi
	 */
	private PostEditorialUi $ui;

	/**
	 * Sets up test fixtures.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->db        = new TestableWpdb();
		$GLOBALS['wpdb'] = $this->db;

		$repo = $this->createMock( SettingsRepository::class );
		$repo->method( 'load' )->willReturn(
			array(
				'languages'        => array(
					'es' => array(
						'code'        => 'es',
						'locale'      => 'es_ES',
						'name'        => 'Spanish',
						'native_name' => 'Español',
						'active'      => true,
						'order'       => 1,
					),
					'en' => array(
						'code'        => 'en',
						'locale'      => 'en_US',
						'name'        => 'English',
						'native_name' => 'English',
						'active'      => true,
						'order'       => 2,
					),
					'pt' => array(
						'code'        => 'pt',
						'locale'      => 'pt_PT',
						'name'        => 'Portuguese',
						'native_name' => 'Português',
						'active'      => true,
						'order'       => 3,
					),
				),
				'default_language' => 'es',
			)
		);

		$this->language_registry = new LanguageRegistry( $repo );
		$this->editorial_service = $this->createMock( TranslationEditorialService::class );
		$this->ui                = new PostEditorialUi( $this->editorial_service, $this->language_registry );

		$GLOBALS['wp_test_caps'] = array();
		$_POST                   = array();
		$_GET                    = array();
		$_REQUEST                = array();
	}

	/**
	 * Tears down test fixtures.
	 */
	protected function tearDown(): void {
		$GLOBALS['wp_test_caps'] = array();
		$_POST                   = array();
		$_GET                    = array();
		$_REQUEST                = array();
		parent::tearDown();
	}

	/**
	 * Tests nonces and constants definitions.
	 */
	public function test_constants_and_nonces(): void {
		$this->assertSame( 'tfml_save_post_editorial', PostEditorialUi::NONCE_ACTION_SAVE );
		$this->assertSame( 'tfml_create_post_translation_', PostEditorialUi::NONCE_ACTION_CREATE );
		$this->assertSame( 'tfml_link_post_translation_', PostEditorialUi::NONCE_ACTION_LINK );
		$this->assertSame( 'tfml_unlink_post_translation_', PostEditorialUi::NONCE_ACTION_UNLINK );
		$this->assertSame( 'tfml_editorial_ajax', PostEditorialUi::NONCE_ACTION_AJAX );
	}

	/**
	 * Tests render_meta_box for an unmanaged post.
	 */
	public function test_render_meta_box_unmanaged_post(): void {
		$post            = new WP_Post();
		$post->ID        = 501;
		$post->post_type = 'page';

		$this->editorial_service->method( 'get_editorial_data' )->willReturn(
			array(
				'is_managed'       => false,
				'active_languages' => $this->language_registry->active(),
			)
		);

		ob_start();
		$this->ui->render_meta_box( $post );
		$html = ob_get_clean();

		$this->assertStringContainsString( 'tfml_editorial_nonce', $html );
		$this->assertStringContainsString( 'tfml_editorial_ajax_nonce', $html );
		$this->assertStringContainsString( 'This content is not yet managed by TF Multilingual.', $html );
		$this->assertStringContainsString( 'name="tfml_assign_language"', $html );
		$this->assertStringContainsString( 'value="es"', $html );
		$this->assertStringContainsString( 'value="en"', $html );
		$this->assertStringContainsString( 'value="pt"', $html );
	}

	/**
	 * Tests render_meta_box for a managed post with existing translations, unlink buttons, and link cards.
	 */
	public function test_render_meta_box_managed_post_with_link_tools(): void {
		$post            = new WP_Post();
		$post->ID        = 4976;
		$post->post_type = 'page';

		$this->editorial_service->method( 'get_editorial_data' )->willReturn(
			array(
				'is_managed'            => true,
				'current_language'      => 'es',
				'current_language_name' => 'Español',
				'translations'          => array(
					'es' => array(
						'element_id'    => 4976,
						'language_code' => 'es',
						'language_name' => 'Español',
						'is_current'    => true,
						'is_active'     => true,
						'edit_url'      => 'post.php?post=4976&action=edit',
						'status'        => TranslationStatus::UPDATED,
					),
					'en' => array(
						'element_id'    => 8339,
						'language_code' => 'en',
						'language_name' => 'English',
						'is_current'    => false,
						'is_active'     => true,
						'edit_url'      => 'post.php?post=8339&action=edit',
						'status'        => TranslationStatus::UPDATED,
					),
				),
				'missing_languages'     => array(
					'pt' => array(
						'language_code' => 'pt',
						'language_name' => 'Português',
						'status'        => TranslationStatus::UNTRANSLATED,
					),
				),
				'inactive_translations' => array(),
			)
		);

		ob_start();
		$this->ui->render_meta_box( $post );
		$html = ob_get_clean();

		// 1. Current language info.
		$this->assertStringContainsString( 'Español (es)', $html );

		// 2. Existing translations and Edit link.
		$this->assertStringContainsString( 'post.php?post=8339&action=edit', $html );

		// 3. Unlink button for sibling translation with POST form, nonce, and dashicon.
		$this->assertStringContainsString( 'tfml-unlink-btn', $html );
		$this->assertStringContainsString( 'dashicons-editor-unlink', $html );
		$this->assertStringContainsString( 'tfml-unlink-form', $html );
		$this->assertStringContainsString( 'value="tfml_unlink_post_translation"', $html );
		$this->assertStringContainsString( 'value="8339"', $html );
		$this->assertStringContainsString( 'value="4976"', $html );

		// 4. Missing language: Português.
		$this->assertStringContainsString( 'Português (PT)', $html );

		// 5. Tabs: Create New vs Link Existing.
		$this->assertStringContainsString( 'tfml-tab-create', $html );
		$this->assertStringContainsString( 'tfml-tab-link', $html );
		$this->assertStringContainsString( 'Crear nueva', $html );
		$this->assertStringContainsString( 'Vincular existente', $html );

		// 6. Create panel with target language pt.
		$this->assertStringContainsString( 'tfml-create-panel-pt', $html );
		$this->assertStringContainsString( 'value="tfml_create_post_translation"', $html );

		// 7. Link panel with candidate search input, target id input, preview card, and submit button.
		$this->assertStringContainsString( 'tfml-link-panel-pt', $html );
		$this->assertStringContainsString( 'value="tfml_link_post_translation"', $html );
		$this->assertStringContainsString( 'tfml-candidate-search-input', $html );
		$this->assertStringContainsString( 'data-lang="pt"', $html );
		$this->assertStringContainsString( 'data-subtype="page"', $html );
		$this->assertStringContainsString( 'tfml-target-id-input', $html );
		$this->assertStringContainsString( 'tfml-candidate-preview-box', $html );
		$this->assertStringContainsString( 'tfml-link-submit-btn', $html );
		$this->assertStringContainsString( 'Vincular como traducción en Português', $html );
	}

	/**
	 * Tests handle_unlink_translation strictly rejects GET requests with 405.
	 */
	public function test_handle_unlink_rejects_get_request(): void {
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_POST                     = array();

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionCode( 405 );
		$this->ui->handle_unlink_translation();
	}

	/**
	 * Tests handle_unlink_translation rejects invalid or missing nonce with 403.
	 */
	public function test_handle_unlink_rejects_invalid_nonce(): void {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array(
			'element_id'        => 8339,
			'source_post_id'    => 4976,
			'tfml_unlink_nonce' => 'invalid_nonce',
		);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionCode( 403 );
		$this->ui->handle_unlink_translation();
	}

	/**
	 * Tests handle_unlink_translation rejects unauthorized users with 403.
	 */
	public function test_handle_unlink_rejects_unauthorized_user(): void {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array(
			'element_id'        => 8339,
			'source_post_id'    => 4976,
			'tfml_unlink_nonce' => wp_create_nonce( PostEditorialUi::NONCE_ACTION_UNLINK . 8339 ),
		);

		$GLOBALS['wp_test_caps']['edit_post:8339'] = false;
		$GLOBALS['wp_test_caps']['edit_post:4976'] = true;

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionCode( 403 );
		$this->ui->handle_unlink_translation();
	}

	/**
	 * Tests handle_unlink_translation rejects when user lacks permissions on source context with 403.
	 */
	public function test_handle_unlink_rejects_unauthorized_source_context(): void {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array(
			'element_id'        => 8339,
			'source_post_id'    => 4976,
			'tfml_unlink_nonce' => wp_create_nonce( PostEditorialUi::NONCE_ACTION_UNLINK . 8339 ),
		);

		$GLOBALS['wp_test_caps']['edit_post:8339'] = true;
		$GLOBALS['wp_test_caps']['edit_post:4976'] = false;

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionCode( 403 );
		$this->ui->handle_unlink_translation();
	}

	/**
	 * Tests handle_unlink_translation successfully unlinks via POST and redirects with notice.
	 */
	public function test_handle_unlink_success_via_post(): void {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array(
			'element_id'        => 8339,
			'source_post_id'    => 4976,
			'tfml_unlink_nonce' => wp_create_nonce( PostEditorialUi::NONCE_ACTION_UNLINK . 8339 ),
		);

		$GLOBALS['wp_test_caps']['edit_post:8339'] = true;
		$GLOBALS['wp_test_caps']['edit_post:4976'] = true;

		$this->editorial_service
			->expects( $this->once() )
			->method( 'unlink_translation' )
			->with( 'post', 8339, null, 4976 )
			->willReturn( true );

		$this->ui->handle_unlink_translation();

		$this->assertNotEmpty( $GLOBALS['wp_test_last_redirect'] );
		$this->assertStringContainsString( 'tfml_success', $GLOBALS['wp_test_last_redirect']['location'] );
	}

	/**
	 * Tests handle_create_translation strictly rejects GET requests with 405.
	 */
	public function test_handle_create_rejects_get_request(): void {
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_POST                     = array();

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionCode( 405 );
		$this->ui->handle_create_translation();
	}

	/**
	 * Tests handle_link_translation strictly rejects GET requests with 405.
	 */
	public function test_handle_link_rejects_get_request(): void {
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_POST                     = array();

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionCode( 405 );
		$this->ui->handle_link_translation();
	}

	/**
	 * Tests render_admin_notices with both error and success messages.
	 */
	public function test_render_admin_notices(): void {
		$_GET['tfml_error'] = 'Ha ocurrido un error en la vinculación';
		ob_start();
		$this->ui->render_admin_notices();
		$html_err = ob_get_clean();

		$this->assertStringContainsString( 'notice-error', $html_err );
		$this->assertStringContainsString( 'Ha ocurrido un error en la vinculación', $html_err );

		unset( $_GET['tfml_error'] );
		$_GET['tfml_success'] = 'Traducción vinculada exitosamente';
		ob_start();
		$this->ui->render_admin_notices();
		$html_succ = ob_get_clean();

		$this->assertStringContainsString( 'notice-success', $html_succ );
		$this->assertStringContainsString( 'Traducción vinculada exitosamente', $html_succ );
	}
}
