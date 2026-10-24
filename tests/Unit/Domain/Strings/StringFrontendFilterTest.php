<?php
/**
 * String Frontend Filter Test.
 *
 * @package TF\Multilingual\Tests\Unit\Domain\Strings
 */

declare( strict_types=1 );

namespace TF\Multilingual\Tests\Unit\Domain\Strings;

use PHPUnit\Framework\TestCase;
use TF\Multilingual\Domain\Language\Language;
use TF\Multilingual\Domain\Language\LanguageRegistry;
use TF\Multilingual\Domain\Language\SettingsRepository;
use TF\Multilingual\Domain\Strings\StringFrontendFilter;
use TF\Multilingual\Domain\Strings\StringRepository;
use TF\Multilingual\Routing\CurrentLanguageResolver;

/**
 * Class StringFrontendFilterTest
 */
class StringFrontendFilterTest extends TestCase {

	/**
	 * Language registry.
	 *
	 * @var LanguageRegistry
	 */
	private LanguageRegistry $registry;

	/**
	 * Current language resolver mock.
	 *
	 * @var CurrentLanguageResolver|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $current_lang_resolver;

	/**
	 * String repository mock.
	 *
	 * @var StringRepository|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $string_repo;

	/**
	 * Setup.
	 */
	protected function setUp(): void {
		parent::setUp();

		$settings_repo  = new SettingsRepository();
		$this->registry = new LanguageRegistry( $settings_repo );

		$es = new Language( 'es', 'es_ES', 'Español', 'Spanish', true, 1 );
		$en = new Language( 'en', 'en_US', 'English', 'English', true, 2 );
		$fr = new Language( 'fr', 'fr_FR', 'Français', 'French', false, 3 ); // Inactivo.

		$this->registry->add_language( $es, true );
		$this->registry->add_language( $en, false );
		$this->registry->add_language( $fr, false );

		$this->current_lang_resolver = $this->createMock( CurrentLanguageResolver::class );
		$this->string_repo           = $this->createMock( StringRepository::class );
	}

	/**
	 * Test: En idioma predeterminado (español), filtrar_salida_buffer retorna el HTML intacto sin procesar.
	 */
	public function test_idioma_predeterminado_retorna_html_intacto(): void {
		$this->current_lang_resolver->method( 'get_current_language' )->willReturn( 'es' );

		$this->string_repo->expects( $this->never() )
			->method( 'get_translation_pairs_by_language' );

		$filter = new StringFrontendFilter(
			$this->string_repo,
			$this->registry,
			$this->current_lang_resolver
		);

		$html_original = '<footer><h4>¿Como ponerse en contaco?</h4><p>Todos los derechos reservados</p></footer>';
		$resultado     = $filter->filtrar_salida_buffer( $html_original );

		$this->assertSame( $html_original, $resultado );
	}

	/**
	 * Test: En idioma secundario (inglés), filtrar_salida_buffer reemplaza las cadenas registradas contextualmente.
	 */
	public function test_idioma_secundario_reemplaza_cadenas_correctamente(): void {
		$this->current_lang_resolver->method( 'get_current_language' )->willReturn( 'en' );

		$this->string_repo->method( 'get_translation_pairs_by_language' )
			->with( 'en', array( 'ecoCMS', 'theme', 'default' ) )
			->willReturn(
				array(
					'¿Como ponerse en contaco?'     => 'How to get in touch?',
					'Todos los derechos reservados' => 'All rights reserved',
					'Desarrollado por:'             => 'Developed by:',
				)
			);

		$filter = new StringFrontendFilter(
			$this->string_repo,
			$this->registry,
			$this->current_lang_resolver
		);

		$html_original = '<footer><h4>¿Como ponerse en contaco?</h4><p>Todos los derechos reservados | <strong>Desarrollado por:</strong> OG</p></footer>';
		$resultado     = $filter->filtrar_salida_buffer( $html_original );

		$this->assertStringContainsString( 'How to get in touch?', $resultado );
		$this->assertStringContainsString( 'All rights reserved', $resultado );
		$this->assertStringContainsString( 'Developed by:', $resultado );
		$this->assertStringNotContainsString( '¿Como ponerse en contaco?', $resultado );
		$this->assertStringNotContainsString( 'Todos los derechos reservados', $resultado );
	}

