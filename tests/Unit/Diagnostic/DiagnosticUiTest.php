<?php
/**
 * Diagnostic UI Unit Tests.
 *
 * @package TF\Multilingual\Tests\Unit\Diagnostic
 */

declare( strict_types=1 );

namespace TF\Multilingual\Tests\Unit\Diagnostic;

use PHPUnit\Framework\TestCase;
use TF\Multilingual\Admin\DiagnosticUi;
use TF\Multilingual\Diagnostic\DiagnosticService;
use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Domain\Language\SettingsRepository;
use TF\Multilingual\Domain\Media\MediaTranslationRepository;
use TF\Multilingual\Domain\Navigation\NavMenuLocationRepository;
use TF\Multilingual\Domain\Strings\StringRepository;
use TF\Multilingual\Domain\Translation\ContentTranslationResolver;
use TF\Multilingual\Domain\Translation\TranslationGroupRepository;
use TF\Multilingual\Tests\Doubles\TestableWpdb;

/**
 * Class DiagnosticUiTest
 */
class DiagnosticUiTest extends TestCase {

	/**
	 * Database double.
	 *
	 * @var TestableWpdb
	 */
	private TestableWpdb $db;

	/**
	 * Diagnostic service double.
	 *
	 * @var DiagnosticService
	 */
	private DiagnosticService $service;

	/**
	 * Diagnostic UI under test.
	 *
	 * @var DiagnosticUi
	 */
	private DiagnosticUi $ui;

	/**
	 * Sets up test fixtures.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->db        = new TestableWpdb();
		$GLOBALS['wpdb'] = $this->db;

		$settings_repo = $this->createMock( SettingsRepository::class );
		$settings_repo->method( 'load' )->willReturn(
			array(
				'languages'        => array(
					'es' => array(
						'code'        => 'es',
						'locale'      => 'es_ES',
						'name'        => 'Spanish',
						'native_name' => 'Español',
						'active'      => true,
						'is_default'  => true,
						'order'       => 1,
					),
				),
				'default_language' => 'es',
			)
		);

		$registry   = new LanguageRegistry( $settings_repo );
		$group_repo = new TranslationGroupRepository( $this->db );
		$media_repo = new MediaTranslationRepository( $this->db );
		$str_repo   = new StringRepository( $this->db );
		$resolver   = new ContentTranslationResolver( $group_repo, $registry );
		$nav_repo   = new NavMenuLocationRepository( $settings_repo, $registry, $resolver );

		$this->service = new DiagnosticService(
			$registry,
			$settings_repo,
			$group_repo,
			$media_repo,
			$str_repo,
			$nav_repo,
			$this->db
		);

		$this->ui = new DiagnosticUi( $this->service );
	}

	/**
	 * Tests register_site_status_tests adds all 3 tests to Site Health.
	 */
	public function test_register_site_status_tests(): void {
		$tests  = array( 'direct' => array() );
		$result = $this->ui->register_site_status_tests( $tests );

		$this->assertArrayHasKey( 'tfml_tables_integrity', $result['direct'] );
		$this->assertArrayHasKey( 'tfml_default_language', $result['direct'] );
		$this->assertArrayHasKey( 'tfml_relations_integrity', $result['direct'] );
	}

	/**
	 * Tests Site Health callback for default language.
	 */
	public function test_site_health_default_language(): void {
		$res = $this->ui->test_default_language();

		$this->assertSame( 'good', $res['status'] );
		$this->assertSame( 'tfml_default_language', $res['test'] );
		$this->assertStringContainsString( 'ES', $res['label'] );
	}

	/**
	 * Tests Site Health callback for relations integrity.
	 */
	public function test_site_health_relations_integrity(): void {
		$res = $this->ui->test_relations_integrity();

		$this->assertSame( 'good', $res['status'] );
		$this->assertSame( 'tfml_relations_integrity', $res['test'] );
	}

	/**
	 * Tests render_page when user has manage_options capability.
	 */
	public function test_render_page_authorized(): void {
		$GLOBALS['wp_test_caps']['manage_options'] = true;

		ob_start();
		$this->ui->render_page();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Diagnóstico del Sistema', $output );
		$this->assertStringContainsString( 'Entorno de Ejecución', $output );
		$this->assertStringContainsString( 'Tablas Maestras SQL', $output );
		$this->assertStringContainsString( 'Catálogo de Idiomas', $output );
		$this->assertStringContainsString( 'Integridad Relacional', $output );
	}
}
