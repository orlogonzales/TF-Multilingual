<?php
/**
 * Diagnostic Service Unit Tests.
 *
 * @package TF\Multilingual\Tests\Unit\Diagnostic
 */

declare( strict_types=1 );

namespace TF\Multilingual\Tests\Unit\Diagnostic;

use PHPUnit\Framework\TestCase;
use TF\Multilingual\Core\Plugin;
use TF\Multilingual\Diagnostic\DiagnosticService;
use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Domain\Language\SettingsRepository;
use TF\Multilingual\Domain\Media\MediaTranslationRepository;
use TF\Multilingual\Domain\Navigation\NavMenuLocationRepository;
use TF\Multilingual\Domain\Strings\StringRepository;
use TF\Multilingual\Domain\Translation\ContentTranslationResolver;
use TF\Multilingual\Domain\Translation\TranslationGroupRepository;
use TF\Multilingual\Infrastructure\Persistence\SchemaManager;
use TF\Multilingual\Tests\Doubles\TestableWpdb;

/**
 * Class DiagnosticServiceTest
 */
class DiagnosticServiceTest extends TestCase {

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
	 * Diagnostic service under test.
	 *
	 * @var DiagnosticService
	 */
	private DiagnosticService $service;

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
					'en' => array(
						'code'        => 'en',
						'locale'      => 'en_US',
						'name'        => 'English',
						'native_name' => 'English',
						'active'      => true,
						'is_default'  => false,
						'order'       => 2,
					),
				),
				'default_language' => 'es',
			)
		);

		$this->language_registry = new LanguageRegistry( $settings_repo );

		$group_repo  = new TranslationGroupRepository( $this->db );
		$media_repo  = new MediaTranslationRepository( $this->db );
		$string_repo = new StringRepository( $this->db );
		$resolver    = new ContentTranslationResolver( $group_repo, $this->language_registry );
		$nav_repo    = new NavMenuLocationRepository( $settings_repo, $this->language_registry, $resolver );

		$this->service = new DiagnosticService(
			$this->language_registry,
			$settings_repo,
			$group_repo,
			$media_repo,
			$string_repo,
			$nav_repo,
			$this->db
		);
	}

	/**
	 * Tests get_environment_report.
	 */
	public function test_get_environment_report(): void {
		$report = $this->service->get_environment_report();

		$this->assertSame( Plugin::VERSION, $report['plugin_version'] );
		$this->assertSame( PHP_VERSION, $report['php_version'] );
		$this->assertTrue( $report['php_compatible'] );
		$this->assertTrue( $report['wordpress_compatible'] );
		$this->assertArrayHasKey( 'mbstring', $report['extensions'] );
		$this->assertArrayHasKey( 'json', $report['extensions'] );
		$this->assertArrayHasKey( 'hash', $report['extensions'] );
		$this->assertTrue( $report['all_extensions_loaded'] );
		$this->assertSame( 'good', $report['status'] );
	}

	/**
	 * Tests get_tables_report.
	 */
	public function test_get_tables_report(): void {
		$report = $this->service->get_tables_report();

		$this->assertSame( SchemaManager::SCHEMA_VERSION, $report['expected_version'] );
		$this->assertArrayHasKey( 'groups', $report['tables'] );
		$this->assertArrayHasKey( 'group_elements', $report['tables'] );
		$this->assertArrayHasKey( 'media_translations', $report['tables'] );
		$this->assertArrayHasKey( 'strings', $report['tables'] );
		$this->assertArrayHasKey( 'string_translations', $report['tables'] );
	}

	/**
	 * Tests get_languages_report.
	 */
	public function test_get_languages_report(): void {
		$report = $this->service->get_languages_report();

		$this->assertTrue( $report['is_configured'] );
		$this->assertSame( 'es', $report['default_language'] );
		$this->assertTrue( $report['default_is_active'] );
		$this->assertSame( 2, $report['total_languages'] );
		$this->assertContains( 'es', $report['active_languages'] );
		$this->assertContains( 'en', $report['active_languages'] );
		$this->assertSame( 'good', $report['status'] );
	}

	/**
	 * Tests get_relations_integrity_report.
	 */
	public function test_get_relations_integrity_report(): void {
		$report = $this->service->get_relations_integrity_report();

		$this->assertSame( 0, $report['total_groups'] );
		$this->assertSame( 0, $report['total_elements'] );
		$this->assertSame( 0, $report['empty_groups_count'] );
		$this->assertSame( 0, $report['duplicate_languages_count'] );
		$this->assertSame( 0, $report['duplicate_elements_count'] );
		$this->assertSame( 0, $report['orphaned_posts_count'] );
		$this->assertSame( 0, $report['orphaned_terms_count'] );
		$this->assertSame( 0, $report['orphaned_canonical_count'] );
		$this->assertSame( 'good', $report['status'] );
	}

	/**
	 * Tests get_rewrites_report.
	 */
	public function test_get_rewrites_report(): void {
		$report = $this->service->get_rewrites_report();

		$this->assertArrayHasKey( 'using_pretty_permalinks', $report );
		$this->assertArrayHasKey( 'permalink_structure', $report );
		$this->assertContains( 'en', $report['active_prefixes'] );
	}

	/**
	 * Tests get_modules_report.
	 */
	public function test_get_modules_report(): void {
		$report = $this->service->get_modules_report();

		$this->assertTrue( $report['media']['enabled'] );
		$this->assertTrue( $report['strings']['enabled'] );
		$this->assertTrue( $report['menus']['enabled'] );
		$this->assertTrue( $report['seo']['enabled'] );
		$this->assertTrue( $report['rest']['enabled'] );
		$this->assertSame( 'tf-multilingual/v1', $report['rest']['namespace'] );
		$this->assertSame( 'good', $report['status'] );
	}

	/**
	 * Tests run_full_diagnostic.
	 */
	public function test_run_full_diagnostic(): void {
		$report = $this->service->run_full_diagnostic();

		$this->assertArrayHasKey( 'timestamp', $report );
		$this->assertArrayHasKey( 'overall_status', $report );
		$this->assertArrayHasKey( 'summary', $report );
		$this->assertSame( 6, $report['summary']['total'] );
		$this->assertArrayHasKey( 'sections', $report );
		$this->assertArrayHasKey( 'environment', $report['sections'] );
		$this->assertArrayHasKey( 'tables', $report['sections'] );
		$this->assertArrayHasKey( 'languages', $report['sections'] );
		$this->assertArrayHasKey( 'relations', $report['sections'] );
		$this->assertArrayHasKey( 'rewrites', $report['sections'] );
		$this->assertArrayHasKey( 'modules', $report['sections'] );
	}
}
