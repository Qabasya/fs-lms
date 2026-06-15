<?php

declare( strict_types=1 );

namespace Unit\Services\Import;

use Inc\Services\Import\CsvParseService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class CsvParseServiceTest extends TestCase {

	private CsvParseService $service;

	/** @var string[] Пути временных файлов для очистки */
	private array $tmpFiles = array();

	protected function setUp(): void {
		parent::setUp();
		$this->service = new CsvParseService();
	}

	protected function tearDown(): void {
		foreach ( $this->tmpFiles as $path ) {
			if ( is_file( $path ) ) {
				unlink( $path );
			}
		}
		$this->tmpFiles = array();
		parent::tearDown();
	}

	private function tempFile( string $content ): string {
		$path = tempnam( sys_get_temp_dir(), 'fs_csv_' );
		file_put_contents( $path, $content );
		$this->tmpFiles[] = $path;
		return $path;
	}

	public function testParsesSemicolonWithBom(): void {
		$path = $this->tempFile( "\xEF\xBB\xBFФамилия;Имя\r\nИванов;Иван\r\n" );

		$rows = iterator_to_array( $this->service->parse( $path ) );

		$this->assertCount( 1, $rows );
		$this->assertSame( array( 'Фамилия' => 'Иванов', 'Имя' => 'Иван' ), $rows[0] );
	}

	public function testParsesCommaDelimiter(): void {
		$path = $this->tempFile( "Фамилия,Имя\r\nПетров,Пётр\r\n" );

		$rows = iterator_to_array( $this->service->parse( $path ) );

		$this->assertSame( array( 'Фамилия' => 'Петров', 'Имя' => 'Пётр' ), $rows[0] );
	}

	public function testConvertsCp1251ToUtf8(): void {
		$utf8    = "Фамилия;Имя\nИванов;Иван\n";
		$cp1251  = mb_convert_encoding( $utf8, 'Windows-1251', 'UTF-8' );
		$path    = $this->tempFile( $cp1251 );

		$rows = iterator_to_array( $this->service->parse( $path ) );

		$this->assertSame( array( 'Фамилия' => 'Иванов', 'Имя' => 'Иван' ), $rows[0] );
	}

	public function testPadsShortRows(): void {
		$path = $this->tempFile( "A;B;C\n1;2\n" );

		$rows = iterator_to_array( $this->service->parse( $path ) );

		$this->assertSame( array( 'A' => '1', 'B' => '2', 'C' => '' ), $rows[0] );
	}

	public function testValidateHeadersPassesWhenAllPresent(): void {
		$this->expectNotToPerformAssertions();
		$this->service->validateHeaders( array( 'Фамилия', 'Имя' ), array( 'Фамилия', 'Имя', 'Группа' ) );
	}

	public function testValidateHeadersThrowsOnMissing(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Группа' );

		$this->service->validateHeaders( array( 'Фамилия', 'Группа' ), array( 'Фамилия', 'Имя' ) );
	}
}
