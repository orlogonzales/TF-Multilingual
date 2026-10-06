<?php
/**
 * MediaEditorialUi Unit Tests.
 *
 * @package TF\Multilingual\Tests\Unit
 */

declare( strict_types=1 );

namespace TF\Multilingual\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TF\Multilingual\Admin\MediaEditorialUi;
use TF\Multilingual\Domain\Language\Language;
use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Domain\Language\SettingsRepository;
use TF\Multilingual\Domain\Media\MediaTranslationRepository;
use TF\Multilingual\Domain\Media\MediaTranslationResolver;
use TF\Multilingual\Tests\Doubles\TestableWpdb;
use WP_Post;

/**
 * Class MediaEditorialUiTest
 */
class MediaEditorialUiTest extends TestCase {

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
	private LanguageRegistry $registry;

	/**
	 * Repository.
	 *
	 * @var MediaTranslationRepository
	 */
	private MediaTranslationRepository $repository;

	/**
	 * Resolver.
	 *
	 * @var MediaTranslationResolver
	 */
	private MediaTranslationResolver $resolver;

	/**
	 * UI under test.
	 *
	 * @var MediaEditorialUi
	 */
	private MediaEditorialUi $ui;

	/**
	 * Setup.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->db                        = new TestableWpdb();
		$GLOBALS['wp_test_meta_boxes']   = array();
		$GLOBALS['wp_test_posts']        = array();
		$GLOBALS['wp_test_postmeta']     = array();
		$GLOBALS['wp_test_caps']         = array( 'edit_post' => true );
		$GLOBALS['wp_test_valid_nonces'] = array();
		$_POST                           = array();

		$settings_repo  = new SettingsRepository();
		$this->registry = new LanguageRegistry( $settings_repo );
		$this->registry->add_language( Language::create( 'es', 'es_ES', 'Spanish', 'Español', true, 10 ), true );
		$this->registry->add_language( Language::create( 'en', 'en_US', 'English', 'English', true, 20 ) );
		$this->registry->add_language( Language::create( 'de', 'de_DE', 'German', 'Deutsch', false, 30 ) ); // Inactive.

		$this->repository = new MediaTranslationRepository( $this->db, $this->registry );
		$this->resolver   = new MediaTranslationResolver( $this->repository, $this->registry );
		$this->ui         = new MediaEditorialUi( $this->repository, $this->resolver, $this->registry );

		$p               = new WP_Post();
		$p->ID           = 300;
		$p->post_type    = 'attachment';
		$p->post_title   = 'Attachment 300 Title';
		$p->post_excerpt = 'Attachment 300 Caption';
		$p->post_content = 'Attachment 300 Desc';

		$GLOBALS['wp_test_posts'][300]                                = $p;
		$GLOBALS['wp_test_postmeta'][300]['_wp_attachment_image_alt'] = 'Attachment 300 Alt';
	}

	/**
	 * Tear down.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$GLOBALS['wp_test_meta_boxes']   = array();
		$GLOBALS['wp_test_posts']        = array();
		$GLOBALS['wp_test_postmeta']     = array();
		$GLOBALS['wp_test_caps']         = array();
		$GLOBALS['wp_test_valid_nonces'] = array();
		$_POST                           = array();
		parent::tearDown();
	}

	/**
	 * Tests meta box registration.
	 *
	 * @return void
	 */
	public function test_register_meta_box(): void {
		$this->ui->register_meta_box();

		$this->assertArrayHasKey( 'tfml_media_translations_meta_box', $GLOBALS['wp_test_meta_boxes'] );
		$box = $GLOBALS['wp_test_meta_boxes']['tfml_media_translations_meta_box'];
		$this->assertSame( 'attachment', $box['screen'] );
		$this->assertSame( 'normal', $box['context'] );
		$this->assertSame( 'high', $box['priority'] );
	}

