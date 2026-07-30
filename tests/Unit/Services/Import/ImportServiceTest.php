<?php

declare( strict_types=1 );

namespace Unit\Services\Import;

use Generator;
use Inc\Contracts\LogEventDispatcherInterface;
use Inc\Contracts\RowImporterInterface;
use Inc\DTO\Import\ImportReportDTO;
use Inc\DTO\Import\ImportRowResultDTO;
use Inc\DTO\Import\RowCredentialsDTO;
use Inc\DTO\Log\Events\EntityChangedEvent;
use Inc\Enums\Import\ImportMode;
use Inc\Enums\Log\LogEvent;
use Inc\Services\Import\CsvParseService;
use Inc\Services\Import\ImportService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ImportServiceTest extends TestCase {

	private CsvParseService $parser;
	private RowImporterInterface $importer;
	private LogEventDispatcherInterface $logEvents;
	private ImportService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->parser    = $this->createMock( CsvParseService::class );
		$this->importer  = $this->createMock( RowImporterInterface::class );
		$this->logEvents = $this->createMock( LogEventDispatcherInterface::class );
		$this->importer->method( 'requiredHeaders' )->willReturn( array( 'Фамилия', 'Имя' ) );

		$this->service = new ImportService( $this->parser, $this->logEvents );
	}

	/** @param array<int, array<string,string>> $rows */
	private function generatorFrom( array $rows ): Generator {
		foreach ( $rows as $row ) {
			yield $row;
		}
	}

	private function runImport(
		bool $dryRun = false,
		ImportMode $mode = ImportMode::Archive,
		bool $sendEmails = false
	): ImportReportDTO {
		return $this->service->run( $this->importer, $mode, $sendEmails, 'math', '2024', '/tmp/x.csv', $dryRun );
	}

	public function testCountsCreatedAndSkipped(): void {
		$this->parser->method( 'parse' )->willReturn(
			$this->generatorFrom( array( array( 'Фамилия' => 'A' ), array( 'Фамилия' => 'B' ) ) )
		);
		$this->importer->method( 'import' )->willReturnOnConsecutiveCalls(
			ImportRowResultDTO::created(),
			ImportRowResultDTO::skipped(),
		);

		$report = $this->runImport();

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

		$report = $this->runImport();

		$this->assertSame( 1, $report->created );
		$this->assertArrayHasKey( 1, $report->errors );
		$this->assertSame( 'битая строка', $report->errors[1] );
	}

	public function testProvisioningRuntimeErrorGoesToReport(): void {
		$this->parser->method( 'parse' )->willReturn(
			$this->generatorFrom( array( array( 'Фамилия' => 'A' ), array( 'Фамилия' => 'B' ) ) )
		);

		$calls = 0;
		$this->importer->method( 'import' )->willReturnCallback(
			function () use ( &$calls ): ImportRowResultDTO {
				++$calls;
				if ( 1 === $calls ) {
					throw new RuntimeException( 'Логин уже занят.' );
				}
				return ImportRowResultDTO::created();
			}
		);

		$report = $this->runImport( mode: ImportMode::Enrolled );

		$this->assertSame( 1, $report->created );
		$this->assertSame( 'Логин уже занят.', $report->errors[1] );
	}

	public function testCollectsCredentialsFromCreatedRows(): void {
		$this->parser->method( 'parse' )->willReturn(
			$this->generatorFrom( array( array( 'Фамилия' => 'A' ), array( 'Фамилия' => 'B' ) ) )
		);
		$creds = new RowCredentialsDTO( 'Иванов Иван', 'ivanov', 'p1', 'maria@example.com', 'p2' );
		$this->importer->method( 'import' )->willReturnOnConsecutiveCalls(
			ImportRowResultDTO::created( null, $creds ),
			ImportRowResultDTO::skipped(),
		);

		$report = $this->runImport( mode: ImportMode::Enrolled );

		$this->assertSame( array( $creds ), $report->credentials );
		$this->assertSame( 'ivanov', $report->toArray()['credentials'][0]['student_login'] );
	}

	public function testDryRunDoesNotDispatchSummary(): void {
		$this->parser->method( 'parse' )->willReturn(
			$this->generatorFrom( array( array( 'Фамилия' => 'A' ) ) )
		);
		$this->importer->method( 'import' )->willReturn( ImportRowResultDTO::created() );

		$this->logEvents->expects( $this->never() )->method( 'dispatch' );

		$report = $this->runImport( dryRun: true );

		$this->assertTrue( $report->dryRun );
	}

	public function testDryRunCollectsPreviewOfRows(): void {
		$this->parser->method( 'parse' )->willReturn(
			$this->generatorFrom( array( array( 'Фамилия' => 'A' ), array( 'Фамилия' => 'B' ) ) )
		);
		$this->importer->method( 'import' )->willReturnOnConsecutiveCalls(
			ImportRowResultDTO::created( 'Будет создано (dry-run).', null, 'Иванов Иван — группа «Г-1», договор № C-1' ),
			ImportRowResultDTO::skipped( 'Запись с таким договором уже существует.', 'Петров Пётр — группа «Г-1», договор № C-2' ),
		);

		$report = $this->runImport( dryRun: true );

		$this->assertCount( 2, $report->preview );
		$this->assertSame( 'Иванов Иван — группа «Г-1», договор № C-1', $report->preview[0]['label'] );
		$this->assertSame( ImportRowResultDTO::STATUS_CREATED, $report->preview[0]['status'] );
		$this->assertSame( ImportRowResultDTO::STATUS_SKIPPED, $report->preview[1]['status'] );
		$this->assertSame( $report->preview, $report->toArray()['preview'] );
	}

	public function testRealRunCollectsNoPreview(): void {
		$this->parser->method( 'parse' )->willReturn(
			$this->generatorFrom( array( array( 'Фамилия' => 'A' ) ) )
		);
		$this->importer->method( 'import' )->willReturn(
			ImportRowResultDTO::created( null, null, 'Иванов Иван — группа «Г-1», договор № C-1' )
		);

		$report = $this->runImport();

		$this->assertSame( array(), $report->preview );
	}

	public function testDispatchesSummaryWhenNotDryRun(): void {
		$this->parser->method( 'parse' )->willReturn(
			$this->generatorFrom( array( array( 'Фамилия' => 'A' ) ) )
		);
		$this->importer->method( 'import' )->willReturn( ImportRowResultDTO::created() );

		$this->logEvents->expects( $this->once() )
			->method( 'dispatch' )
			->with( LogEvent::CsvImported, $this->anything() );

		$this->runImport();
	}

	public function testSummaryTextDependsOnMode(): void {
		$this->parser->method( 'parse' )->willReturn(
			$this->generatorFrom( array( array( 'Фамилия' => 'A' ) ) )
		);
		$this->importer->method( 'import' )->willReturn( ImportRowResultDTO::created() );

		$captured = null;
		$this->logEvents->method( 'dispatch' )->willReturnCallback(
			function ( LogEvent $event, EntityChangedEvent $payload ) use ( &$captured ): void {
				$captured = $payload;
			}
		);

		$this->runImport( mode: ImportMode::Enrolled );

		$this->assertStringStartsWith( 'Зачисление учеников', $captured->oldLabel );
	}

	public function testEmptyFileThrows(): void {
		$this->parser->method( 'parse' )->willReturn( $this->generatorFrom( array() ) );

		$this->expectException( InvalidArgumentException::class );

		$this->runImport();
	}
}
