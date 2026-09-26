<?php

declare( strict_types=1 );

namespace Unit\Services\Print;

use Inc\Services\Print\PdfFormFiller;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Заполнение PDF-формы справки на вычет: файл остаётся формой, исходник не меняется.
 */
class PdfFormFillerTest extends TestCase {

	private const string ROOT = __DIR__ . '/../../../../';

	private string $template;
	private PdfFormFiller $filler;
	private string $out;

	protected function setUp(): void {
		$this->template = self::ROOT . 'templates/documents/tax_deduction.pdf';
		$this->filler   = new PdfFormFiller( self::ROOT . 'templates/documents/fonts/PTMono-Regular.ttf' );
		$this->out      = (string) tempnam( sys_get_temp_dir(), 'fs-pdf' );
	}

	protected function tearDown(): void {
		@unlink( $this->out );
	}

	public function test_template_exposes_all_form_fields(): void {
		$names = $this->filler->fieldNames( $this->template );

		self::assertCount( 45, $names );
		foreach ( array( 'Text4', 'Text3', 'Text7.0', 'Text13', 'Text15.0', 'Text18.0', 'Text260', 'Text30' ) as $name ) {
			self::assertContains( $name, $names );
		}
	}

	public function test_fill_appends_incremental_update_and_stays_a_form(): void {
		$original = (string) file_get_contents( $this->template );
		$filled   = $this->filler->fill( $this->template, array( 'Text7.0' => 'Новикова', 'Text4' => '17' ) );

		// Исходный документ не тронут — изменения дописаны в конец.
		self::assertStringStartsWith( $original, $filled );
		self::assertStringContainsString( '/Prev ', substr( $filled, strlen( $original ) ) );

		// Значение — строкой UTF-16BE в поле; внешний вид нарисован, NeedAppearances снят.
		$utf16 = strtoupper( bin2hex( (string) mb_convert_encoding( 'Новикова', 'UTF-16BE', 'UTF-8' ) ) );
		self::assertStringContainsString( '/V <FEFF' . $utf16 . '>', $filled );
		self::assertStringNotContainsString( '/NeedAppearances', substr( $filled, strlen( $original ) ) );

		// Результат снова читается как форма (цепочка xref через /Prev).
		file_put_contents( $this->out, $filled );
		self::assertCount( 45, $this->filler->fieldNames( $this->out ) );
	}

	public function test_unknown_field_is_rejected(): void {
		$this->expectException( RuntimeException::class );

		$this->filler->fill( $this->template, array( 'NoSuchField' => '1' ) );
	}
}
