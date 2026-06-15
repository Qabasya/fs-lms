<?php

declare( strict_types=1 );

namespace Unit\Services\Import;

use Inc\Contracts\ClockInterface;
use Inc\Contracts\LogEventDispatcherInterface;
use Inc\DTO\Enrollment\StudentRecordInputDTO;
use Inc\DTO\Import\ImportContextDTO;
use Inc\Enums\EnrollmentStatus;
use Inc\Enums\ImportColumn;
use Inc\Enums\LogEvent;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Services\Import\DocTypeResolver;
use Inc\Services\Import\ExpulsionResolver;
use Inc\Services\Import\PersonImportResolver;
use Inc\Services\Import\StudentRowImporter;
use Inc\Services\Person\PersonService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class StudentRowImporterTest extends TestCase {

	private GroupsRepository $groups;
	private PersonImportResolver $personResolver;
	private PersonService $personService;
	private StudentRecordRepository $studentRecords;
	private ExpulsionResolver $expulsionResolver;
	private DocTypeResolver $docTypeResolver;
	private LogEventDispatcherInterface $logEvents;
	private StudentRowImporter $importer;

	protected function setUp(): void {
		parent::setUp();

		$this->groups            = $this->createMock( GroupsRepository::class );
		$this->personResolver    = $this->createMock( PersonImportResolver::class );
		$this->personService     = $this->createMock( PersonService::class );
		$this->studentRecords    = $this->createMock( StudentRecordRepository::class );
		$this->expulsionResolver = $this->createMock( ExpulsionResolver::class );
		$this->docTypeResolver   = $this->createMock( DocTypeResolver::class );
		$this->logEvents         = $this->createMock( LogEventDispatcherInterface::class );

		$clock = $this->createMock( ClockInterface::class );
		$clock->method( 'now' )->willReturn( '2024-01-01 00:00:00' );

		$this->docTypeResolver->method( 'resolve' )->willReturn( '' );
		$this->expulsionResolver->method( 'resolve' )->willReturn( null );

		$this->importer = new StudentRowImporter(
			$this->groups,
			$this->personResolver,
			$this->personService,
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
		$this->groups->method( 'findByNameSubjectPeriod' )->willReturn( null );
		$this->groups->method( 'create' )->willReturn( 50 );
		$this->personResolver->method( 'resolve' )->willReturn( null );
		$this->personService->method( 'createOrFindBy' )->willReturnOnConsecutiveCalls( 101, 202 );

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
		$this->groups->method( 'findByNameSubjectPeriod' )->willReturn( (object) array( 'id' => 50 ) );
		$this->personResolver->method( 'resolve' )->willReturnOnConsecutiveCalls( 5, 6 );
		$this->studentRecords->method( 'existsByContract' )->with( 5, 50, 'C-1' )->willReturn( true );

		$this->studentRecords->expects( $this->never() )->method( 'create' );

		$result = $this->importer->import( $this->row(), $this->ctx() );

		$this->assertFalse( $result->isCreated() );
	}

	public function testDryRunDoesNotWrite(): void {
		$this->groups->method( 'findByNameSubjectPeriod' )->willReturn( null );
		$this->personResolver->method( 'resolve' )->willReturn( null );

		$this->groups->expects( $this->never() )->method( 'create' );
		$this->personService->expects( $this->never() )->method( 'createOrFindBy' );
		$this->studentRecords->expects( $this->never() )->method( 'create' );

		$result = $this->importer->import( $this->row(), $this->ctx( dryRun: true ) );

		$this->assertTrue( $result->isCreated() );
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
			$this->groups,
			$this->personResolver,
			$this->personService,
			$this->studentRecords,
			$this->expulsionResolver,
			$this->docTypeResolver,
			$clock,
			$this->logEvents,
		);

		$this->groups->method( 'findByNameSubjectPeriod' )->willReturn( null );
		$this->groups->method( 'create' )->willReturn( 50 );
		$this->personResolver->method( 'resolve' )->willReturn( null );
		$this->personService->method( 'createOrFindBy' )->willReturnOnConsecutiveCalls( 101, 202 );

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
