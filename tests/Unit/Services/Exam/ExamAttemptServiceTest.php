<?php

declare( strict_types=1 );

namespace Tests\Unit\Services\Exam;

use Inc\Contracts\ClockInterface;
use Inc\DTO\Assessment\AttemptDTO;
use Inc\DTO\Assessment\AttemptInputDTO;
use Inc\DTO\Exam\AttemptContext;
use Inc\DTO\Exam\ExamEventDTO;
use Inc\DTO\Exam\ExamParticipantDTO;
use Inc\DTO\Exam\ExamParticipationDTO;
use Inc\DTO\Exam\ExamRegistrationDTO;
use Inc\DTO\Exam\ExamSessionDTO;
use Inc\DTO\Person\PersonDTO;
use Inc\Enums\Exam\ExamAudience;
use Inc\Enums\Exam\ExamOutboxEvent;
use Inc\Enums\Log\ErrorCode;
use Inc\Managers\Assessment\AssessmentManager;
use Inc\Repositories\WPDBRepositories\AssessmentAttemptRepository;
use Inc\Repositories\WPDBRepositories\ExamEventRepository;
use Inc\Repositories\WPDBRepositories\ExamParticipantRepository;
use Inc\Repositories\WPDBRepositories\ExamParticipationRepository;
use Inc\Repositories\WPDBRepositories\ExamRegistrationRepository;
use Inc\Repositories\WPDBRepositories\ExamSessionRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Services\Assessment\AttemptService;
use Inc\Services\Exam\ExamAccessGuard;
use Inc\Services\Exam\ExamAttemptService;
use Inc\Services\Exam\ExamFormatRegistry;
use Inc\Services\Exam\ExamNoShowService;
use Inc\Services\Exam\ExamOutbox;
use Inc\Services\Exam\ExamTime;
use Inc\Shared\CodedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Жизненный цикл официальной попытки: старт, сохранение и сдача под одной блокировкой участия,
 * автоистечение по дедлайну, доступ только владельцу.
 *
 * Время сеанса — UTC, время попытки — местное (МСК, +3).
 */
class ExamAttemptServiceTest extends TestCase {

	private const PARTICIPATION = 5;
	private const REGISTRATION  = 6;
	private const PERSON        = 11;
	private const ASSESSMENT    = 50;

	private ExamParticipationRepository&MockObject $participations;
	private ExamParticipantRepository&MockObject $participants;
	private ExamRegistrationRepository&MockObject $registrations;
	private ExamSessionRepository&MockObject $sessions;
	private ExamEventRepository&MockObject $events;
	private AssessmentAttemptRepository&MockObject $attempts;
	private PersonRepository&MockObject $persons;
	private AttemptService&MockObject $attemptService;
	private ExamNoShowService&MockObject $noShow;
	private ExamOutbox&MockObject $outbox;
	private ExamAccessGuard&MockObject $accessGuard;
	private \Inc\Services\Exam\GuestSessionService&MockObject $guestSessions;
	private ExamAttemptService $service;

	/** @var array{utc: string, local: string} Текущее время теста. */
	private array $now = array( 'utc' => '2026-03-12 07:00:00', 'local' => '2026-03-12 10:00:00' );

	protected function setUp(): void {
		parent::setUp();

		$this->participations = $this->createMock( ExamParticipationRepository::class );
		$this->participants   = $this->createMock( ExamParticipantRepository::class );
		$this->registrations  = $this->createMock( ExamRegistrationRepository::class );
		$this->sessions       = $this->createMock( ExamSessionRepository::class );
		$this->events         = $this->createMock( ExamEventRepository::class );
		$this->attempts       = $this->createMock( AssessmentAttemptRepository::class );
		$this->persons        = $this->createMock( PersonRepository::class );
		$this->attemptService = $this->createMock( AttemptService::class );
		$this->noShow         = $this->createMock( ExamNoShowService::class );
		$this->outbox         = $this->createMock( ExamOutbox::class );
		$this->accessGuard    = $this->createMock( ExamAccessGuard::class );
		$this->guestSessions  = $this->createMock( \Inc\Services\Exam\GuestSessionService::class );

		$clock = $this->createMock( ClockInterface::class );
		$clock->method( 'now' )->willReturnCallback( fn ( string $type = 'mysql', bool $gmt = false ): string => $gmt ? $this->now['utc'] : $this->now['local'] );

		$this->service = new ExamAttemptService(
			$this->participations, $this->participants, $this->registrations, $this->sessions, $this->events, $this->attempts,
			$this->persons, $this->createMock( AssessmentManager::class ), $this->attemptService, $this->noShow,
			$this->createMock( ExamFormatRegistry::class ), $this->accessGuard, $this->outbox, new ExamTime( $clock ),
			$this->guestSessions,
		);
	}

	private function setNow( string $utc, string $local ): void {
		$this->now = array( 'utc' => $utc, 'local' => $local );
	}

	private function ctx(): AttemptContext {
		return new AttemptContext( ExamAudience::Student, self::PARTICIPATION, self::REGISTRATION, self::PERSON, 42 );
	}

