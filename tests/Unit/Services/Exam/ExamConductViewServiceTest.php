<?php

declare( strict_types=1 );

namespace Tests\Unit\Services\Exam;

use Inc\Managers\Person\UserManager;

use Inc\DTO\Assessment\AttemptDTO;
use Inc\DTO\Enrollment\StudentRecordDTO;
use Inc\DTO\Exam\ExamParticipantDTO;
use Inc\DTO\Exam\ExamParticipationDTO;
use Inc\DTO\Exam\ExamRegistrationDTO;
use Inc\Enums\Exam\ExamAudience;
use Inc\Repositories\WPDBRepositories\AssessmentAnswerRepository;
use Inc\Repositories\WPDBRepositories\AssessmentAttemptRepository;
use Inc\Repositories\WPDBRepositories\ExamEventRepository;
use Inc\Repositories\WPDBRepositories\ExamParticipantRepository;
use Inc\Repositories\WPDBRepositories\ExamParticipationRepository;
use Inc\Repositories\WPDBRepositories\ExamRegistrationRepository;
use Inc\Repositories\WPDBRepositories\ExamSessionRepository;
use Inc\Repositories\WPDBRepositories\ExamSourceRepository;
use Inc\Repositories\WPDBRepositories\RoomRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Services\Exam\ExamAccessGuard;
use Inc\Services\Exam\ExamConductViewService;
use Inc\Services\Exam\ExamPlanService;
use Inc\Services\Exam\ExamReviewProjection;
use Inc\Services\Exam\ExamRoomService;
use Inc\Services\Exam\ExamScoreService;
use Inc\Services\Exam\ExamTime;
use Inc\Shared\CodedException;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Tests\Support\ExamFixtures;

/**
 * Доска сеанса (8.1) и отметка прихода (8.2.2): плитки, состояния строк, результат, действия, права.
 */
#[AllowMockObjectsWithoutExpectations]
class ExamConductViewServiceTest extends TestCase {

	use ExamFixtures;

	private ExamEventRepository&MockObject $events;
	private ExamSessionRepository&MockObject $sessions;
	private ExamRegistrationRepository&MockObject $registrations;
	private ExamParticipationRepository&MockObject $participations;
	private ExamParticipantRepository&MockObject $participants;
	private AssessmentAttemptRepository&MockObject $attempts;
	private AssessmentAnswerRepository&MockObject $answers;
	private StudentRecordRepository&MockObject $records;
	private ExamRoomService&MockObject $roomService;
	private ExamScoreService&MockObject $scores;
	private ExamAccessGuard&MockObject $guard;
	private \Inc\Services\Exam\ExamAccessTokenService&MockObject $tokens;
	private \Inc\Services\Exam\GuestParticipantMaterializer&MockObject $guestData;
	private \Inc\Services\Shared\PluginConfig&MockObject $config;
	private \Inc\Contracts\LogEventDispatcherInterface&MockObject $logEvents;
	private \Inc\Services\Exam\ExamGuestBoardService&MockObject $guestBoard;
	private ExamSourceRepository&MockObject $sources;
	private ExamTime&MockObject $time;
	private ExamConductViewService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->events         = $this->createMock( ExamEventRepository::class );
		$this->sessions       = $this->createMock( ExamSessionRepository::class );
		$this->registrations  = $this->createMock( ExamRegistrationRepository::class );
		$this->participations = $this->createMock( ExamParticipationRepository::class );
		$this->participants   = $this->createMock( ExamParticipantRepository::class );
		$this->attempts       = $this->createMock( AssessmentAttemptRepository::class );
		$this->answers        = $this->createMock( AssessmentAnswerRepository::class );
		$this->records        = $this->createMock( StudentRecordRepository::class );
		$this->roomService    = $this->createMock( ExamRoomService::class );
		$this->scores         = $this->createMock( ExamScoreService::class );
		$this->guard          = $this->createMock( ExamAccessGuard::class );
		$this->tokens         = $this->createMock( \Inc\Services\Exam\ExamAccessTokenService::class );
		$this->guestData      = $this->createMock( \Inc\Services\Exam\GuestParticipantMaterializer::class );
		$this->config         = $this->createMock( \Inc\Services\Shared\PluginConfig::class );
		$this->logEvents      = $this->createMock( \Inc\Contracts\LogEventDispatcherInterface::class );
		$this->guestBoard     = $this->createMock( \Inc\Services\Exam\ExamGuestBoardService::class );
		$this->guestBoard->method( 'holdsOf' )->willReturn( array() );
		$this->sources        = $this->createMock( ExamSourceRepository::class );
		$this->time           = $this->createMock( ExamTime::class );

