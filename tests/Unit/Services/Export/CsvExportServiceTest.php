<?php

declare( strict_types=1 );

namespace Tests\Unit\Services\Export;

use Inc\DTO\Export\CsvColumn;
use Inc\Services\Export\CsvExportService;
use Inc\Services\Export\OneTimeDownloadService;
use PHPUnit\Framework\TestCase;

/**
 * Защита CSV от формул (8.9.1): ячейка, которую редактор прочёл бы как формулу, получает апостроф; числа и обычный текст — нет.
 */
class CsvExportServiceTest extends TestCase {

	private CsvExportService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->service = new CsvExportService( $this->createMock( OneTimeDownloadService::class ) );
	}

	/** @return array<string, array{0: string}> */
	public static function formulaProvider(): array {
		return array(
			'равно'            => array( '=1+1' ),
			'плюс'             => array( '+7999' ),
			'минус'            => array( '-cmd' ),
			'собака'           => array( '@SUM(A1)' ),
			'после пробелов'   => array( '  =HYPERLINK("http://x")' ),
			'табуляция'        => array( "\t=1" ),
			'возврат каретки'  => array( "\r=1" ),
		);
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'formulaProvider' )]
	public function test_formula_prefixes_are_neutralized( string $value ): void {
		self::assertSame( "'" . $value, $this->service->neutralizeFormula( $value ) );
	}

	public function test_numeric_values_are_untouched(): void {
		self::assertSame( '-12.5', $this->service->neutralizeFormula( '-12.5' ) );
		self::assertSame( 42, $this->service->neutralizeFormula( 42 ) );
		self::assertSame( -3.5, $this->service->neutralizeFormula( -3.5 ) );
		self::assertSame( '42', $this->service->neutralizeFormula( '42' ) );
	}

	public function test_plain_text_is_untouched(): void {
		self::assertSame( 'Иванов Пётр', $this->service->neutralizeFormula( 'Иванов Пётр' ) );
		self::assertSame( 'a=b', $this->service->neutralizeFormula( 'a=b' ) );
		self::assertSame( '', $this->service->neutralizeFormula( '' ) );
		self::assertNull( $this->service->neutralizeFormula( null ) );
	}

	public function test_export_applies_protection_to_every_cell(): void {
		$csv = $this->service->export(
			array( array( 'name' => '=HYPERLINK("http://x")', 'score' => '12' ) ),
			array( new CsvColumn( 'ФИО', static fn ( array $r ): string => $r['name'] ), new CsvColumn( 'Балл', static fn ( array $r ): string => $r['score'] ) )
		);

		self::assertStringContainsString( "\"'=HYPERLINK(\"\"http://x\"\")\",12", $csv );
	}
}