	/**
	 * Tests rendering meta box contains tab buttons and fields.
	 *
	 * @return void
	 */
	public function test_render_meta_box(): void {
		$post            = new WP_Post();
		$post->ID        = 300;
		$post->post_type = 'attachment';

		ob_start();
		$this->ui->render_meta_box( $post );
		$output = ob_get_clean();

		// Nonce field present.
		$this->assertStringContainsString( 'name="' . MediaEditorialUi::NONCE_NAME . '"', $output );

		// Tabs for active languages (ES, EN).
		$this->assertStringContainsString( 'tfml-tab-button', $output );
		$this->assertStringContainsString( 'Spanish', $output );
		$this->assertStringContainsString( 'English', $output );
		// Inactive language (DE) should NOT have a tab.
		$this->assertStringNotContainsString( 'Deutsch', $output );

		// Field names.
		$this->assertStringContainsString( 'name="tfml_media[es][alt_text]"', $output );
		$this->assertStringContainsString( 'name="tfml_media[es][title]"', $output );
		$this->assertStringContainsString( 'name="tfml_media[en][alt_text]"', $output );
		$this->assertStringContainsString( 'name="tfml_media[en][caption]"', $output );
	}

	/**
	 * Tests save_translations aborts without valid nonce.
	 *
	 * @return void
	 */
	public function test_save_aborts_without_valid_nonce(): void {
		$_POST['tfml_media'] = array(
			'es' => array(
				'active'   => '1',
				'alt_text' => 'New Alt',
			),
		);

		$this->ui->save_translations( 300 );
		$this->assertFalse( $this->repository->exists( 300, 'es' ) );
	}

	/**
	 * Tests save_translations aborts when user lacks edit_post capability.
	 *
	 * @return void
	 */
	public function test_save_aborts_without_permission(): void {
		$GLOBALS['wp_test_caps']['edit_post']  = false;
		$_POST[ MediaEditorialUi::NONCE_NAME ] = 'valid_nonce';
		$_POST['tfml_media']                   = array(
			'es' => array(
				'active'   => '1',
				'alt_text' => 'New Alt',
			),
		);

		$this->ui->save_translations( 300 );
		$this->assertFalse( $this->repository->exists( 300, 'es' ) );
	}

	/**
	 * Tests save_translations successfully persists valid data.
	 *
	 * @return void
	 */
	public function test_save_persists_valid_translations(): void {
		$_POST[ MediaEditorialUi::NONCE_NAME ] = 'valid_nonce';
		$_POST['tfml_media']                   = array(
			'es' => array(
				'active'      => '1',
				'alt_text'    => 'Foto de los Andes',
				'title'       => 'Andes Peruanos',
				'caption'     => 'Cordillera blanca',
				'description' => 'Vista panorámica',
			),
			'en' => array(
				'active'      => '1',
				'alt_text'    => 'Andes Mountains Photo',
				'title'       => 'Peruvian Andes',
				'caption'     => 'White Mountain Range',
				'description' => 'Panoramic View',
			),
			'de' => array( // Inactive language, must be ignored.
				'active'   => '1',
				'alt_text' => 'Anden Foto',
			),
		);

		$this->ui->save_translations( 300 );

		$this->assertTrue( $this->repository->exists( 300, 'es' ) );
		$this->assertTrue( $this->repository->exists( 300, 'en' ) );
		$this->assertFalse( $this->repository->exists( 300, 'de' ) );

		$es = $this->repository->find( 300, 'es' );
		$this->assertNotNull( $es );
		$this->assertSame( 'Foto de los Andes', $es->get_alt_text() );
		$this->assertSame( 'Andes Peruanos', $es->get_title() );
		$this->assertSame( 'Cordillera blanca', $es->get_caption() );
		$this->assertSame( 'Vista panorámica', $es->get_description() );

		$en = $this->repository->find( 300, 'en' );
		$this->assertNotNull( $en );
		$this->assertSame( 'Andes Mountains Photo', $en->get_alt_text() );
		$this->assertSame( 'Peruvian Andes', $en->get_title() );
	}
}