	/**
	 * Test: Bloques opacos (scripts, styles, SVG, comentarios) permanecen intactos (DT-TFML-093).
	 */
	public function test_bloques_opacos_permanecen_intactos(): void {
		$this->current_lang_resolver->method( 'get_current_language' )->willReturn( 'en' );

		$this->string_repo->method( 'get_translation_pairs_by_language' )
			->willReturn(
				array(
					'contacto'                      => 'contact',
					'Todos los derechos reservados' => 'All rights reserved',
				)
			);

		$filter = new StringFrontendFilter(
			$this->string_repo,
			$this->registry,
			$this->current_lang_resolver
		);

		$html = '<script>var contacto = "contacto";</script><style>.contacto { color: red; }</style><svg><text>contacto</text></svg><!-- comentario contacto -->';
		$res  = $filter->filtrar_salida_buffer( $html );

		$this->assertStringContainsString( 'var contacto = "contacto";', $res );
		$this->assertStringContainsString( '.contacto { color: red; }', $res );
		$this->assertStringContainsString( '<svg><text>contacto</text></svg>', $res );
		$this->assertStringContainsString( '<!-- comentario contacto -->', $res );
		$this->assertStringNotContainsString( 'var contact = "contact";', $res );
	}

	/**
	 * Test: Atributos técnicos no son alterados mientras que atributos permitidos sí se localizan (DT-TFML-093).
	 */
	public function test_atributos_tecnicos_intactos_y_permitidos_localizados(): void {
		$this->current_lang_resolver->method( 'get_current_language' )->willReturn( 'en' );

		$this->string_repo->method( 'get_translation_pairs_by_language' )
			->willReturn(
				array(
					'contacto'          => 'contact',
					'Teléfono Contacto' => 'Contact Phone',
				)
			);

		$filter = new StringFrontendFilter(
			$this->string_repo,
			$this->registry,
			$this->current_lang_resolver
		);

		$html = '<div id="box-contacto" class="widget-contacto" data-action="/contacto" style="background: url(/contacto.png)">'
			. '<a href="/contacto/" class="btn-contacto" title="Teléfono Contacto" aria-label="Teléfono Contacto">'
			. 'Teléfono Contacto'
			. '</a>'
			. '<img src="/images/contacto.png" alt="Teléfono Contacto">'
			. '</div>';

		$res = $filter->filtrar_salida_buffer( $html );

		// Atributos técnicos intactos
		$this->assertStringContainsString( 'id="box-contacto"', $res );
		$this->assertStringContainsString( 'class="widget-contacto"', $res );
		$this->assertStringContainsString( 'data-action="/contacto"', $res );
		$this->assertStringContainsString( 'href="/contacto/"', $res );
		$this->assertStringContainsString( 'class="btn-contacto"', $res );
		$this->assertStringContainsString( 'src="/images/contacto.png"', $res );

		// Atributos permitidos localizados
		$this->assertStringContainsString( 'title="Contact Phone"', $res );
		$this->assertStringContainsString( 'aria-label="Contact Phone"', $res );
		$this->assertStringContainsString( 'alt="Contact Phone"', $res );

		// Nodo de texto visible localizado
		$this->assertStringContainsString( '>Contact Phone</a>', $res );
	}

	/**
	 * Test: Búfer vacío o en blanco retorna tal cual sin llamadas a base de datos.
	 */
	public function test_buffer_vacio_retorna_inmediatamente(): void {
		$this->string_repo->expects( $this->never() )
			->method( 'get_translation_pairs_by_language' );

		$filter = new StringFrontendFilter(
			$this->string_repo,
			$this->registry,
			$this->current_lang_resolver
		);

		$this->assertSame( '', $filter->filtrar_salida_buffer( '' ) );
		$this->assertSame( '   ', $filter->filtrar_salida_buffer( '   ' ) );
	}
}