	private function participation( ?int $currentAttemptId = null ): ExamParticipationDTO {
		return ExamParticipationDTO::fromArray( array(
			'id' => self::PARTICIPATION, 'event_id' => 1, 'participant_id' => 4, 'audience' => 'student', 'current_attempt_id' => $currentAttemptId,
			'transfer_allowed' => 0, 'version' => 7, 'created_at' => '2026-03-01 00:00:00', 'updated_at' => '2026-03-01 00:00:00',
		) );
	}

	private function registration( int $activeSlot = 1, string $status = 'confirmed' ): ExamRegistrationDTO {
		return ExamRegistrationDTO::fromArray( array(
			'id' => self::REGISTRATION, 'participation_id' => self::PARTICIPATION, 'session_id' => 100, 'status' => $status,
			'active_slot' => $activeSlot, 'created_at' => '2026-03-01 00:00:00',
		) );
	}

	/** Сеанс 07:00–10:55 UTC, то есть 10:00–13:55 по Москве. */
	private function session( string $status = 'open' ): ExamSessionDTO {
		return ExamSessionDTO::fromArray( array(
			'id' => 100, 'event_id' => 1, 'assessment_id' => self::ASSESSMENT, 'scheduled_at' => '2026-03-12 07:00:00',
			'planned_end_at' => '2026-03-12 10:55:00', 'room_id' => 1, 'capacity' => 10, 'occupied_count' => 1, 'responsible_user_id' => 1,
			'status' => $status, 'version' => 1, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
		) );
	}

	private function event(): ExamEventDTO {
		return ExamEventDTO::fromArray( array(
			'id' => 1, 'subject_key' => 'inf', 'title' => 'Экзамен', 'owner_user_id' => 1, 'status' => 'published',
			'period_from' => '2026-03-01', 'period_to' => '2026-03-31', 'guest_registration_enabled' => 0, 'version' => 1,
			'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
			'variant_snapshot' => json_encode( array( (string) self::ASSESSMENT => array( 'duration_minutes' => 235 ) ) ),
		) );
	}

	private function attempt( string $status = 'in_progress', string $deadline = '2026-03-12 13:55:00', ?int $participationId = self::PARTICIPATION ): AttemptDTO {
		return AttemptDTO::fromArray( array(
			'id' => 9, 'assessment_id' => self::ASSESSMENT, 'student_person_id' => self::PERSON, 'group_id' => null, 'attempt_number' => 1,
			'status' => $status, 'started_at' => '2026-03-12 10:00:00', 'deadline_at' => $deadline,
			'exam_participation_id' => $participationId, 'exam_registration_id' => self::REGISTRATION,
		) );
	}

	/* ── Сохранение ответа ── */

	public function test_save_answer_is_written_under_participation_lock(): void {
		$order = array();
		$this->attempts->method( 'find' )->willReturn( $this->attempt() );
		$this->participations->method( 'findForUpdate' )->willReturnCallback( function () use ( &$order ): ExamParticipationDTO {
			$order[] = 'lock';
			return $this->participation();
		} );
		$this->attemptService->expects( self::once() )->method( 'saveAnswerFor' )->willReturnCallback( function () use ( &$order ): void {
			$order[] = 'write';
		} );

		$this->service->saveAnswer( $this->ctx(), 9, 3, 'ответ' );

		self::assertSame( array( 'lock', 'write' ), $order );
	}

	public function test_save_answer_is_refused_when_attempt_was_submitted_before_the_lock_was_taken(): void {
		// Автосохранение проверило in_progress, а до блокировки сдача уже завершила и оценила работу.
		$this->attempts->method( 'find' )->willReturnOnConsecutiveCalls( $this->attempt(), $this->attempt( 'submitted' ) );
		$this->participations->method( 'findForUpdate' )->willReturn( $this->participation() );
		$this->attemptService->expects( self::never() )->method( 'saveAnswerFor' );

		try {
			$this->service->saveAnswer( $this->ctx(), 9, 3, 'поздний ответ' );
			self::fail( 'Ответ в сданную попытку писать нельзя.' );
		} catch ( CodedException $e ) {
			self::assertSame( ErrorCode::ExamStarted, $e->errorCode );
		}
	}

	public function test_save_answer_is_refused_when_deadline_passed_before_the_lock_was_taken(): void {
		$this->attempts->method( 'find' )->willReturnOnConsecutiveCalls( $this->attempt(), $this->attempt( 'in_progress', '2026-03-12 09:59:59' ) );
		$this->participations->method( 'findForUpdate' )->willReturn( $this->participation() );
		$this->attemptService->expects( self::never() )->method( 'saveAnswerFor' );

		$this->expectException( CodedException::class );
		$this->expectExceptionMessage( 'Время попытки истекло.' );

		$this->service->saveAnswer( $this->ctx(), 9, 3, 'x' );
	}