		$this->time->method( 'nowUtc' )->willReturn( '2026-03-10 08:00:00' );
		$this->time->method( 'nowLocal' )->willReturn( '2026-03-10 11:00:00' );
		$this->time->method( 'toLocal' )->willReturnCallback( static fn ( string $utc ): string => gmdate( 'Y-m-d H:i:s', strtotime( $utc ) + 3 * 3600 ) );
		$this->time->method( 'toUtc' )->willReturnCallback( static fn ( string $local ): string => gmdate( 'Y-m-d H:i:s', strtotime( $local ) - 3 * 3600 ) );
		$this->time->method( 'secondsUntil' )->willReturnCallback( static fn ( string $from, string $to ): int => strtotime( $to ) - strtotime( $from ) );

		$this->guard->method( 'canManageEvent' )->willReturn( true );
		$this->guard->method( 'canManageEventGuests' )->willReturn( true );
		$this->sessions->method( 'find' )->willReturn( $this->examSession() );
		$this->sessions->method( 'findByEvent' )->willReturn( array( $this->examSession() ) );
		$this->events->method( 'find' )->willReturn( $this->examEvent( array( 'status' => 'published' ) ) );
		$this->scores->method( 'summarize' )->willReturn( array( 'primary' => 10, 'primary_max' => 20, 'secondary' => null, 'grade' => null, 'pending' => false ) );

