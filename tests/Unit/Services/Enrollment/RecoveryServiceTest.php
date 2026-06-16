<?php

declare(strict_types=1);

namespace Unit\Services\Enrollment;

use Inc\Contracts\LogEventDispatcherInterface;
use Inc\DTO\Application\ApplicationDTO;
use Inc\DTO\Enrollment\StudentRecordDTO;
use Inc\DTO\Person\PersonDTO;
use Inc\Enums\ApplicationStatus;
use Inc\Enums\EnrollmentStatus;
use Inc\Managers\UserManager;
use Inc\Repositories\WPDBRepositories\ApplicationRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Services\Enrollment\RecoveryService;
use PHPUnit\Framework\TestCase;

class RecoveryServiceTest extends TestCase {

	private ApplicationRepository $appRepo;
	private StudentRecordRepository $recordRepo;
	private PersonRepository $personRepo;
	private UserManager $userManager;
	private LogEventDispatcherInterface $logEvents;
	private RecoveryService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->appRepo     = $this->createMock( ApplicationRepository::class );
		$this->recordRepo  = $this->createMock( StudentRecordRepository::class );
		$this->personRepo  = $this->createMock( PersonRepository::class );
		$this->userManager = $this->createMock( UserManager::class );
		$this->logEvents   = $this->createMock( LogEventDispatcherInterface::class );

		$this->service = new RecoveryService(
			$this->appRepo,
			$this->recordRepo,
			$this->personRepo,
			$this->userManager,
			$this->logEvents,
		);
	}

	// ── Helpers ──────────────────────────────────────────────────────────────────

	private function stuckApp( int $id, ?int $studentPersonId ): ApplicationDTO {
		return new ApplicationDTO(
			id:                $id,
			studentPersonId:   $studentPersonId,
			parentPersonId:    null,
			status:            ApplicationStatus::Enrolling,
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

	private function record( int $id, int $studentPersonId ): StudentRecordDTO {
		return new StudentRecordDTO(
			id:                 $id,
			studentPersonId:    $studentPersonId,
			parentPersonId:     0,
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

	// ── Tests ─────────────────────────────────────────────────────────────────────

	public function test_resets_application_to_ready_for_review_when_no_active_record(): void {
		$this->appRepo->method( 'findStuckEnrolling' )->willReturn( array( $this->stuckApp( 1, 300 ) ) );
		$this->recordRepo->method( 'findActiveByStudentFirst' )->willReturn( null );

		$this->appRepo->expects( self::once() )
			->method( 'setStatus' )
			->with( 1, ApplicationStatus::ReadyForReview );
		$this->appRepo->expects( self::never() )->method( 'markConverted' );

		self::assertSame( 1, $this->service->resolveStuckEnrollments() );
	}

	public function test_creates_wp_user_when_person_has_none_then_marks_converted(): void {
		$this->appRepo->method( 'findStuckEnrolling' )->willReturn( array( $this->stuckApp( 2, 300 ) ) );
		$this->recordRepo->method( 'findActiveByStudentFirst' )->willReturn( $this->record( 55, 300 ) );
		$this->personRepo->method( 'find' )->willReturn( $this->person( 300, null ) );

		$this->userManager->expects( self::once() )->method( 'create' )->willReturn( 1001 );
		$this->personRepo->expects( self::once() )->method( 'setWpUser' )->with( 300, 1001 );
		$this->userManager->expects( self::once() )->method( 'setPersonId' )->with( 1001, 300 );
		$this->appRepo->expects( self::once() )->method( 'markConverted' )->with( 2, 55 );

		self::assertSame( 1, $this->service->resolveStuckEnrollments() );
	}

	public function test_idempotent_when_person_already_has_wp_user(): void {
		$this->appRepo->method( 'findStuckEnrolling' )->willReturn( array( $this->stuckApp( 3, 300 ) ) );
		$this->recordRepo->method( 'findActiveByStudentFirst' )->willReturn( $this->record( 55, 300 ) );
		$this->personRepo->method( 'find' )->willReturn( $this->person( 300, 900 ) );

		$this->userManager->expects( self::never() )->method( 'create' );
		$this->personRepo->expects( self::never() )->method( 'setWpUser' );
		$this->appRepo->expects( self::once() )->method( 'markConverted' )->with( 3, 55 );

		self::assertSame( 1, $this->service->resolveStuckEnrollments() );
	}

	public function test_returns_count_of_all_resolved_applications(): void {
		$this->appRepo->method( 'findStuckEnrolling' )->willReturn( array(
			$this->stuckApp( 10, 300 ),
			$this->stuckApp( 11, 301 ),
		) );
		$this->recordRepo->method( 'findActiveByStudentFirst' )->willReturn( null );

		self::assertSame( 2, $this->service->resolveStuckEnrollments() );
	}

	public function test_per_record_error_does_not_abort_the_rest(): void {
		$this->appRepo->method( 'findStuckEnrolling' )->willReturn( array(
			$this->stuckApp( 20, 300 ),
			$this->stuckApp( 21, null ),
		) );
		$this->recordRepo->method( 'findActiveByStudentFirst' )->willReturnCallback(
			function ( int $studentPersonId ): ?StudentRecordDTO {
				if ( 300 === $studentPersonId ) {
					throw new \RuntimeException( 'simulated DB failure' );
				}
				return null;
			}
		);

		// Первая заявка падает, вторая (без ученика) переводится в ready_for_review.
		self::assertSame( 1, $this->service->resolveStuckEnrollments() );
	}
}