	public function test_save_denied_for_foreign_participation(): void {
		$this->attempts->method( 'find' )->willReturn( $this->attempt( 'in_progress', '2026-03-12 13:55:00', 99 ) );
		$this->attemptService->expects( self::never() )->method( 'saveAnswerFor' );

		try {
			$this->service->saveAnswer( $this->ctx(), 9, 3, 'x' );
			self::fail( 'Чужая попытка должна отвечать как несуществующая.' );
		} catch ( CodedException $e ) {
			self::assertSame( ErrorCode::ExamAccess, $e->errorCode );
		}
	}

	public function test_save_after_deadline_finalizes_at_deadline_and_reports_time_over(): void {
		$this->setNow( '2026-03-12 11:00:00', '2026-03-12 14:00:00' );
		$expired = $this->attempt( 'in_progress', '2026-03-12 13:55:00' );
		$this->attempts->method( 'find' )->willReturn( $expired );
		$this->participations->method( 'findForUpdate' )->willReturn( $this->participation() );
		// Сдача по дедлайну: момент сдачи — сам дедлайн, а не момент срабатывания.
		$this->attemptService->expects( self::once() )->method( 'submitFor' )->with( $expired, '2026-03-12 13:55:00' );
		$this->attemptService->expects( self::never() )->method( 'saveAnswerFor' );

		$this->expectException( CodedException::class );
		$this->expectExceptionMessage( 'Время попытки истекло.' );

		$this->service->saveAnswer( $this->ctx(), 9, 3, 'x' );
	}

	/* ── Сдача и автоистечение ── */

	public function test_submit_writes_outbox_and_grades(): void {
		$attempt   = $this->attempt();
		$submitted = $this->attempt( 'submitted' );
		$this->attempts->method( 'find' )->willReturn( $attempt );
		$this->participations->method( 'findForUpdate' )->willReturn( $this->participation() );
		$this->attemptService->expects( self::once() )->method( 'submitFor' )->with( $attempt )->willReturn( $submitted );
		$this->outbox->expects( self::once() )->method( 'add' )->with(
			ExamOutboxEvent::AttemptSubmitted, 'participation', self::PARTICIPATION, 7, array( 'attempt_id' => 9, 'auto' => false )
		);

		self::assertSame( $submitted, $this->service->submit( $this->ctx(), 9 ) );
	}

	public function test_submit_is_refused_when_attempt_finished_before_the_lock_was_taken(): void {
		$this->attempts->method( 'find' )->willReturnOnConsecutiveCalls( $this->attempt(), $this->attempt( 'submitted' ) );
		$this->participations->method( 'findForUpdate' )->willReturn( $this->participation() );
		$this->attemptService->expects( self::never() )->method( 'submitFor' );
		$this->outbox->expects( self::never() )->method( 'add' );

		$this->expectException( CodedException::class );

		$this->service->submit( $this->ctx(), 9 );
	}

	public function test_finalize_expired_submits_at_deadline_and_grades_saved_answers(): void {
		$this->setNow( '2026-03-12 11:00:00', '2026-03-12 14:00:00' );
		$expired = $this->attempt( 'in_progress', '2026-03-12 13:55:00' );
		$this->attempts->method( 'find' )->willReturn( $expired );
		$this->participations->method( 'findForUpdate' )->willReturn( $this->participation() );
		// Общее тело сдачи оценивает сохранённые ответы; момент сдачи — дедлайн, а не момент срабатывания.
		$this->attemptService->expects( self::once() )->method( 'submitFor' )->with( $expired, '2026-03-12 13:55:00' );

		self::assertTrue( $this->service->finalizeExpired( 9 ) );
	}

	public function test_finalize_writes_outbox_with_auto_flag(): void {
		$this->setNow( '2026-03-12 11:00:00', '2026-03-12 14:00:00' );
		$expired = $this->attempt( 'in_progress', '2026-03-12 13:55:00' );
		$this->attempts->method( 'find' )->willReturn( $expired );
		$this->participations->method( 'findForUpdate' )->willReturn( $this->participation() );
		$this->attemptService->expects( self::once() )->method( 'submitFor' )->with( $expired, '2026-03-12 13:55:00' );
		$this->outbox->expects( self::once() )->method( 'add' )->with(
			ExamOutboxEvent::AttemptSubmitted, 'participation', self::PARTICIPATION, 7, array( 'attempt_id' => 9, 'auto' => true )
		);

		self::assertTrue( $this->service->finalizeExpired( 9 ) );
	}

	public function test_finalize_expired_is_idempotent(): void {
		$this->setNow( '2026-03-12 11:00:00', '2026-03-12 14:00:00' );
		$this->attempts->method( 'find' )->willReturn( $this->attempt( 'submitted' ) );
		$this->participations->method( 'findForUpdate' )->willReturn( $this->participation() );
		$this->attemptService->expects( self::never() )->method( 'submitFor' );
		$this->outbox->expects( self::never() )->method( 'add' );

		self::assertFalse( $this->service->finalizeExpired( 9 ) );
	}

