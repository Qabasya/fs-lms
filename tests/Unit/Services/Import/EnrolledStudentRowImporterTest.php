<?php

declare( strict_types=1 );

namespace Unit\Services\Import;

use Inc\Contracts\ClockInterface;
use Inc\Contracts\LogEventDispatcherInterface;
use Inc\DTO\Enrollment\StudentRecordInputDTO;
use Inc\DTO\Import\AccountCredentialsDTO;
use Inc\DTO\Import\ImportContextDTO;
use Inc\DTO\Person\PersonInputDTO;
use Inc\Enums\Enrollment\EnrollmentStatus;
use Inc\Enums\Import\ImportColumn;
use Inc\Enums\Import\ImportMode;
use Inc\Enums\Log\LogEvent;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Services\Email\EmailService;
use Inc\Services\Enrollment\AccountProvisioningService;
use Inc\Services\Import\DocTypeResolver;
use Inc\Services\Import\EnrolledStudentRowImporter;
use Inc\Services\Import\StudentRecordWriter;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class EnrolledStudentRowImporterTest extends TestCase {

	private StudentRecordWriter $writer;
	private StudentRecordRepository $studentRecords;
	private DocTypeResolver $docTypeResolver;
	private AccountProvisioningService $provisioning;
	private EmailService $emailService;
	private LogEventDispatcherInterface $logEvents;
	private EnrolledStudentRowImporter $importer;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['wpdb'] = new \wpdb();

		$this->writer         = $this->createMock( StudentRecordWriter::class );
		$this->studentRecords = $this->createMock( StudentRecordRepository::class );
		$this->docTypeResolver = $this->createMock( DocTypeResolver::class );
		$this->provisioning   = $this->createMock( AccountProvisioningService::class );
		$this->emailService   = $this->createMock( EmailService::class );
		$this->logEvents      = $this->createMock( LogEventDispatcherInterface::class );

		$clock = $this->createMock( ClockInterface::class );
		$clock->method( 'now' )->willReturn( '2024-01-01 00:00:00' );

		$this->docTypeResolver->method( 'resolve' )->willReturn( '' );

		$this->importer = new EnrolledStudentRowImporter(
			$this->writer,
			$this->studentRecords,
			$this->docTypeResolver,
			$this->provisioning,
			$this->emailService,
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
			ImportColumn::Username->value        => 'ivanov2024',
			ImportColumn::Password->value        => 'Passw0rd!7',
			ImportColumn::ParentEmail->value     => 'maria@example.com',
		);

		return array_merge( $base, $overrides );
	}

	private function ctx( bool $dryRun = false, bool $sendEmails = false ): ImportContextDTO {
		return new ImportContextDTO( 'math', '2024', $dryRun, 1, 1, ImportMode::Enrolled, $sendEmails );
	}

	/** Настраивает моки на успешное создание записи (группа 50, родитель 101, ученик 202). */
	private function expectRecordCreated(): void {
		$this->writer->method( 'resolveGroupId' )->willReturn( null );
		$this->writer->method( 'resolvePersonId' )->willReturn( null );
		$this->writer->method( 'createGroup' )->willReturn( 50 );
		// Порядок создания в import(): сначала родитель, потом ученик.
		$this->writer->method( 'createPerson' )->willReturnOnConsecutiveCalls( 101, 202 );
		$this->studentRecords->method( 'create' )->willReturn( 1 );
	}

	public function testCreatesActiveRecordWithAccounts(): void {
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

		// Креды ученика — из CSV, пароль родителя — сгенерирован сервисом.
		$this->provisioning->expects( $this->once() )
			->method( 'provisionStudent' )
			->with( 202, $this->isInstanceOf( PersonInputDTO::class ), 'ivanov2024', 'Passw0rd!7' )
			->willReturn( new AccountCredentialsDTO( 10, 'ivanov2024', 'Passw0rd!7', true ) );
		$this->provisioning->expects( $this->once() )
			->method( 'provisionParent' )
			->with( 101, $this->isInstanceOf( PersonInputDTO::class ) )
			->willReturn( new AccountCredentialsDTO( 11, 'maria@example.com', 'GenPass99', true ) );

		$this->logEvents->expects( $this->once() )
			->method( 'dispatch' )
			->with( LogEvent::StudentEnrolled, $this->anything() );

		$result = $this->importer->import( $this->row(), $this->ctx() );

		$this->assertTrue( $result->isCreated() );
		$this->assertSame( EnrollmentStatus::Active->value, $captured->status );
		$this->assertSame( 202, $captured->studentPersonId );
		$this->assertSame( 101, $captured->parentPersonId );
		$this->assertNull( $captured->expelledAt );
		$this->assertNull( $captured->expelReason );

		$this->assertNotNull( $result->credentials );
		$this->assertSame( 'Иванов Иван', $result->credentials->studentName );
		$this->assertSame( 'ivanov2024', $result->credentials->studentLogin );
		$this->assertSame( 'Passw0rd!7', $result->credentials->studentPassword );
		$this->assertSame( 'maria@example.com', $result->credentials->parentLogin );
		$this->assertSame( 'GenPass99', $result->credentials->parentPassword );
	}

	public function testEmptyUsernameThrows(): void {
		$this->provisioning->expects( $this->never() )->method( 'provisionStudent' );
		$this->expectException( InvalidArgumentException::class );

		$this->importer->import(
			$this->row( array( ImportColumn::Username->value => '' ) ),
			$this->ctx()
		);
	}

	public function testEmptyPasswordThrows(): void {
		$this->expectException( InvalidArgumentException::class );

		$this->importer->import(
			$this->row( array( ImportColumn::Password->value => '' ) ),
			$this->ctx()
		);
	}

	public function testEmptyParentEmailThrows(): void {
		$this->expectException( InvalidArgumentException::class );

		$this->importer->import(
			$this->row( array( ImportColumn::ParentEmail->value => '' ) ),
			$this->ctx()
		);
	}

	public function testSkipsDuplicateWithoutTouchingAccounts(): void {
		$this->writer->method( 'resolveGroupId' )->willReturn( 50 );
		// Порядок резолва в import(): сначала ученик, потом родитель.
		$this->writer->method( 'resolvePersonId' )->willReturnOnConsecutiveCalls( 5, 6 );
		$this->studentRecords->method( 'existsByContract' )->with( 5, 50, 'C-1' )->willReturn( true );

		$this->writer->expects( $this->never() )->method( 'createPerson' );
		$this->studentRecords->expects( $this->never() )->method( 'create' );
		$this->provisioning->expects( $this->never() )->method( 'provisionStudent' );
		$this->provisioning->expects( $this->never() )->method( 'provisionParent' );

		$result = $this->importer->import( $this->row(), $this->ctx() );

		$this->assertFalse( $result->isCreated() );
		$this->assertNull( $result->credentials );
	}

	public function testDryRunDoesNotWriteOrProvision(): void {
		$this->writer->method( 'resolveGroupId' )->willReturn( null );
		$this->writer->method( 'resolvePersonId' )->willReturn( null );

		$this->writer->expects( $this->never() )->method( 'createGroup' );
		$this->writer->expects( $this->never() )->method( 'createPerson' );
		$this->studentRecords->expects( $this->never() )->method( 'create' );
		$this->provisioning->expects( $this->never() )->method( 'provisionStudent' );
		$this->provisioning->expects( $this->never() )->method( 'provisionParent' );
		$this->emailService->expects( $this->never() )->method( 'sendWelcomeWithCredentials' );

		$result = $this->importer->import( $this->row(), $this->ctx( dryRun: true ) );

		$this->assertTrue( $result->isCreated() );
		$this->assertNull( $result->credentials );
	}

	public function testDoesNotSendEmailWhenDisabled(): void {
		$this->expectRecordCreated();
		$this->provisioning->method( 'provisionStudent' )
			->willReturn( new AccountCredentialsDTO( 10, 'ivanov2024', 'Passw0rd!7', true ) );
		$this->provisioning->method( 'provisionParent' )
			->willReturn( new AccountCredentialsDTO( 11, 'maria@example.com', 'GenPass99', true ) );

		$this->emailService->expects( $this->never() )->method( 'sendWelcomeWithCredentials' );

		$this->importer->import( $this->row(), $this->ctx( sendEmails: false ) );
	}

	public function testSendsEmailToParentWhenEnabled(): void {
		$this->expectRecordCreated();
		$this->provisioning->method( 'provisionStudent' )
			->willReturn( new AccountCredentialsDTO( 10, 'ivanov2024', 'Passw0rd!7', true ) );
		$this->provisioning->method( 'provisionParent' )
			->willReturn( new AccountCredentialsDTO( 11, 'maria@example.com', 'GenPass99', true ) );

		$this->emailService->expects( $this->once() )
			->method( 'sendWelcomeWithCredentials' )
			->with(
				11,
				'GenPass99',
				$this->callback(
					static fn( array $vars ): bool => 'Иванов Иван' === $vars['student_full_name']
						&& 'Мария' === $vars['parent_first_name']
				),
				101
			)
			->willReturn( true );

		$this->importer->import( $this->row(), $this->ctx( sendEmails: true ) );
	}
}
