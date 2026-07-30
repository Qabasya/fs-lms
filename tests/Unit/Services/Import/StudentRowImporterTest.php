<?php

declare( strict_types=1 );

namespace Unit\Services\Import;

use Inc\Contracts\ClockInterface;
use Inc\Contracts\LogEventDispatcherInterface;
use Inc\DTO\Enrollment\StudentRecordInputDTO;
use Inc\DTO\Import\ImportContextDTO;
use Inc\Enums\Enrollment\EnrollmentStatus;
use Inc\Enums\Import\ImportColumn;
use Inc\Enums\Log\LogEvent;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Services\Import\DocTypeResolver;
use Inc\Services\Import\ExpulsionResolver;
use Inc\Services\Import\StudentRecordWriter;
use Inc\Services\Import\StudentRowImporter;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class StudentRowImporterTest extends TestCase {

	private StudentRecordWriter $writer;
	private StudentRecordRepository $studentRecords;
	private ExpulsionResolver $expulsionResolver;
	private DocTypeResolver $docTypeResolver;
	private LogEventDispatcherInterface $logEvents;
	private StudentRowImporter $importer;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['wpdb'] = new \wpdb();

		$this->writer             = $this->createMock( StudentRecordWriter::class );
		$this->studentRecords     = $this->createMock( StudentRecordRepository::class );
		$this->expulsionResolver  = $this->createMock( ExpulsionResolver::class );
		$this->docTypeResolver    = $this->createMock( DocTypeResolver::class );
		$this->logEvents          = $this->createMock( LogEventDispatcherInterface::class );

		$clock = $this->createMock( ClockInterface::class );
		$clock->method( 'now' )->willReturn( '2024-01-01 00:00:00' );

		$this->docTypeResolver->method( 'resolve' )->willReturn( '' );
		$this->expulsionResolver->method( 'resolve' )->willReturn( null );

		$this->importer = new StudentRowImporter(
			$this->writer,
			$this->studentRecords,
			$this->expulsionResolver,
			$this->docTypeResolver,
			$clock,
			$this->logEvents,
		);
	}

	/** @param array<string,string> $overrides */
	private function row( array $overrides = array() ): array {
		$base = array(
			ImportColumn::LastName->value        => 'Иванов',
			ImportColumn::FirstName->value       => 'Иван',
			ImportColumn::Group->value           => 'G-1',
			ImportColumn::ContractNo->value      => 'C-1',
			ImportColumn::ParentLastName->value  => 'Иванова',
			ImportColumn::ParentFirstName->value => 'Мария',
		);

		return array_merge( $base, $overrides );
	}

	private function ctx( bool $dryRun = false ): ImportContextDTO {
		return new ImportContextDTO( 'math', '2024', $dryRun, 1, 1 );
	}

	public function testCreatesActiveRecord(): void {
		$this->writer->method( 'resolveGroupId' )->willReturn( null );
		$this->writer->method( 'resolvePersonId' )->willReturn( null );
		$this->writer->method( 'createGroup' )->willReturn( 50 );
		// Порядок создания в import(): сначала родитель, потом ученик.
		$this->writer->method( 'createPerson' )->willReturnOnConsecutiveCalls( 101, 202 );

		$captured = null;
		$this->studentRecords->method( 'create' )->willReturnCallback(
			function ( StudentRecordInputDTO $dto ) use ( &$captured ): int {
				$captured = $dto;
				return 1;
			}
		);

		$this->logEvents->expects( $this->once() )
			->method( 'dispatch' )
			->with( LogEvent::StudentEnrolled, $this->anything() );

		$result = $this->importer->import( $this->row(), $this->ctx() );

		$this->assertTrue( $result->isCreated() );
		$this->assertSame( EnrollmentStatus::Active->value, $captured->status );
		$this->assertSame( 202, $captured->studentPersonId );
		$this->assertSame( 101, $captured->parentPersonId );
		$this->assertSame( 50, $captured->groupId );
		$this->assertSame( 'C-1', $captured->contractNo );
		$this->assertNull( $captured->expelledAt );
	}

	public function testSkipsDuplicateByContract(): void {
		$this->writer->method( 'resolveGroupId' )->willReturn( 50 );
		// Порядок резолва в import(): сначала ученик, потом родитель.
		$this->writer->method( 'resolvePersonId' )->willReturnOnConsecutiveCalls( 5, 6 );
		$this->studentRecords->method( 'existsByContract' )->with( 5, 50, 'C-1' )->willReturn( true );

		$this->writer->expects( $this->never() )->method( 'createPerson' );
		$this->writer->expects( $this->never() )->method( 'createGroup' );
		$this->studentRecords->expects( $this->never() )->method( 'create' );

		$result = $this->importer->import( $this->row(), $this->ctx() );

		$this->assertFalse( $result->isCreated() );
	}

	public function testDryRunDoesNotWrite(): void {
		$this->writer->method( 'resolveGroupId' )->willReturn( null );
		$this->writer->method( 'resolvePersonId' )->willReturn( null );

		$this->writer->expects( $this->never() )->method( 'createGroup' );
		$this->writer->expects( $this->never() )->method( 'createPerson' );
		$this->studentRecords->expects( $this->never() )->method( 'create' );

		$result = $this->importer->import( $this->row(), $this->ctx( dryRun: true ) );

		$this->assertTrue( $result->isCreated() );
	}

	public function testDryRunLabelDescribesRow(): void {
		$this->writer->method( 'resolveGroupId' )->willReturn( null );
		$this->writer->method( 'resolvePersonId' )->willReturn( null );

		$result = $this->importer->import(
			$this->row( array( ImportColumn::MiddleName->value => 'Иванович' ) ),
			$this->ctx( dryRun: true )
		);

		$this->assertSame( 'Иванов Иван Иванович — группа «G-1», договор № C-1', $result->label );
	}

	public function testDryRunLabelMarksArchiveRow(): void {
		$this->writer->method( 'resolveGroupId' )->willReturn( null );
		$this->writer->method( 'resolvePersonId' )->willReturn( null );

		$result = $this->importer->import(
			$this->row( array( ImportColumn::ExpelReason->value => 'Окончание курса' ) ),
			$this->ctx( dryRun: true )
		);

		$this->assertStringEndsWith( ', в архив', (string) $result->label );
	}

	public function testMissingRequiredValueThrows(): void {
		$this->expectException( InvalidArgumentException::class );

		$this->importer->import(
			$this->row( array( ImportColumn::FirstName->value => '' ) ),
			$this->ctx()
		);
	}

	public function testExpelledRecordMapsStatusAndFields(): void {
		$this->expulsionResolver = $this->createMock( ExpulsionResolver::class );
		$this->expulsionResolver->method( 'resolve' )->willReturn(
			array( 'status' => EnrollmentStatus::Finished, 'reason' => 'Окончание курса' )
		);

		$clock = $this->createMock( ClockInterface::class );
		$clock->method( 'now' )->willReturn( '2024-01-01 00:00:00' );

		$importer = new StudentRowImporter(
			$this->writer,
			$this->studentRecords,
			$this->expulsionResolver,
			$this->docTypeResolver,
			$clock,
			$this->logEvents,
		);

		$this->writer->method( 'resolveGroupId' )->willReturn( null );
		$this->writer->method( 'resolvePersonId' )->willReturn( null );
		$this->writer->method( 'createGroup' )->willReturn( 50 );
		$this->writer->method( 'createPerson' )->willReturnOnConsecutiveCalls( 101, 202 );

		$captured = null;
		$this->studentRecords->method( 'create' )->willReturnCallback(
			function ( StudentRecordInputDTO $dto ) use ( &$captured ): int {
				$captured = $dto;
				return 1;
			}
		);

		$importer->import(
			$this->row( array( ImportColumn::ExpelledAt->value => '31.05.2024' ) ),
			$this->ctx()
		);

		$this->assertSame( EnrollmentStatus::Finished->value, $captured->status );
		$this->assertSame( '2024-05-31 00:00:00', $captured->expelledAt );
		$this->assertSame( 'Окончание курса', $captured->expelReason );
		$this->assertSame( 1, $captured->expelledByUserId );
	}
}