	public function test_finalize_does_not_touch_attempt_before_its_deadline(): void {
		$this->attempts->method( 'find' )->willReturn( $this->attempt() );
		$this->participations->method( 'findForUpdate' )->willReturn( $this->participation() );
		$this->attemptService->expects( self::never() )->method( 'submitFor' );

		self::assertFalse( $this->service->finalizeExpired( 9 ) );
	}

	/* ── Доступ ── */

	public function test_context_for_attempt_is_null_for_course_attempt(): void {
		$this->attempts->method( 'find' )->willReturn( $this->attempt( 'in_progress', '2026-03-12 13:55:00', null ) );

		self::assertNull( $this->service->contextForAttempt( 42, 9 ) );
	}

	public function test_context_for_attempt_is_null_for_unknown_attempt(): void {
		$this->attempts->method( 'find' )->willReturn( null );

		self::assertNull( $this->service->contextForAttempt( 42, 9 ) );
	}

	public function test_context_for_foreign_exam_attempt_is_denied(): void {
		$this->attempts->method( 'find' )->willReturn( $this->attempt() );
		$this->registrations->method( 'find' )->willReturn( $this->registration() );
		$this->participations->method( 'find' )->willReturn( $this->participation() );
		$this->participants->method( 'find' )->willReturn( ExamParticipantDTO::fromArray( array(
			'id' => 4, 'person_id' => 777, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
		) ) );
		$this->persons->method( 'findByWpUserId' )->willReturn( PersonDTO::fromArray( array(
			'id' => self::PERSON, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
		) ) );

		try {
			$this->service->contextForAttempt( 42, 9 );
			self::fail( 'Чужая экзаменная попытка должна быть закрыта.' );
		} catch ( CodedException $e ) {
			self::assertSame( ErrorCode::ExamAccess, $e->errorCode );
		}
	}

	/* ── Состояние станции ── */

	public function test_station_state_finalizes_expired_attempt_and_returns_fresh_one(): void {
		$this->setNow( '2026-03-12 11:00:00', '2026-03-12 14:00:00' );
		$expired = $this->attempt( 'in_progress', '2026-03-12 13:55:00' );
		$done    = $this->attempt( 'submitted', '2026-03-12 13:55:00' );
		$this->registrations->method( 'find' )->willReturn( $this->registration() );
		$this->sessions->method( 'find' )->willReturn( $this->session() );
		$this->participations->method( 'find' )->willReturn( $this->participation( 9 ) );
		$this->participations->method( 'findForUpdate' )->willReturn( $this->participation( 9 ) );
		// 1) станция, 2) завершение (прочитать и перечитать под блокировкой), 3) свежее состояние.
		$this->attempts->method( 'find' )->willReturnOnConsecutiveCalls( $expired, $expired, $expired, $done );
		$this->attemptService->expects( self::once() )->method( 'submitFor' );

		$state = $this->service->stationState( $this->ctx() );

		self::assertSame( 'submitted', $state['attempt']->status->value );
		self::assertSame( self::ASSESSMENT, $state['assessment_id'] );
	}

	public function test_station_state_is_null_for_closed_registration_without_attempt(): void {
		$this->registrations->method( 'find' )->willReturn( $this->registration( 0, 'cancelled' ) );
		$this->sessions->method( 'find' )->willReturn( $this->session() );
		$this->participations->method( 'find' )->willReturn( $this->participation() );

		self::assertNull( $this->service->stationState( $this->ctx() ) );
	}

	public function test_station_state_for_open_registration_has_variant_and_no_attempt(): void {
		$this->registrations->method( 'find' )->willReturn( $this->registration() );
		$this->sessions->method( 'find' )->willReturn( $this->session() );
		$this->participations->method( 'find' )->willReturn( $this->participation() );

		$state = $this->service->stationState( $this->ctx() );

		self::assertSame( array( 'assessment_id' => self::ASSESSMENT, 'attempt' => null ), $state );
	}

	/* ── Старт ── */

	private function arrangeStart( ?ExamParticipationDTO $participation = null ): void {
		$this->participations->method( 'findForUpdate' )->willReturn( $participation ?? $this->participation() );
		$this->registrations->method( 'find' )->willReturn( $this->registration() );
		$this->sessions->method( 'find' )->willReturn( $this->session() );
		$this->events->method( 'find' )->willReturn( $this->event() );
	}

	public function test_start_denied_at_09_59(): void {
		$this->setNow( '2026-03-12 06:59:00', '2026-03-12 09:59:00' );
		$this->arrangeStart();
		$this->attempts->expects( self::never() )->method( 'create' );

		try {
			$this->service->start( $this->ctx() );
			self::fail( 'До начала сеанса старт закрыт.' );
		} catch ( CodedException $e ) {
			self::assertSame( ErrorCode::ExamNotOpen, $e->errorCode );
		}
	}