		$this->service = new ExamConductViewService(
			$this->events,
			$this->sessions,
			$this->registrations,
			$this->participations,
			$this->participants,
			$this->attempts,
			new \Inc\Services\Exam\ExamResultStatusService( $this->answers ),
			$this->sources,
			new \Inc\Services\Exam\ExamParticipantNameService( $this->records, $this->guestData ),
			$this->createMock( RoomRepository::class ),
			$this->roomService,
			$this->scores,
			$this->guard,
			$this->time,
			$this->createMock( ExamPlanService::class ),
			$this->tokens,
			$this->guestBoard,
			new UserManager(),
			new \Inc\Services\Exam\ExamEventViewService( $this->sessions, $this->sources, $this->time ),
		);
	}

	private function registration( int $id, int $participationId, string $status = 'confirmed', ?string $arrivedAt = null ): ExamRegistrationDTO {
		return ExamRegistrationDTO::fromArray( array(
			'id' => $id, 'participation_id' => $participationId, 'session_id' => 7, 'status' => $status,
			'active_slot' => 'confirmed' === $status ? 1 : null, 'arrived_at' => $arrivedAt, 'created_at' => '2026-03-01 00:00:00',
		) );
	}

	private function participation( int $id, ?int $attemptId = null, string $audience = 'student' ): ExamParticipationDTO {
		return ExamParticipationDTO::fromArray( array(
			'id' => $id, 'event_id' => 3, 'participant_id' => $id + 100, 'audience' => $audience, 'current_attempt_id' => $attemptId,
			'transfer_allowed' => 0, 'version' => 1, 'created_at' => '2026-03-01 00:00:00', 'updated_at' => '2026-03-01 00:00:00',
		) );
	}

	private function participant( int $id, ?int $personId ): ExamParticipantDTO {
		return ExamParticipantDTO::fromArray( array( 'id' => $id, 'person_id' => $personId, 'created_at' => '2026-03-01 00:00:00', 'updated_at' => '2026-03-01 00:00:00' ) );
	}

	private function attempt( int $id, int $registrationId, string $status, ?string $approvedAt = null, int $participationId = 1 ): AttemptDTO {
		return AttemptDTO::fromArray( array(
			'id' => $id, 'assessment_id' => 500, 'student_person_id' => 1, 'attempt_number' => 1,
			'started_at' => '2026-03-10 10:00:00', 'deadline_at' => '2026-03-10 13:55:00', 'status' => $status,
			'approved_at' => $approvedAt, 'exam_participation_id' => $participationId, 'exam_registration_id' => $registrationId,
		) );
	}

	/**
	 * @param list<ExamRegistrationDTO>         $registrations
	 * @param array<int, ExamParticipationDTO>  $participations по ID участия
	 * @param array<int, AttemptDTO>            $attempts       по ID попытки
	 */
	private function givenBoard( array $registrations, array $participations, array $attempts = array() ): void {
		$this->registrations->method( 'listBySession' )->willReturn( $registrations );
		$this->participations->method( 'find' )->willReturnCallback( static fn ( int $id ): ?ExamParticipationDTO => $participations[ $id ] ?? null );
		$this->participants->method( 'find' )->willReturnCallback( fn ( int $id ): ExamParticipantDTO => $this->participant( $id, $id - 100 ) );
		$this->attempts->method( 'find' )->willReturnCallback( static fn ( int $id ): ?AttemptDTO => $attempts[ $id ] ?? null );
		$this->records->method( 'findActiveByStudentFirst' )->willReturn( StudentRecordDTO::fromArray( array(
			'id' => 1, 'student_person_id' => 1, 'snapshot_last_name' => 'Иванов', 'snapshot_first_name' => 'Пётр', 'status' => 'active',
		) ) );
	}

	public function test_board_tiles_count_registered_started_submitted_missed(): void {
		$this->givenBoard(
			array( $this->registration( 1, 1 ), $this->registration( 2, 2 ), $this->registration( 3, 3 ), $this->registration( 4, 4, 'missed' ), $this->registration( 5, 5, 'cancelled' ) ),
			array( 1 => $this->participation( 1, 11 ), 2 => $this->participation( 2, 12 ), 3 => $this->participation( 3 ), 4 => $this->participation( 4 ), 5 => $this->participation( 5 ) ),
			array( 11 => $this->attempt( 11, 1, 'in_progress' ), 12 => $this->attempt( 12, 2, 'submitted' ) )
		);
		$this->answers->method( 'hasPendingAnswers' )->willReturn( true );

		$tiles = $this->service->sessionBoard( 10, 7 )['tiles'];

		self::assertSame( array( 'registered' => 4, 'started' => 2, 'submitted' => 1, 'missed' => 1, 'pending' => 1 ), $tiles );
	}

	public function test_row_progress_for_each_state(): void {
		$this->givenBoard(
			array( $this->registration( 1, 1 ), $this->registration( 2, 2 ), $this->registration( 3, 3 ), $this->registration( 4, 4, 'missed' ) ),
			array( 1 => $this->participation( 1, 11 ), 2 => $this->participation( 2, 12 ), 3 => $this->participation( 3 ), 4 => $this->participation( 4 ) ),
			array( 11 => $this->attempt( 11, 1, 'in_progress' ), 12 => $this->attempt( 12, 2, 'graded' ) )
		);

		$rows = $this->service->sessionBoard( 10, 7 )['rows'];

		self::assertSame( array( 'in_progress', 'submitted', 'not_started', 'missed' ), array_column( $rows, 'progress' ) );
		self::assertSame( 175 * 60, $rows[0]['seconds_left'] );
		self::assertNull( $rows[1]['seconds_left'] );
	}

	public function test_result_status_pending_review_for_manual_tasks(): void {
		$this->answers->method( 'hasPendingAnswers' )->willReturn( true );

		self::assertSame( \Inc\Enums\Exam\ExamResultStatus::PendingReview, ( new \Inc\Services\Exam\ExamResultStatusService( $this->answers ) )->status( $this->attempt( 1, 1, 'submitted' ), ExamAudience::Student ) );
	}

	public function test_result_status_ready_then_approved_and_guest_never_waits(): void {
		$this->answers->method( 'hasPendingAnswers' )->willReturn( false );

		self::assertSame( \Inc\Enums\Exam\ExamResultStatus::None, ( new \Inc\Services\Exam\ExamResultStatusService( $this->answers ) )->status( null, ExamAudience::Student ) );
		self::assertSame( \Inc\Enums\Exam\ExamResultStatus::None, ( new \Inc\Services\Exam\ExamResultStatusService( $this->answers ) )->status( $this->attempt( 1, 1, 'in_progress' ), ExamAudience::Student ) );
		self::assertSame( \Inc\Enums\Exam\ExamResultStatus::Ready, ( new \Inc\Services\Exam\ExamResultStatusService( $this->answers ) )->status( $this->attempt( 1, 1, 'graded' ), ExamAudience::Student ) );
		self::assertSame( \Inc\Enums\Exam\ExamResultStatus::Approved, ( new \Inc\Services\Exam\ExamResultStatusService( $this->answers ) )->status( $this->attempt( 1, 1, 'graded', '2026-03-10 12:00:00' ), ExamAudience::Student ) );
		self::assertSame( \Inc\Enums\Exam\ExamResultStatus::Approved, ( new \Inc\Services\Exam\ExamResultStatusService( $this->answers ) )->status( $this->attempt( 1, 1, 'graded' ), ExamAudience::Guest ) );
	}

	public function test_missed_row_of_this_session_is_listed(): void {
		$this->givenBoard( array( $this->registration( 4, 4, 'missed' ) ), array( 4 => $this->participation( 4 ) ) );

		$rows = $this->service->sessionBoard( 10, 7 )['rows'];

		self::assertCount( 1, $rows );
		self::assertSame( 'missed', $rows[0]['registration_status'] );
		self::assertSame( array(), $rows[0]['actions'] );
	}

	public function test_cancelled_row_is_hidden_when_participation_has_confirmed_one(): void {
		$this->givenBoard(
			array( $this->registration( 1, 1, 'cancelled' ), $this->registration( 2, 1 ) ),
			array( 1 => $this->participation( 1 ) )
		);

		self::assertCount( 1, $this->service->sessionBoard( 10, 7 )['rows'] );
	}

	public function test_late_start_warning_present_when_room_busy_after_end(): void {
		$this->givenBoard(
			array( $this->registration( 1, 1 ) ),
			array( 1 => $this->participation( 1, 11 ) ),
			array( 11 => $this->attempt( 11, 1, 'in_progress' ) )
		);
		$this->roomService->expects( self::once() )->method( 'lateStartConflicts' )
			->willReturn( array( array( 'kind' => 'lesson', 'title' => 'Алгебра', 'start' => '2026-03-10 14:00:00' ) ) );

		$warnings = $this->service->sessionBoard( 10, 7 )['warnings'];

		self::assertSame( array( 'После планового конца в кабинете стоит занятие «Алгебра» в 14:00.' ), $warnings );
	}

	public function test_student_name_is_snapshot_not_decrypted_document(): void {
		$this->givenBoard( array( $this->registration( 1, 1 ) ), array( 1 => $this->participation( 1 ) ) );

		self::assertSame( 'Иванов Пётр', $this->service->sessionBoard( 10, 7 )['rows'][0]['name'] );
	}

	public function test_board_denied_for_foreign_event(): void {
		$guard = $this->createMock( ExamAccessGuard::class );
		$guard->method( 'canManageEvent' )->willReturn( false );
		$service = new ExamConductViewService(
			$this->events,
			$this->sessions,
			$this->registrations,
			$this->participations,
			$this->participants,
			$this->attempts,
			new \Inc\Services\Exam\ExamResultStatusService( $this->answers ),
			$this->createMock( ExamSourceRepository::class ),
			new \Inc\Services\Exam\ExamParticipantNameService( $this->records, $this->guestData ),
			$this->createMock( RoomRepository::class ),
			$this->roomService,
			$this->scores,
			$guard,
			$this->time,
			$this->createMock( ExamPlanService::class ),
			$this->tokens,
			$this->guestBoard,
			new UserManager(),
			new \Inc\Services\Exam\ExamEventViewService( $this->sessions, $this->sources, $this->time ),
		);

		$this->expectException( CodedException::class );
		$service->sessionBoard( 99, 7 );
	}

	public function test_row_actions_for_each_state(): void {
		$open = array( $this->examSession( array( 'id' => '8' ) ) );

		self::assertSame( array( 'cancel', 'transfer', 'arrival' ), $this->service->rowActions( $this->registration( 1, 1 ), null, $open ) );
		self::assertSame( array( 'cancel', 'arrival' ), $this->service->rowActions( $this->registration( 1, 1 ), null, array() ) );
		self::assertSame( array( 'extend' ), $this->service->rowActions( $this->registration( 1, 1 ), $this->attempt( 1, 1, 'in_progress' ), $open ) );
		self::assertSame( array( 'open_work' ), $this->service->rowActions( $this->registration( 1, 1 ), $this->attempt( 1, 1, 'submitted' ), $open ) );
		self::assertSame( array(), $this->service->rowActions( $this->registration( 1, 1, 'missed' ), null, $open ) );
	}

	// ── 8.8.2 допуск и 11b.1 ссылка входа ─────────────────────────────────────────────────────────

	// ── 11b.5 ссылка результата ──────────────────────────────────────────────────────────────────

	// ── 8.8.6 «Ссылка передана» ───────────────────────────────────────────────────────────────────

	// ── 8.8.1 гости в таблице сеанса ──────────────────────────────────────────────────────────────

	public function test_guest_row_has_four_independent_states(): void {
		$this->givenBoard(
			array( $this->registration( 1, 1 ) ),
			array( 1 => $this->participation( 1, null, 'guest' ) ),
			array()
		);
		$this->guestBoard->method( 'paymentOf' )->willReturn( array( 'state' => 'paid', 'label' => 'Оплачено' ) );

		$row = $this->service->sessionBoard( 10, 7 )['rows'][0];

		// Оплата, запись, допуск, попытка — отдельные поля, а не одна «галочка».
		self::assertSame( 'paid', $row['payment']['state'] );
		self::assertSame( 'confirmed', $row['registration_status'] );
		self::assertArrayHasKey( 'admitted_at', $row );
		self::assertNull( $row['admitted_at'] );
		self::assertSame( 'not_started', $row['progress'] );
	}

	public function test_board_without_guests_has_no_guest_columns(): void {
		$this->givenBoard(
			array( $this->registration( 1, 1 ) ),
			array( 1 => $this->participation( 1 ) ),
			array()
		);
		$this->guestBoard->expects( self::never() )->method( 'paymentOf' );

		$board = $this->service->sessionBoard( 10, 7 );

		self::assertNull( $board['rows'][0]['payment'] );
		self::assertSame( array(), $board['holds'] );
	}

	public function test_board_lists_holds_apart_from_rows(): void {
		$this->givenBoard( array(), array(), array() );
		$hold = array( 'application_id' => 9, 'name' => 'Иванов Пётр', 'hold_until' => '10:20', 'seconds_left' => 900, 'on_site' => true, 'actions' => array( 'copy_pay_link' ) );
		$this->guestBoard = $this->createMock( \Inc\Services\Exam\ExamGuestBoardService::class );
		$this->guestBoard->method( 'holdsOf' )->willReturn( array( $hold ) );
		$service = new ExamConductViewService(
			$this->events,
			$this->sessions,
			$this->registrations,
			$this->participations,
			$this->participants,
			$this->attempts,
			new \Inc\Services\Exam\ExamResultStatusService( $this->answers ),
			$this->sources,
			new \Inc\Services\Exam\ExamParticipantNameService( $this->records, $this->guestData ),
			$this->createMock( RoomRepository::class ),
			$this->roomService,
			$this->scores,
			$this->guard,
			$this->time,
			$this->createMock( ExamPlanService::class ),
			$this->tokens,
			$this->guestBoard,
			new UserManager(),
			new \Inc\Services\Exam\ExamEventViewService( $this->sessions, $this->sources, $this->time ),
		);

		$board = $service->sessionBoard( 10, 7 );

		self::assertSame( array(), $board['rows'], 'Бронь без записи — не строка доски: у неё нет записи, попытки и результата.' );
		self::assertSame( array( $hold ), $board['holds'] );
		self::assertSame( 0, $board['tiles']['registered'] );
	}
}
