<?php

declare(strict_types=1);

namespace Unit\Services\Enrollment;

use Inc\Contracts\LogEventDispatcherInterface;
use Inc\DTO\Application\ApplicationDTO;
use Inc\DTO\Enrollment\StudentDataDTO;
use Inc\DTO\Enrollment\StudentRecordDTO;
use Inc\DTO\Import\AccountCredentialsDTO;
use Inc\DTO\Person\ParentDataDTO;
use Inc\DTO\Person\PersonDTO;
use Inc\Enums\Enrollment\ApplicationStatus;
use Inc\Enums\Enrollment\EnrollmentStatus;
use Inc\Repositories\WPDBRepositories\ApplicationRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Services\Application\ApplicationService;
use Inc\Services\Enrollment\EnrollmentAccountsService;
use Inc\Services\Enrollment\RecoveryService;
use Inc\Services\Security\PiiCryptoService;
use PHPUnit\Framework\TestCase;

class RecoveryServiceTest extends TestCase {

	private ApplicationRepository $appRepo;
	private StudentRecordRepository $recordRepo;
	private PersonRepository $personRepo;
	private ApplicationService $applications;
	private EnrollmentAccountsService $accounts;
	private RecoveryService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->appRepo      = $this->createMock( ApplicationRepository::class );
		$this->recordRepo   = $this->createMock( StudentRecordRepository::class );
		$this->personRepo   = $this->createMock( PersonRepository::class );
		$this->applications = $this->createMock( ApplicationService::class );
		$this->accounts     = $this->createMock( EnrollmentAccountsService::class );

		$crypto = $this->createStub( PiiCryptoService::class );
		$crypto->method( 'decrypt' )->willReturnCallback(
			static fn( string $enc ): string => (string) json_encode(
				'student_enc' === $enc
					? array( 'last_name' => 'Иванов', 'first_name' => 'Иван', 'username' => 'ivanov', 'login_password' => 'Pass%1' )
					: array( 'last_name' => 'Иванова', 'first_name' => 'Мария', 'email' => 'mom@test.com' )
			)
		);