	public function test_start_at_13_54_gets_deadline_17_49(): void {
		// 13:54 по Москве, длительность 235 минут → дедлайн 17:49: плановый конец сеанса дедлайн не обрезает.
		$this->setNow( '2026-03-12 10:54:00', '2026-03-12 13:54:00' );
		$this->arrangeStart();
		$this->attempts->method( 'findAnyActive' )->willReturn( null );
		$this->attempts->method( 'nextAttemptNumber' )->willReturn( 1 );
		$captured = null;
		$this->attempts->method( 'create' )->willReturnCallback( function ( AttemptInputDTO $dto ) use ( &$captured ): int {
			$captured = $dto;
			return 9;
		} );
		$this->attempts->method( 'find' )->willReturn( $this->attempt( 'in_progress', '2026-03-12 17:49:00' ) );
		$this->participations->expects( self::once() )->method( 'setCurrentAttempt' )->with( self::PARTICIPATION, 9 );
		$this->sessions->expects( self::once() )->method( 'markFirstStarted' );
		$this->outbox->expects( self::once() )->method( 'add' )->with( ExamOutboxEvent::AttemptStarted );

		$this->service->start( $this->ctx() );

		self::assertSame( '2026-03-12 13:54:00', $captured->startedAt );
		self::assertSame( '2026-03-12 17:49:00', $captured->deadlineAt );
		self::assertSame( self::PARTICIPATION, $captured->examParticipationId );
		self::assertSame( self::REGISTRATION, $captured->examRegistrationId );
		self::assertNull( $captured->groupId );
	}

	public function test_start_denied_at_13_55_and_marks_missed(): void {
		$this->setNow( '2026-03-12 10:55:00', '2026-03-12 13:55:00' );
		$this->arrangeStart();
		$this->noShow->expects( self::once() )->method( 'markMissedLocked' )->with(
			self::callback( static fn ( ExamParticipationDTO $p ): bool => self::PARTICIPATION === $p->id ),
			self::callback( static fn ( ExamRegistrationDTO $r ): bool => self::REGISTRATION === $r->id ),
			self::callback( static fn ( ExamSessionDTO $x ): bool => 100 === $x->id )
		)->willReturn( true );
		$this->attempts->expects( self::never() )->method( 'create' );
		$this->sessions->expects( self::never() )->method( 'markFirstStarted' );

		try {
			$this->service->start( $this->ctx() );
			self::fail( 'С 13:55 старт закрыт.' );
		} catch ( CodedException $e ) {
			self::assertSame( ErrorCode::ExamNotOpen, $e->errorCode );
			self::assertSame( 'Время начала истекло.', $e->getMessage() );
		}
	}

	public function test_second_start_returns_same_attempt(): void {
		$existing = $this->attempt();
		$this->arrangeStart( $this->participation( 9 ) );
		$this->attempts->method( 'find' )->willReturn( $existing );
		$this->attempts->expects( self::never() )->method( 'create' );

		self::assertSame( $existing, $this->service->start( $this->ctx() ) );
	}

	public function test_start_denied_with_other_active_attempt(): void {
		$this->arrangeStart();
		$this->attempts->expects( self::once() )->method( 'findAnyActive' )->with( self::PERSON, '2026-03-12 10:00:00' )->willReturn( $this->attempt() );
		$this->attempts->expects( self::never() )->method( 'create' );

		try {
			$this->service->start( $this->ctx() );
			self::fail( 'Пока идёт другая работа, второй старт закрыт.' );
		} catch ( CodedException $e ) {
			self::assertSame( ErrorCode::ExamConflict, $e->errorCode );
		}
	}

	/** Старт на открытом сеансе: пустая активность, номер попытки и созданная попытка заданы. @return AttemptInputDTO[] созданные */
	private function arrangeSuccessfulStart( string $deadline = '2026-03-12 14:00:00' ): void {
		$this->arrangeStart();
		$this->attempts->method( 'findAnyActive' )->willReturn( null );
		$this->attempts->method( 'nextAttemptNumber' )->willReturn( 1 );
		$this->attempts->method( 'create' )->willReturn( 9 );
		$this->attempts->method( 'find' )->willReturn( $this->attempt( 'in_progress', $deadline ) );
	}

	public function test_start_allowed_at_10_00(): void {
		$this->setNow( '2026-03-12 07:00:00', '2026-03-12 10:00:00' );
		$this->arrangeSuccessfulStart();
		$this->attempts->expects( self::once() )->method( 'create' );

		$attempt = $this->service->start( $this->ctx() );

		self::assertSame( 9, $attempt->id, 'Ровно в 10:00 старт открыт.' );
	}

