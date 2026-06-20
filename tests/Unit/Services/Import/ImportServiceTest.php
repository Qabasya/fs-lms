<?php

declare( strict_types=1 );

namespace Unit\Services\Import;

use Generator;
use Inc\Contracts\LogEventDispatcherInterface;
use Inc\DTO\Import\ImportRowResultDTO;
use Inc\Enums\Log\LogEvent;
use Inc\Services\Import\CsvParseService;
use Inc\Services\Import\ImportService;
use Inc\Services\Import\StudentRowImporter;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ImportServiceTest extends TestCase {

	private CsvParseService $parser;
	private StudentRowImporter $importer;
	private LogEventDispatcherInterface $logEvents;
	private ImportService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->parser    = $this->createMock( CsvParseService::class );
		$this->importer  = $this->createMock( StudentRowImporter::class );
		$this->logEvents = $this->createMock( LogEventDispatcherInterface::class );
		$this->importer->method( 'requiredHeaders' )->willReturn( array( 'Фамилия', 'Имя' ) );

		$this->service = new ImportService( $this->parser, $this->importer, $this->logEvents );
	}

	/** @param array<int, array<string,string>> $rows */
	private function generatorFrom( array $rows ): Generator {
		foreach ( $rows as $row ) {
			yield $row;
		}
	}

	public function testCountsCreatedAndSkipped(): void {
		$this->parser->method( 'parse' )->willReturn(
			$this->generatorFrom( array( array( 'Фамилия' => 'A' ), array( 'Фамилия' => 'B' ) ) )
		);
		$this->importer->method( 'import' )->willReturnOnConsecutiveCalls(
			ImportRowResultDTO::created(),
			ImportRowResultDTO::skipped(),
		);

		$report = $this->service->run( 'math', '2024', '/tmp/x.csv' );

		$this->assertSame( 1, $report->created );
		$this->assertSame( 1, $report->skipped );
		$this->assertSame( array(), $report->errors );
	}

	public function testRowErrorDoesNotStopFile(): void {
		$this->parser->method( 'parse' )->willReturn(
			$this->generatorFrom( array( array( 'Фамилия' => 'A' ), array( 'Фамилия' => 'B' ) ) )
		);

		$calls = 0;
		$this->importer->method( 'import' )->willReturnCallback(
			function () use ( &$calls ): ImportRowResultDTO {
				++$calls;
				if ( 1 === $calls ) {
					throw new InvalidArgumentException( 'битая строка' );
				}
				return ImportRowResultDTO::created();
			}
		);

		$report = $this->service->run( 'math', '2024', '/tmp/x.csv' );

		$this->assertSame( 1, $report->created );
		$this->assertArrayHasKey( 1, $report->errors );
		$this->assertSame( 'битая строка', $report->errors[1] );
	}

	public function testDryRunDoesNotDispatchSummary(): void {
		$this->parser->method( 'parse' )->willReturn(
			$this->generatorFrom( array( array( 'Фамилия' => 'A' ) ) )
		);
		$this->importer->method( 'import' )->willReturn( ImportRowResultDTO::created() );

		$this->logEvents->expects( $this->never() )->method( 'dispatch' );

		$report = $this->service->run( 'math', '2024', '/tmp/x.csv', true );

		$this->assertTrue( $report->dryRun );
	}

	public function testDispatchesSummaryWhenNotDryRun(): void {
		$this->parser->method( 'parse' )->willReturn(
			$this->generatorFrom( array( array( 'Фамилия' => 'A' ) ) )
		);
		$this->importer->method( 'import' )->willReturn( ImportRowResultDTO::created() );

		$this->logEvents->expects( $this->once() )
			->method( 'dispatch' )
			->with( LogEvent::CsvImported, $this->anything() );

		$this->service->run( 'math', '2024', '/tmp/x.csv' );
	}

	public function testEmptyFileThrows(): void {
		$this->parser->method( 'parse' )->willReturn( $this->generatorFrom( array() ) );

		$this->expectException( InvalidArgumentException::class );

		$this->service->run( 'math', '2024', '/tmp/x.csv' );
	}
}
