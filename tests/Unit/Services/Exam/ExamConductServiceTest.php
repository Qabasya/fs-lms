<?php

declare( strict_types=1 );

namespace Tests\Unit\Services\Exam;

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
use Inc\Services\Exam\ExamConductService;
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
class ExamConductServiceTest extends TestCase {

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
	private ExamSourceRepository&MockObject $sources;
	private ExamTime&MockObject $time;
	private ExamConductService $service;

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

		$this->service = new ExamConductService(
			$this->events,
			$this->sessions,
			$this->registrations,
			$this->participations,
			$this->participants,
			$this->attempts,
			$this->answers,
			$this->sources,
			$this->records,
			$this->createMock( RoomRepository::class ),
			$this->roomService,
			$this->scores,
			$this->guard,
			$this->time,
			$this->createMock( ExamPlanService::class ),
			$this->createMock( ExamReviewProjection::class ),
			$this->tokens,
			$this->guestData,
			$this->config,
			$this->logEvents
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

		self::assertSame( 'pending_review', $this->service->resultStatus( $this->attempt( 1, 1, 'submitted' ), ExamAudience::Student ) );
	}

	public function test_result_status_ready_then_approved_and_guest_never_waits(): void {
		$this->answers->method( 'hasPendingAnswers' )->willReturn( false );

		self::assertSame( 'none', $this->service->resultStatus( null, ExamAudience::Student ) );
		self::assertSame( 'none', $this->service->resultStatus( $this->attempt( 1, 1, 'in_progress' ), ExamAudience::Student ) );
		self::assertSame( 'ready', $this->service->resultStatus( $this->attempt( 1, 1, 'graded' ), ExamAudience::Student ) );
		self::assertSame( 'approved', $this->service->resultStatus( $this->attempt( 1, 1, 'graded', '2026-03-10 12:00:00' ), ExamAudience::Student ) );
		self::assertSame( 'approved', $this->service->resultStatus( $this->attempt( 1, 1, 'graded' ), ExamAudience::Guest ) );
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
		$service = new ExamConductService(
			$this->events, $this->sessions, $this->registrations, $this->participations, $this->participants, $this->attempts, $this->answers,
			$this->createMock( ExamSourceRepository::class ), $this->records, $this->createMock( RoomRepository::class ), $this->roomService,
			$this->scores, $guard, $this->time, $this->createMock( ExamPlanService::class ), $this->createMock( ExamReviewProjection::class ),
			$this->tokens,
			$this->guestData,
			$this->config,
			$this->logEvents
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

	public function test_mark_arrival_sets_and_clears(): void {
		$this->registrations->method( 'find' )->willReturn( $this->registration( 1, 1 ) );
		$this->participations->method( 'find' )->willReturn( $this->participation( 1 ) );
		$calls = array();
		$this->registrations->method( 'setArrival' )->willReturnCallback( static function ( int $id, ?string $at, ?int $by ) use ( &$calls ): void {
			$calls[] = array( $at, $by );
		} );

		$this->service->markArrival( 10, 1, true );
		$this->service->markArrival( 10, 1, false );

		self::assertSame( '2026-03-10 08:00:00', $calls[0][0] );
		self::assertSame( 10, $calls[0][1] );
		self::assertSame( array( null, null ), $calls[1] );
	}

	public function test_arrival_does_not_change_registration_status(): void {
		$this->registrations->method( 'find' )->willReturn( $this->registration( 1, 1 ) );
		$this->participations->method( 'find' )->willReturn( $this->participation( 1 ) );
		$this->registrations->expects( self::never() )->method( 'deactivate' );
		$this->participations->expects( self::never() )->method( 'setActiveRegistration' );

		$this->service->markArrival( 10, 1, true );
	}

	/* ── 8.7 Результаты ───────────────────────────────────────────────────── */

	/** Два участия проведения: 1 — работа готова, 2 — ждёт проверки; 3 — гость с готовой работой. */
	private function givenResults(): void {
		$this->events->method( 'findBySubjectKey' )->willReturn( array( $this->examEvent( array( 'status' => 'published' ) ) ) );
		$p = array( 1 => $this->participation( 1, 11 ), 2 => $this->participation( 2, 12 ), 3 => $this->participation( 3, 13, 'guest' ) );
		$this->participations->method( 'findByEvent' )->willReturn( array_values( $p ) );
		$this->attempts->method( 'listByParticipations' )->willReturn( array(
			$this->attempt( 11, 1, 'graded', null, 1 ), $this->attempt( 12, 2, 'submitted', null, 2 ), $this->attempt( 13, 3, 'graded', null, 3 ),
		) );
		$this->participants->method( 'find' )->willReturnCallback( fn ( int $id ): ExamParticipantDTO => $this->participant( $id, $id > 102 ? null : $id - 100 ) );
		$this->registrations->method( 'find' )->willReturn( $this->registration( 1, 1 ) );
		$this->records->method( 'findActiveByStudentFirst' )->willReturn( StudentRecordDTO::fromArray( array(
			'id' => 1, 'student_person_id' => 1, 'snapshot_last_name' => 'Иванов', 'snapshot_first_name' => 'Пётр', 'status' => 'active',
		) ) );
		$this->answers->method( 'hasPendingAnswers' )->willReturnCallback( static fn ( int $id ): bool => 12 === $id );
	}

	public function test_results_filter_by_status(): void {
		$this->givenResults();

		$pending = $this->service->results( 10, 'inf_ege', array( 'status' => 'pending_review' ) )['items'];
		$ready   = $this->service->results( 10, 'inf_ege', array( 'status' => 'ready' ) )['items'];
		$all     = $this->service->results( 10, 'inf_ege', array( 'status' => 'all' ) )['items'];

		self::assertSame( array( 12 ), array_column( $pending, 'attempt_id' ) );
		self::assertSame( array( 11 ), array_column( $ready, 'attempt_id' ) );
		self::assertCount( 3, $all );
	}

	public function test_results_filter_by_audience_and_source(): void {
		$this->givenResults();

		$guests   = $this->service->results( 10, 'inf_ege', array( 'audience' => 'guest' ) )['items'];
		$students = $this->service->results( 10, 'inf_ege', array( 'audience' => 'student' ) )['items'];
		$noSource = $this->service->results( 10, 'inf_ege', array( 'source_id' => 99 ) )['items'];

		self::assertSame( array( 13 ), array_column( $guests, 'attempt_id' ) );
		self::assertSame( array( 11, 12 ), array_column( $students, 'attempt_id' ) );
		self::assertSame( array(), $noSource );
	}

	public function test_results_exclude_foreign_events(): void {
		$this->events->method( 'findBySubjectKey' )->willReturn( array( $this->examEvent( array( 'status' => 'published' ) ) ) );
		$guard = $this->createMock( ExamAccessGuard::class );
		$guard->method( 'canManageEvent' )->willReturn( false );
		$service = new ExamConductService(
			$this->events, $this->sessions, $this->registrations, $this->participations, $this->participants, $this->attempts, $this->answers,
			$this->sources, $this->records, $this->createMock( RoomRepository::class ), $this->roomService,
			$this->scores, $guard, $this->time, $this->createMock( ExamPlanService::class ), $this->createMock( ExamReviewProjection::class ),
			$this->tokens,
			$this->guestData,
			$this->config,
			$this->logEvents
		);
		$this->participations->expects( self::never() )->method( 'findByEvent' );

		$result = $service->results( 99, 'inf_ege', array() );

		self::assertSame( array(), $result['items'] );
		self::assertSame( array(), $result['filters']['events'] );
	}

	// ── 8.8.2 допуск и 11b.1 ссылка входа ─────────────────────────────────────────────────────────

	private function guestParticipation( array $over = array() ): ExamParticipationDTO {
		return ExamParticipationDTO::fromArray( array_merge( array(
			'id' => '4', 'event_id' => '3', 'participant_id' => '9', 'audience' => 'guest', 'active_registration_id' => '1', 'current_attempt_id' => null,
			'source_id' => '14', 'consent_refs' => null, 'transfer_allowed' => '0', 'admitted_at' => '2026-03-10 06:30:00', 'admitted_by_user_id' => '10',
			'version' => '1', 'created_at' => '2026-03-01 00:00:00', 'updated_at' => '2026-03-01 00:00:00',
		), $over ) );
	}

	public function test_entry_link_requires_admission_and_confirmed_registration(): void {
		$this->participations->method( 'find' )->willReturnOnConsecutiveCalls( $this->guestParticipation( array( 'admitted_at' => null ) ), $this->guestParticipation( array( 'active_registration_id' => null ) ) );
		$this->registrations->method( 'find' )->willReturn( $this->registration( 1, 4 ) );
		$this->tokens->expects( self::never() )->method( 'issue' );

		foreach ( array( 'Сначала отметьте допуск участника.', 'У участника нет действующей записи.' ) as $message ) {
			try {
				$this->service->issueEntryLink( 10, 4 );
				self::fail( 'Ожидался отказ.' );
			} catch ( CodedException $e ) {
				self::assertSame( $message, $e->getMessage() );
			}
		}
	}

	public function test_entry_link_denied_for_student_participation(): void {
		$this->participations->method( 'find' )->willReturn( $this->guestParticipation( array( 'audience' => 'student' ) ) );
		$this->tokens->expects( self::never() )->method( 'issue' );

		$this->expectException( CodedException::class );
		$this->service->issueEntryLink( 10, 4 );
	}

	public function test_entry_link_does_not_require_export_pii(): void {
		$GLOBALS['_fs_test_can_callback'] = static fn ( string $cap ): bool => 'export_pii' !== $cap && 'manage_lms_platform' !== $cap;
		$this->participations->method( 'find' )->willReturn( $this->guestParticipation() );
		$this->registrations->method( 'find' )->willReturn( $this->registration( 1, 4 ) );
		$this->tokens->expects( self::once() )->method( 'issue' )->willReturn( str_repeat( 'a', 64 ) );

		$url = $this->service->issueEntryLink( 10, 4 );
		unset( $GLOBALS['_fs_test_can_callback'] );

		self::assertStringContainsString( '?k=' . str_repeat( 'a', 64 ), $url );
	}

	public function test_reissue_revokes_previous_key(): void {
		$this->participations->method( 'find' )->willReturn( $this->guestParticipation() );
		$this->registrations->method( 'find' )->willReturn( $this->registration( 1, 4 ) );
		// issue() сам отзывает прежние ключи цели (ExamAccessTokenService); здесь проверяем, что вызов идёт с целью-участием и сроком до конца сеанса.
		$this->tokens->expects( self::once() )->method( 'issue' )->with( \Inc\Enums\Exam\ExamTokenPurpose::Entry, 4, 10, self::isType( 'string' ) )->willReturn( str_repeat( 'b', 64 ) );

		$this->service->issueEntryLink( 10, 4 );
	}

	public function test_admit_requires_manage_exam_guests(): void {
		$guard = $this->createMock( ExamAccessGuard::class );
		$guard->method( 'canManageEventGuests' )->willReturn( false );
		$service = new ExamConductService(
			$this->events, $this->sessions, $this->registrations, $this->participations, $this->participants, $this->attempts, $this->answers,
			$this->sources, $this->records, $this->createMock( RoomRepository::class ), $this->roomService,
			$this->scores, $guard, $this->time, $this->createMock( ExamPlanService::class ), $this->createMock( ExamReviewProjection::class ), $this->tokens, $this->guestData, $this->config, $this->logEvents
		);
		$this->participations->method( 'find' )->willReturn( $this->guestParticipation() );
		$this->participations->expects( self::never() )->method( 'setAdmission' );

		$this->expectException( CodedException::class );
		$service->admit( 10, 4, true );
	}

	public function test_admit_sets_and_clears_admission(): void {
		$this->participations->method( 'find' )->willReturn( $this->guestParticipation( array( 'admitted_at' => null ) ) );
		$this->registrations->method( 'find' )->willReturn( $this->registration( 1, 4 ) );
		$this->participations->expects( self::once() )->method( 'setAdmission' )->with( 4, self::isType( 'string' ), 10 );

		$this->service->admit( 10, 4, true );
	}

	public function test_admission_cannot_change_after_attempt_started(): void {
		$this->participations->method( 'find' )->willReturn( $this->guestParticipation( array( 'current_attempt_id' => '55' ) ) );
		$this->registrations->method( 'find' )->willReturn( $this->registration( 1, 4 ) );
		$this->participations->expects( self::never() )->method( 'setAdmission' );

		$this->expectException( CodedException::class );
		$this->service->admit( 10, 4, false );
	}

	// ── 11b.5 ссылка результата ──────────────────────────────────────────────────────────────────

	public function test_result_link_requires_share_cap_and_submitted_attempt(): void {
		$GLOBALS['_test_user_can'][10]['share_lms_exam_results'] = true;
		$this->participations->method( 'find' )->willReturn( $this->guestParticipation( array( 'current_attempt_id' => '55' ) ) );
		$this->attempts->method( 'find' )->willReturnOnConsecutiveCalls( $this->attempt( 9, 4, 'in_progress' ), $this->attempt( 9, 4, 'submitted' ) );
		$this->config->method( 'examGuestRetentionDays' )->willReturn( 30 );
		$this->tokens->expects( self::once() )->method( 'issue' )->with( \Inc\Enums\Exam\ExamTokenPurpose::Result, 4, 10, self::isType( 'string' ) )->willReturn( str_repeat( 'c', 64 ) );

		try {
			$this->service->issueResultLink( 10, 4 );
			self::fail( 'Пока работа не сдана, ссылку результата выдать нельзя.' );
		} catch ( CodedException $e ) {
			self::assertSame( 'Ссылку результата можно выдать после сдачи работы.', $e->getMessage() );
		}

		self::assertStringContainsString( '?k=' . str_repeat( 'c', 64 ), $this->service->issueResultLink( 10, 4 ) );

		$GLOBALS['_test_user_can'][10]['share_lms_exam_results'] = false;
		$this->expectException( CodedException::class );
		$this->service->issueResultLink( 10, 4 );
	}

	public function test_result_link_denied_for_student_participation(): void {
		$GLOBALS['_test_user_can'][10]['share_lms_exam_results'] = true;
		$this->participations->method( 'find' )->willReturn( $this->guestParticipation( array( 'audience' => 'student', 'current_attempt_id' => '55' ) ) );
		$this->tokens->expects( self::never() )->method( 'issue' );

		$this->expectException( CodedException::class );
		$this->service->issueResultLink( 10, 4 );
	}

	public function test_revoke_result_link_keeps_entry_session(): void {
		$GLOBALS['_test_user_can'][10]['share_lms_exam_results'] = true;
		$this->participations->method( 'find' )->willReturn( $this->guestParticipation() );
		// Отзывается только ключ назначения «результат»; ключ входа и сессии входа не затрагиваются.
		$this->tokens->expects( self::once() )->method( 'revoke' )->with( \Inc\Enums\Exam\ExamTokenPurpose::Result, 4 );

		$this->service->revokeResultLink( 10, 4 );
	}
}