	public function test_attempt_started_at_11_00_has_deadline_14_55_and_is_not_interrupted_at_13_55(): void {
		// Старт в 11:00 по Москве с длительностью 235 минут → дедлайн 14:55. В 13:55 сеанс по плану закончен,
		// но начавшая попытка идёт дальше: ни неявки, ни автозавершения.
		$this->setNow( '2026-03-12 08:00:00', '2026-03-12 11:00:00' );
		$this->arrangeStart();
		$this->attempts->method( 'findAnyActive' )->willReturn( null );
		$this->attempts->method( 'nextAttemptNumber' )->willReturn( 1 );
		$captured = null;
		$this->attempts->method( 'create' )->willReturnCallback( function ( AttemptInputDTO $dto ) use ( &$captured ): int {
			$captured = $dto;
			return 9;
		} );
		$this->attempts->method( 'find' )->willReturn( $this->attempt( 'in_progress', '2026-03-12 14:55:00' ) );

		$this->service->start( $this->ctx() );

		self::assertSame( '2026-03-12 14:55:00', $captured->deadlineAt );

		$this->setNow( '2026-03-12 10:55:00', '2026-03-12 13:55:00' );
		$this->noShow->expects( self::never() )->method( 'markMissedLocked' );
		$this->attemptService->expects( self::never() )->method( 'submitFor' );

		self::assertFalse( $this->service->finalizeExpired( 9 ), 'В 13:55 попытка не завершается: дедлайн 14:55.' );
	}

	public function test_start_denied_for_cancelled_registration(): void {
		$this->participations->method( 'findForUpdate' )->willReturn( $this->participation() );
		$this->registrations->method( 'find' )->willReturn( $this->registration( 0, 'cancelled' ) );
		$this->attempts->expects( self::never() )->method( 'create' );

		try {
			$this->service->start( $this->ctx() );
			self::fail( 'По отменённой записи стартовать нельзя.' );
		} catch ( CodedException $e ) {
			self::assertSame( ErrorCode::ExamAccess, $e->errorCode );
		}
	}

	public function test_start_denied_for_registration_of_another_participation(): void {
		$this->participations->method( 'findForUpdate' )->willReturn( $this->participation() );
		$this->registrations->method( 'find' )->willReturn( ExamRegistrationDTO::fromArray( array(
			'id' => self::REGISTRATION, 'participation_id' => 99, 'session_id' => 100, 'status' => 'confirmed', 'active_slot' => 1, 'created_at' => '2026-03-01 00:00:00',
		) ) );
		$this->attempts->expects( self::never() )->method( 'create' );

		$this->expectException( CodedException::class );

		$this->service->start( $this->ctx() );
	}

	public function test_start_sets_first_started_at_once(): void {
		$this->setNow( '2026-03-12 07:10:00', '2026-03-12 10:10:00' );
		$this->arrangeSuccessfulStart();
		$this->sessions->expects( self::once() )->method( 'markFirstStarted' )->with( 100, '2026-03-12 07:10:00' );

		$this->service->start( $this->ctx() );
	}

	public function test_repeated_start_does_not_touch_the_session_again(): void {
		$this->arrangeStart( $this->participation( 9 ) );
		$this->attempts->method( 'find' )->willReturn( $this->attempt() );
		$this->sessions->expects( self::never() )->method( 'markFirstStarted' );
		$this->outbox->expects( self::never() )->method( 'add' );

		$this->service->start( $this->ctx() );
	}

	public function test_start_does_not_check_assessment_attempt_limit(): void {
		// Официальная попытка — одна на участие; лимит попыток работы (он считается по курсу) на неё не действует,
		// а номер попытки растёт по всем попыткам человека, чтобы не упереться в уникальный ключ.
		$this->arrangeStart();
		$this->attempts->method( 'findAnyActive' )->willReturn( null );
		$this->attempts->method( 'nextAttemptNumber' )->willReturn( 4 );
		$this->attempts->method( 'find' )->willReturn( $this->attempt() );
		$this->attemptService->expects( self::never() )->method( 'start' );
		$this->attempts->expects( self::never() )->method( 'countByAssessmentAndStudent' );
		$captured = null;
		$this->attempts->method( 'create' )->willReturnCallback( function ( AttemptInputDTO $dto ) use ( &$captured ): int {
			$captured = $dto;
			return 9;
		} );

		$this->service->start( $this->ctx() );

		self::assertSame( 4, $captured->attemptNumber );
	}

	public function test_start_writes_outbox(): void {
		$this->arrangeSuccessfulStart();
		$this->outbox->expects( self::once() )->method( 'add' )->with(
			ExamOutboxEvent::AttemptStarted, 'participation', self::PARTICIPATION, 7,
			array( 'attempt_id' => 9, 'registration_id' => self::REGISTRATION, 'session_id' => 100 )
		);

		$this->service->start( $this->ctx() );
	}

	public function test_failed_outbox_write_fails_the_start(): void {
		$this->arrangeSuccessfulStart();
		$this->outbox->method( 'add' )->willThrowException( new \RuntimeException( 'outbox недоступен' ) );

		$this->expectException( \RuntimeException::class );

		$this->service->start( $this->ctx() );
	}

	/* ── Допуск ученика к записи ── */

	private function arrangeOwner( int $participantPersonId, int $userPersonId ): void {
		$this->registrations->method( 'find' )->willReturn( $this->registration() );
		$this->participations->method( 'find' )->willReturn( $this->participation() );
		$this->participants->method( 'find' )->willReturn( ExamParticipantDTO::fromArray( array(
			'id' => 4, 'person_id' => $participantPersonId, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
		) ) );
		$this->persons->method( 'findByWpUserId' )->willReturn( PersonDTO::fromArray( array(
			'id' => $userPersonId, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
		) ) );
	}