		$this->service = new RecoveryService(
			$this->appRepo,
			$this->recordRepo,
			$this->personRepo,
			$this->createStub( LogEventDispatcherInterface::class ),
			$this->applications,
			$this->accounts,
			$crypto,
		);
	}

	// ── Helpers ──────────────────────────────────────────────────────────────────

	private function app( int $id, ?int $studentPersonId, ApplicationStatus $status = ApplicationStatus::Enrolling, ?int $recordId = null ): ApplicationDTO {
		return new ApplicationDTO(
			id:                $id,
			studentPersonId:   $studentPersonId,
			parentPersonId:    null,
			status:            $status,
			joinCodeHash:      null,
			joinCodeEnc:       null,
			joinCodeExpiresAt: null,
			studentEmailHash:  null,
			studentDataEnc:    'student_enc',
			parentDataEnc:     'parent_enc',
			convertedRecordId: $recordId,
			parentSubmittedIp: null,
			parentSubmittedUa: null,
			reviewedByUserId:  null,
			createdAt:         '2024-01-01 00:00:00',
			updatedAt:         '2024-01-01 00:00:00',
		);
	}

	private function record( int $id, int $studentPersonId, int $parentPersonId = 0 ): StudentRecordDTO {
		return new StudentRecordDTO(
			id:                 $id,
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

	private function person( int $id, ?int $wpUserId ): PersonDTO {
		return new PersonDTO(
			id:         $id,
			wpUserId:   $wpUserId,
			lastName:   'Иванов',
			firstName:  'Иван',
			middleName: null,
			birthDate:  null,
			isStudent:  true,
			school:     null,
			grade:      null,
			expelledAt: null,
			createdAt:  '2024-01-01 00:00:00',
			updatedAt:  '2024-01-01 00:00:00',
		);
	}

	private function credentials( int $userId ): AccountCredentialsDTO {
		return new AccountCredentialsDTO( $userId, 'login', 'pass', true );
	}

	// ── Зависшее enrolling ─────────────────────────────────────────────────────────

	public function test_stuck_threshold_is_long_enough_for_open_enrollment_modal(): void {
		$this->appRepo->expects( self::once() )
			->method( 'findStuckEnrolling' )
			->with( RecoveryService::STUCK_ENROLLING_MINUTES )
			->willReturn( array() );
		$this->appRepo->method( 'findConverted' )->willReturn( array() );

		self::assertGreaterThanOrEqual( 30, RecoveryService::STUCK_ENROLLING_MINUTES );
		self::assertSame( 0, $this->service->resolveStuckEnrollments() );
	}

	public function test_resets_application_to_ready_for_review_when_no_active_record(): void {
		$this->appRepo->method( 'findStuckEnrolling' )->willReturn( array( $this->app( 1, 300 ) ) );
		$this->appRepo->method( 'findConverted' )->willReturn( array() );
		$this->recordRepo->method( 'findActiveByStudentFirst' )->willReturn( null );

		$this->applications->expects( self::once() )
			->method( 'changeStatus' )
			->with( 1, ApplicationStatus::ReadyForReview );
		$this->appRepo->expects( self::never() )->method( 'markConverted' );

		self::assertSame( 1, $this->service->resolveStuckEnrollments() );
	}

	public function test_stuck_application_with_record_is_marked_converted_without_creating_users_itself(): void {
		$this->appRepo->method( 'findStuckEnrolling' )->willReturn( array( $this->app( 2, 300 ) ) );
		$this->appRepo->method( 'findConverted' )->willReturn( array() );
		$this->recordRepo->method( 'findActiveByStudentFirst' )->willReturn( $this->record( 55, 300 ) );

		$this->appRepo->expects( self::once() )->method( 'markConverted' )->with( 2, 55 );
		$this->accounts->expects( self::never() )->method( 'provisionStudent' );

		self::assertSame( 1, $this->service->resolveStuckEnrollments() );
	}

	// ── converted без учёток ───────────────────────────────────────────────────────

	public function test_creates_missing_accounts_from_application_data_and_deletes_application(): void {
		$this->appRepo->method( 'findStuckEnrolling' )->willReturn( array() );
		$this->appRepo->method( 'findConverted' )->willReturn( array( $this->app( 3, 300, ApplicationStatus::Converted, 55 ) ) );
		$this->recordRepo->method( 'find' )->with( 55 )->willReturn( $this->record( 55, 300, 400 ) );
		$this->personRepo->method( 'find' )->willReturnMap( array(
			array( 300, $this->person( 300, null ) ),
			array( 400, $this->person( 400, null ) ),
		) );

		$this->accounts->expects( self::once() )
			->method( 'provisionStudent' )
			->with(
				self::callback( static fn( StudentDataDTO $s ): bool => 'ivanov' === $s->username && 'Pass%1' === $s->loginPassword ),
				300
			)
			->willReturn( $this->credentials( 1001 ) );
		$this->accounts->expects( self::once() )
			->method( 'provisionGuardian' )
			->with( self::callback( static fn( ParentDataDTO $p ): bool => 'mom@test.com' === $p->email ), 400 )
			->willReturn( $this->credentials( 1002 ) );
		$this->appRepo->expects( self::once() )->method( 'forceDelete' )->with( 3 );

		self::assertSame( 1, $this->service->resolveStuckEnrollments() );
	}

	public function test_existing_account_is_not_provisioned_again(): void {
		$this->appRepo->method( 'findStuckEnrolling' )->willReturn( array() );
		$this->appRepo->method( 'findConverted' )->willReturn( array( $this->app( 4, 300, ApplicationStatus::Converted, 55 ) ) );
		$this->recordRepo->method( 'find' )->willReturn( $this->record( 55, 300, 400 ) );
		$this->personRepo->method( 'find' )->willReturnMap( array(
			array( 300, $this->person( 300, 900 ) ),
			array( 400, $this->person( 400, null ) ),
		) );

		// У ученика учётка уже есть — его пароль не трогаем; создаём только родителя.
		$this->accounts->expects( self::never() )->method( 'provisionStudent' );
		$this->accounts->expects( self::once() )->method( 'provisionGuardian' )->willReturn( $this->credentials( 1002 ) );
		$this->appRepo->expects( self::once() )->method( 'forceDelete' )->with( 4 );

		self::assertSame( 1, $this->service->resolveStuckEnrollments() );
	}

	public function test_failed_provisioning_keeps_application_for_next_run(): void {
		$this->appRepo->method( 'findStuckEnrolling' )->willReturn( array() );
		$this->appRepo->method( 'findConverted' )->willReturn( array(
			$this->app( 5, 300, ApplicationStatus::Converted, 55 ),
			$this->app( 6, 301, ApplicationStatus::Converted, 56 ),
		) );
		$this->recordRepo->method( 'find' )->willReturnCallback(
			fn( int $id ): StudentRecordDTO => $this->record( $id, 55 === $id ? 300 : 301 )
		);
		$this->personRepo->method( 'find' )->willReturnCallback( fn( int $id ): PersonDTO => $this->person( $id, null ) );
		$this->accounts->method( 'provisionStudent' )->willReturnCallback(
			function ( StudentDataDTO $s, int $personId ): AccountCredentialsDTO {
				if ( 300 === $personId ) {
					throw new \RuntimeException( 'Логин занят' );
				}
				return $this->credentials( 1001 );
			}
		);

		// Первая заявка остаётся converted, вторая завершается.
		$this->appRepo->expects( self::once() )->method( 'forceDelete' )->with( 6 );

		self::assertSame( 1, $this->service->resolveStuckEnrollments() );
	}

	public function test_converted_application_without_record_is_left_alone(): void {
		$this->appRepo->method( 'findStuckEnrolling' )->willReturn( array() );
		$this->appRepo->method( 'findConverted' )->willReturn( array( $this->app( 7, 300, ApplicationStatus::Converted, null ) ) );

		$this->appRepo->expects( self::never() )->method( 'forceDelete' );

		self::assertSame( 0, $this->service->resolveStuckEnrollments() );
	}
}
