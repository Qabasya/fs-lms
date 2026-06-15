<?php

declare(strict_types=1);

namespace Unit\Services\Enrollment;

use DomainException;
use Inc\Contracts\ClockInterface;
use Inc\Contracts\LogEventDispatcherInterface;
use Inc\DTO\Application\ApplicationDTO;
use Inc\DTO\Enrollment\EnrollmentInputDTO;
use Inc\DTO\Enrollment\StudentRecordDTO;
use Inc\DTO\Person\PersonDTO;
use Inc\Enums\ApplicationStatus;
use Inc\Enums\EnrollmentStatus;
use Inc\Managers\UserManager;
use Inc\Repositories\WPDBRepositories\ApplicationRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\PersonDocumentsRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Services\Application\JoinCodeService;
use Inc\Services\ConsentService;
use Inc\Services\Email\EmailService;
use Inc\Services\Enrollment\EnrollmentService;
use Inc\Services\Person\PersonService;
use Inc\Services\Security\PasswordGeneratorService;
use Inc\Services\Security\PiiCryptoService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class EnrollmentServiceTest extends TestCase {

	private ApplicationRepository $appRepo;
	private StudentRecordRepository $studentRecordRepo;
	private PersonRepository $personRepo;
	private PersonDocumentsRepository $docsRepo;
	private PersonService $personService;
	private UserManager $userManager;
	private JoinCodeService $joinCodeService;
	private PasswordGeneratorService $passwordGenerator;
	private PiiCryptoService $crypto;
	private ClockInterface $clock;
	private LogEventDispatcherInterface $logEvents;
	private EnrollmentService $service;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['wpdb'] = new \wpdb();

		$this->appRepo           = $this->createMock( ApplicationRepository::class );
		$this->studentRecordRepo = $this->createMock( StudentRecordRepository::class );
		$this->personRepo        = $this->createMock( PersonRepository::class );
		$this->docsRepo          = $this->createMock( PersonDocumentsRepository::class );
		$this->personService     = $this->createMock( PersonService::class );
		$this->userManager       = $this->createMock( UserManager::class );
		$this->joinCodeService   = $this->createMock( JoinCodeService::class );
		$this->passwordGenerator = $this->createMock( PasswordGeneratorService::class );
		$this->crypto            = $this->createMock( PiiCryptoService::class );
		$this->clock             = $this->createMock( ClockInterface::class );
		$this->logEvents         = $this->createMock( LogEventDispatcherInterface::class );

		$this->clock->method( 'now' )->willReturn( '2024-01-01 12:00:00' );
		$this->crypto->method( 'encrypt' )->willReturn( 'enc_value' );
		$this->crypto->method( 'hash' )->willReturn( 'any_hash' );
		$this->joinCodeService->method( 'generate' )->willReturn( 'JOIN-ABCD-EFGH-1234' );
		$this->joinCodeService->method( 'hash' )->willReturn( 'code_hash' );

		$this->service = new EnrollmentService(
			$this->appRepo,
			$this->studentRecordRepo,
			$this->personRepo,
			$this->docsRepo,
			$this->personService,
			$this->createMock( GroupsRepository::class ),
			$this->joinCodeService,
			$this->createMock( ConsentService::class ),
			$this->userManager,
			$this->passwordGenerator,
			$this->createMock( EmailService::class ),
			$this->crypto,
			$this->clock,
			$this->logEvents,
		);
	}

	// ── Helpers ──────────────────────────────────────────────────────────────────

	private function makeEnrollingApp(
		?int $studentPersonId = null,
		?int $parentPersonId = null
	): ApplicationDTO {
		$studentJson = (string) json_encode( [
			'last_name'  => 'Иванов', 'first_name' => 'Иван',
			'doc_number' => '1234567890', 'email' => 'student@test.com', 'grade' => 10,
		] );
		$parentJson  = (string) json_encode( [
			'last_name'  => 'Петрова', 'first_name' => 'Мария',
			'doc_number' => '9876543210', 'email' => 'parent@test.com',
		] );

		$this->crypto->method( 'decrypt' )->willReturnCallback(
			static fn( string $enc ) => str_contains( $enc, 'student' ) ? $studentJson : $parentJson
		);

		return new ApplicationDTO(
			id:                1,
			studentPersonId:   $studentPersonId,
			parentPersonId:    $parentPersonId,
			status:            ApplicationStatus::Enrolling,
			joinCodeHash:      null,
			joinCodeEnc:       null,
			joinCodeExpiresAt: null,
			studentEmailHash:  null,
			studentDataEnc:    'student_data_enc',
			parentDataEnc:     'parent_data_enc',
			convertedRecordId: null,
			parentSubmittedIp: null,
			parentSubmittedUa: null,
			reviewedByUserId:  null,
			createdAt:         '2024-01-01 00:00:00',
			updatedAt:         '2024-01-01 00:00:00',
		);
	}

	private function makePendingApp( int $id = 1, ?int $parentPersonId = null ): ApplicationDTO {
		return new ApplicationDTO(
			id:                $id,
			studentPersonId:   null,
			parentPersonId:    $parentPersonId,
			status:            ApplicationStatus::PendingParent,
			joinCodeHash:      null,
			joinCodeEnc:       null,
			joinCodeExpiresAt: null,
			studentEmailHash:  null,
			studentDataEnc:    null,
			parentDataEnc:     null,
			convertedRecordId: null,
			parentSubmittedIp: null,
			parentSubmittedUa: null,
			reviewedByUserId:  null,
			createdAt:         '2024-01-01 00:00:00',
			updatedAt:         '2024-01-01 00:00:00',
		);
	}

	private function makePersonDTO( int $id, ?string $expelledAt = null ): PersonDTO {
		return new PersonDTO(
			id:         $id,
			wpUserId:   null,
			lastName:   'Тест',
			firstName:  'Тест',
			middleName: null,
			birthDate:  null,
			isStudent:  true,
			school:     null,
			grade:      null,
			expelledAt: $expelledAt,
			createdAt:  '2024-01-01 00:00:00',
			updatedAt:  '2024-01-01 00:00:00',
		);
	}

	private function makeStudentRecord( int $studentPersonId, int $parentPersonId = 0 ): StudentRecordDTO {
		return new StudentRecordDTO(
			id:                 1,
			studentPersonId:    $studentPersonId,
			parentPersonId:     $parentPersonId,
			groupId:            5,
			snapshotLastName:   'Иванов',
			snapshotFirstName:  'Иван',
			snapshotMiddleName: null,
			snapshotSchool:     null,
			snapshotGrade:      null,
			contractNo:         null,
			contractDate:       null,
			orderNo:            null,
			orderDate:          null,
			status:             EnrollmentStatus::Active,
			enrolledAt:         '2024-01-01 00:00:00',
			enrolledByUserId:   null,
			expelledAt:         null,
			expelledByUserId:   null,
			expelReason:        null,
			createdAt:          '2024-01-01 00:00:00',
			updatedAt:          '2024-01-01 00:00:00',
		);
	}

	private function makeInput( int $appId = 1, int $groupId = 5 ): EnrollmentInputDTO {
		return new EnrollmentInputDTO(
			applicationId: $appId,
			contractNo:    'К-001',
			contractDate:  '2024-01-01',
			orderNo:       'П-001',
			orderDate:     '2024-01-01',
			enrolledAt:    '2024-01-01',
			groupId:       $groupId,
			sendEmailAuto: false,
		);
	}

	// ── enroll() — pre-transaction exceptions ─────────────────────────────────

	public function test_enroll_throws_invalid_argument_when_application_not_found(): void {
		$this->appRepo->method( 'find' )->willReturn( null );
		$this->expectException( InvalidArgumentException::class );
		$this->service->enroll( $this->makeInput() );
	}

	public function test_enroll_throws_domain_exception_when_status_not_enrolling(): void {
		$app = new ApplicationDTO(
			id: 1, studentPersonId: null, parentPersonId: null,
			status: ApplicationStatus::ReadyForReview,
			joinCodeHash: null, joinCodeEnc: null, joinCodeExpiresAt: null,
			studentEmailHash: null, studentDataEnc: null, parentDataEnc: null,
			convertedRecordId: null, parentSubmittedIp: null, parentSubmittedUa: null,
			reviewedByUserId: null, createdAt: '2024-01-01', updatedAt: '2024-01-01',
		);
		$this->appRepo->method( 'find' )->willReturn( $app );
		$this->expectException( DomainException::class );
		$this->service->enroll( $this->makeInput() );
	}

	public function test_enroll_throws_domain_exception_when_parent_email_is_taken(): void {
		$this->appRepo->method( 'find' )->willReturn( $this->makeEnrollingApp() );
		$this->personService->method( 'findByDocNumberHash' )->willReturn( null );
		$this->userManager->method( 'findByEmail' )->willReturn( new \WP_User() );

		$this->expectException( DomainException::class );
		$this->service->enroll( $this->makeInput() );
	}

	public function test_enroll_throws_domain_exception_when_student_already_active_in_group(): void {
		$studentId = 7;
		$this->appRepo->method( 'find' )->willReturn( $this->makeEnrollingApp() );
		$this->personService->method( 'findByDocNumberHash' )
			->willReturnOnConsecutiveCalls( $studentId, null );
		$this->personRepo->method( 'find' )->willReturn( $this->makePersonDTO( $studentId ) );
		$this->userManager->method( 'findByEmail' )->willReturn( null );
		$this->studentRecordRepo->method( 'existsActive' )->willReturn( true );

		$this->expectException( DomainException::class );
		$this->service->enroll( $this->makeInput() );
	}

	public function test_enroll_clears_expelled_at_when_student_person_id_is_set(): void {
		$studentId = 7;
		$this->appRepo->method( 'find' )->willReturn( $this->makeEnrollingApp( studentPersonId: $studentId ) );
		$this->personRepo->method( 'findIncludingDeleted' )
			->willReturn( $this->makePersonDTO( $studentId, '2024-01-01 00:00:00' ) );
		$this->personService->method( 'findByDocNumberHash' )->willReturn( null );
		$this->userManager->method( 'findByEmail' )->willReturn( null );
		$this->studentRecordRepo->method( 'existsActive' )->willReturn( true );

		$this->personRepo->expects( $this->once() )
			->method( 'update' )
			->with( $studentId, [ 'expelled_at' => null ] );

		$this->expectException( DomainException::class );
		$this->service->enroll( $this->makeInput() );
	}

	public function test_enroll_does_not_create_person_when_student_found_by_doc_hash(): void {
		$studentId = 15;
		$this->appRepo->method( 'find' )->willReturn( $this->makeEnrollingApp() );
		$this->personService->method( 'findByDocNumberHash' )
			->willReturnOnConsecutiveCalls( $studentId, null );
		$this->personRepo->method( 'find' )->willReturn( $this->makePersonDTO( $studentId ) );
		$this->userManager->method( 'findByEmail' )->willReturn( null );
		$this->studentRecordRepo->method( 'existsActive' )->willReturn( true );

		$this->personService->expects( $this->never() )->method( 'createOrFindBy' );

		$this->expectException( DomainException::class );
		$this->service->enroll( $this->makeInput() );
	}

	public function test_enroll_calls_create_or_find_by_twice_for_new_student_and_guardian(): void {
		$this->appRepo->method( 'find' )->willReturn( $this->makeEnrollingApp() );
		$this->personService->method( 'findByDocNumberHash' )->willReturn( null );
		$this->userManager->method( 'findByEmail' )->willReturn( null );
		$this->personService->method( 'createOrFindBy' )->willReturnOnConsecutiveCalls( 10, 20 );
		$this->studentRecordRepo->method( 'create' )->willReturn( 5 );
		$this->passwordGenerator->method( 'generatePlain' )->willReturn( 'password' );
		$this->userManager->method( 'create' )
			->willThrowException( new \RuntimeException( 'simulated WP error' ) );

		$this->personService->expects( $this->exactly( 2 ) )->method( 'createOrFindBy' );

		$result = $this->service->enroll( $this->makeInput() );
		self::assertTrue( $result->partialFailure );
	}

	public function test_enroll_does_not_call_create_or_find_for_guardian_when_parent_person_id_is_set(): void {
		$studentId  = 7;
		$guardianId = 11;
		$this->appRepo->method( 'find' )
			->willReturn( $this->makeEnrollingApp( studentPersonId: $studentId, parentPersonId: $guardianId ) );
		$this->personRepo->method( 'findIncludingDeleted' )
			->willReturn( $this->makePersonDTO( $studentId ) );
		$this->personRepo->method( 'find' )
			->willReturn( $this->makePersonDTO( $guardianId ) );
		$this->studentRecordRepo->method( 'existsActive' )->willReturn( true );

		$this->personService->expects( $this->never() )->method( 'createOrFindBy' );

		$this->expectException( DomainException::class );
		$this->service->enroll( $this->makeInput() );
	}

	// ── restoreFromArchive() ──────────────────────────────────────────────────

	public function test_restore_throws_when_record_not_found(): void {
		$this->studentRecordRepo->method( 'find' )->willReturn( null );
		$this->expectException( InvalidArgumentException::class );
		$this->service->restoreFromArchive( 1 );
	}

	public function test_restore_without_parent_returns_dto_with_null_parent_name(): void {
		$this->studentRecordRepo->method( 'find' )->willReturn( $this->makeStudentRecord( 3, 5 ) );
		$this->personRepo->method( 'findIncludingDeleted' )->willReturn( $this->makePersonDTO( 3 ) );
		$this->docsRepo->method( 'findByPersonId' )->willReturn( null );
		$this->appRepo->method( 'create' )->willReturn( 99 );

		$this->appRepo->expects( $this->never() )->method( 'update' );

		$result = $this->service->restoreFromArchive( 1, false );

		self::assertNull( $result->parentName );
		self::assertSame( 99, $result->appId );
	}

	public function test_restore_with_parent_throws_when_parent_person_id_is_zero(): void {
		$this->studentRecordRepo->method( 'find' )->willReturn( $this->makeStudentRecord( 3, 0 ) );
		$this->personRepo->method( 'findIncludingDeleted' )->willReturn( $this->makePersonDTO( 3 ) );
		$this->docsRepo->method( 'findByPersonId' )->willReturn( null );
		$this->appRepo->method( 'create' )->willReturn( 99 );

		$this->expectException( InvalidArgumentException::class );
		$this->service->restoreFromArchive( 1, true );
	}

	public function test_restore_calls_person_documents_repo_for_student_docs(): void {
		$studentPersonId = 3;
		$this->studentRecordRepo->method( 'find' )->willReturn( $this->makeStudentRecord( $studentPersonId ) );
		$this->personRepo->method( 'findIncludingDeleted' )->willReturn( $this->makePersonDTO( $studentPersonId ) );
		$this->docsRepo->expects( $this->once() )->method( 'findByPersonId' )
			->with( $studentPersonId )->willReturn( null );
		$this->appRepo->method( 'create' )->willReturn( 99 );

		$this->service->restoreFromArchive( 1, false );
	}

	public function test_restore_with_parent_sets_status_to_ready_for_review(): void {
		$studentPersonId = 3;
		$parentPersonId  = 5;

		$this->studentRecordRepo->method( 'find' )
			->willReturn( $this->makeStudentRecord( $studentPersonId, $parentPersonId ) );
		$this->personRepo->method( 'findIncludingDeleted' )
			->willReturnCallback( function( int $id ) use ( $studentPersonId, $parentPersonId ): PersonDTO {
				return $this->makePersonDTO( $id === $parentPersonId ? $parentPersonId : $studentPersonId );
			} );
		$this->docsRepo->method( 'findByPersonId' )->willReturn( null );
		$this->appRepo->method( 'create' )->willReturn( 99 );
		$this->appRepo->method( 'find' )->willReturn( $this->makePendingApp( 99 ) );

		$this->appRepo->expects( $this->atLeastOnce() )->method( 'update' )
			->willReturn( true );

		$result = $this->service->restoreFromArchive( 1, true );

		self::assertNotNull( $result->parentName );
		self::assertSame( 99, $result->appId );
	}

	// ── selectExistingParent() ────────────────────────────────────────────────

	public function test_select_existing_parent_throws_when_app_not_found(): void {
		$this->appRepo->method( 'find' )->willReturn( null );
		$this->expectException( InvalidArgumentException::class );
		$this->service->selectExistingParent( 1, 5 );
	}

	public function test_select_existing_parent_throws_when_parent_not_found(): void {
		$this->appRepo->method( 'find' )->willReturn( $this->makePendingApp() );
		$this->personRepo->method( 'findIncludingDeleted' )->willReturn( null );
		$this->expectException( InvalidArgumentException::class );
		$this->service->selectExistingParent( 1, 5 );
	}

	public function test_select_existing_parent_rotates_join_code(): void {
		$this->appRepo->method( 'find' )->willReturn( $this->makePendingApp() );
		$this->personRepo->method( 'findIncludingDeleted' )->willReturn( $this->makePersonDTO( 5 ) );
		$this->docsRepo->method( 'findByPersonId' )->willReturn( null );

		$this->appRepo->expects( $this->once() )->method( 'update' )
			->with( 1, $this->callback( fn( array $d ) => $d['join_code_hash'] === 'code_hash' ) )
			->willReturn( true );

		$result = $this->service->selectExistingParent( 1, 5 );

		self::assertStringContainsString( 'JOIN-ABCD-EFGH-1234', $result->joinUrl );
	}

	// ── removeParentAssignment() ──────────────────────────────────────────────

	public function test_remove_parent_throws_when_app_not_found(): void {
		$this->appRepo->method( 'find' )->willReturn( null );
		$this->expectException( InvalidArgumentException::class );
		$this->service->removeParentAssignment( 1 );
	}

	public function test_remove_parent_rotates_join_code_and_clears_parent_person_id(): void {
		$this->appRepo->method( 'find' )->willReturn( $this->makePendingApp( 1, parentPersonId: 5 ) );

		$this->appRepo->expects( $this->once() )->method( 'update' )
			->with(
				1,
				$this->callback( fn( array $d ) => $d['parent_person_id'] === null && isset( $d['join_code_hash'] ) )
			)
			->willReturn( true );

		$result = $this->service->removeParentAssignment( 1 );

		self::assertStringContainsString( 'JOIN-ABCD-EFGH-1234', $result->joinUrl );
	}
}