	public function test_own_registration_gives_context(): void {
		$this->arrangeOwner( self::PERSON, self::PERSON );

		$ctx = $this->service->contextForStudent( 42, self::REGISTRATION );

		self::assertSame( self::PARTICIPATION, $ctx->participationId );
		self::assertSame( self::REGISTRATION, $ctx->registrationId );
		self::assertSame( self::PERSON, $ctx->personId );
	}

	public function test_foreign_registration_is_denied_like_missing(): void {
		// Чужая запись и несуществующая отвечают одинаково: по ответу не понять, чья она и есть ли вообще.
		$this->arrangeOwner( 777, self::PERSON );
		$messages = array();

		try {
			$this->service->contextForStudent( 42, self::REGISTRATION );
		} catch ( CodedException $e ) {
			$messages['foreign'] = array( $e->errorCode, $e->getMessage() );
		}

		$missing = new ExamAttemptService(
			$this->participations, $this->participants, $this->createMock( ExamRegistrationRepository::class ), $this->sessions, $this->events, $this->attempts,
			$this->persons, $this->createMock( AssessmentManager::class ), $this->attemptService, $this->noShow,
			$this->createMock( ExamFormatRegistry::class ), $this->accessGuard, $this->outbox, new ExamTime( $this->createMock( ClockInterface::class ) ),
			$this->guestSessions,
		);
		try {
			$missing->contextForStudent( 42, 999 );
		} catch ( CodedException $e ) {
			$messages['missing'] = array( $e->errorCode, $e->getMessage() );
		}

		self::assertSame( array( ErrorCode::ExamAccess, 'Экзамен недоступен.' ), $messages['foreign'] ?? null );
		self::assertSame( $messages['missing'] ?? null, $messages['foreign'] ?? null );
	}
	/* ── Продление ── */

	private function arrangeExtend( string $status = 'in_progress', bool $canManage = true ): void {
		$this->attempts->method( 'find' )->willReturn( $this->attempt( $status, '2026-03-12 13:55:00' ) );
		$this->participations->method( 'findForUpdate' )->willReturn( $this->participation( 9 ) );
		$this->events->method( 'find' )->willReturn( $this->event() );
		$this->accessGuard->method( 'canManageSubject' )->willReturn( $canManage );
	}

	public function test_extend_requires_reason_and_scope(): void {
		$this->arrangeExtend( 'in_progress', false );

		foreach ( array(
			array( 10, '', 'X-CONFLICT' ),           // причина обязательна
			array( 0, 'причина', 'X-CONFLICT' ),     // минимум 1 минута
			array( 121, 'причина', 'X-CONFLICT' ),   // максимум 120 минут
			array( 10, 'причина', 'X-ACCESS' ),      // нет права на предмет проведения
		) as [ $minutes, $reason, $code ] ) {
			try {
				$this->service->extend( 1, 9, $minutes, $reason );
				self::fail( "Продление ({$minutes}, '{$reason}') должно отказывать." );
			} catch ( CodedException $e ) {
				self::assertSame( $code, $e->errorCode->value, "{$minutes} / '{$reason}'" );
			}
		}
	}

	public function test_extend_denied_for_finished_attempt(): void {
		$this->arrangeExtend( 'submitted' );
		$this->attempts->expects( self::never() )->method( 'update' );

		try {
			$this->service->extend( 1, 9, 15, 'сбой станции' );
			self::fail( 'Завершённую попытку продлением не возобновить.' );
		} catch ( CodedException $e ) {
			self::assertSame( ErrorCode::ExamStarted, $e->errorCode );
		}
	}

	public function test_extend_moves_deadline(): void {
		$this->arrangeExtend();
		$this->attempts->expects( self::once() )->method( 'update' )->with( 9, array( 'deadline_at' => '2026-03-12 14:10:00' ) )->willReturn( true );
		$this->outbox->expects( self::once() )->method( 'add' )->with(
			ExamOutboxEvent::AttemptExtended, 'participation', self::PARTICIPATION, 7,
			array( 'attempt_id' => 9, 'minutes' => 15, 'reason' => 'сбой станции', 'actor_user_id' => 1, 'deadline_at' => '2026-03-12 14:10:00' )
		);

		$this->service->extend( 1, 9, 15, 'сбой станции' );
	}

	public function test_extend_locks_participation_before_rereading_attempt(): void {
		$order = array();
		$this->attempts->method( 'find' )->willReturnCallback( function () use ( &$order ): AttemptDTO {
			$order[] = 'attempt';
			return $this->attempt();
		} );
		$this->participations->method( 'findForUpdate' )->willReturnCallback( function () use ( &$order ): ExamParticipationDTO {
			$order[] = 'lock';
			return $this->participation( 9 );
		} );
		$this->events->method( 'find' )->willReturn( $this->event() );
		$this->accessGuard->method( 'canManageSubject' )->willReturn( true );
		$this->attempts->method( 'update' )->willReturn( true );

		$this->service->extend( 1, 9, 15, 'причина' );

		self::assertSame( 'attempt', $order[0], 'участие определяется до транзакции' );
		self::assertSame( 'lock', $order[1], 'первый оператор транзакции — блокировка участия' );
	}

	/* ── Гость (11b.2) ── */

	private function guestCtx(): AttemptContext {
		return new AttemptContext( ExamAudience::Guest, self::PARTICIPATION, self::REGISTRATION, null, null );
	}

	private function guestParticipation( ?string $admittedAt = '2026-03-12 06:30:00', ?int $currentAttemptId = null ): ExamParticipationDTO {
		return ExamParticipationDTO::fromArray( array(
			'id' => self::PARTICIPATION, 'event_id' => 1, 'participant_id' => 4, 'audience' => 'guest', 'current_attempt_id' => $currentAttemptId,
			'transfer_allowed' => 0, 'admitted_at' => $admittedAt, 'version' => 7, 'created_at' => '2026-03-01 00:00:00', 'updated_at' => '2026-03-01 00:00:00',
		) );
	}

	public function test_guest_start_requires_admission(): void {
		$this->setNow( '2026-03-12 07:10:00', '2026-03-12 10:10:00' );
		$this->arrangeStart( $this->guestParticipation( null ) );
		$this->attempts->expects( self::never() )->method( 'create' );

		$this->expectException( CodedException::class );
		$this->service->start( $this->guestCtx() );
	}

	public function test_guest_attempt_has_null_person_and_number_one(): void {
		$this->setNow( '2026-03-12 07:10:00', '2026-03-12 10:10:00' );
		$this->arrangeStart( $this->guestParticipation() );
		$this->attempts->expects( self::never() )->method( 'findAnyActive' );
		$this->attempts->expects( self::never() )->method( 'nextAttemptNumber' );
		$captured = null;
		$this->attempts->method( 'create' )->willReturnCallback( function ( AttemptInputDTO $dto ) use ( &$captured ): int {
			$captured = $dto;
			return 9;
		} );
		$this->attempts->method( 'find' )->willReturn( $this->attempt( 'in_progress', '2026-03-12 14:05:00' ) );
		$this->guestSessions->expects( self::once() )->method( 'extendForAttempt' )->with( self::PARTICIPATION, self::isType( 'string' ) );

		$this->service->start( $this->guestCtx() );

		self::assertNull( $captured->studentPersonId );
		self::assertSame( 1, $captured->attemptNumber );
	}

	public function test_guest_cannot_use_foreign_registration(): void {
		$this->setNow( '2026-03-12 07:10:00', '2026-03-12 10:10:00' );
		// Контекст гостя не может открыть участие ученика: аудитории не совпадают.
		$this->arrangeStart( $this->participation() );
		$this->attempts->expects( self::never() )->method( 'create' );

		try {
			$this->service->start( $this->guestCtx() );
			self::fail( 'Гость не стартует по участию ученика.' );
		} catch ( CodedException $e ) {
			self::assertSame( ErrorCode::ExamAccess, $e->errorCode );
		}
	}

	public function test_student_cannot_start_guest_participation(): void {
		$this->setNow( '2026-03-12 07:10:00', '2026-03-12 10:10:00' );
		$this->arrangeStart( $this->guestParticipation() );
		$this->attempts->expects( self::never() )->method( 'create' );

		$this->expectException( CodedException::class );
		$this->service->start( $this->ctx() );
	}

	public function test_guest_save_and_submit_by_session_participation(): void {
		$this->attempts->method( 'find' )->willReturn( $this->attempt() );
		$this->participations->method( 'findForUpdate' )->willReturn( $this->guestParticipation() );
		$this->attemptService->expects( self::once() )->method( 'saveAnswerFor' );
		$this->attemptService->expects( self::once() )->method( 'submitFor' )->willReturn( $this->attempt( 'submitted' ) );

		$this->service->saveAnswer( $this->guestCtx(), 9, 3, 'ответ' );
		$this->service->submit( $this->guestCtx(), 9 );
	}

	public function test_guest_result_uses_exam_result_without_person_check(): void {
		$this->attempts->method( 'find' )->willReturn( $this->attempt( 'submitted' ) );
		$this->attemptService->expects( self::never() )->method( 'getResult' );
		$this->attemptService->expects( self::once() )->method( 'getExamResult' )->with( 9 )->willReturn( array( 'attempt' => $this->attempt( 'submitted' ), 'answers' => array() ) );

		$this->service->result( $this->guestCtx(), 9 );
	}

	public function test_context_for_guest_comes_from_guest_session(): void {
		$this->guestSessions->expects( self::once() )->method( 'current' )->willReturn( $this->guestCtx() );

		self::assertNull( $this->service->contextForGuest()->personId );
	}
}
