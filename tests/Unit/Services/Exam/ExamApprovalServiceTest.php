<?php

declare( strict_types=1 );

namespace Tests\Unit\Services\Exam;

use Inc\DTO\Assessment\AttemptDTO;
use Inc\DTO\Exam\ExamParticipationDTO;
use Inc\Enums\Exam\ExamOutboxEvent;
use Inc\Repositories\WPDBRepositories\AssessmentAnswerRepository;
use Inc\Repositories\WPDBRepositories\AssessmentAttemptRepository;
use Inc\Repositories\WPDBRepositories\ExamEventRepository;
use Inc\Repositories\WPDBRepositories\ExamParticipationRepository;
use Inc\Contracts\LogEventDispatcherInterface;
use Inc\DTO\Assessment\AttemptAnswerDTO;
use Inc\Enums\Log\ErrorCode;
use Inc\Enums\Log\LogEvent;
use Inc\Services\Assessment\AutoGradeService;
use Inc\Shared\CodedException;
use Inc\Services\Exam\ExamAccessGuard;
use Inc\Services\Exam\ExamApprovalService;
use Inc\Services\Exam\ExamOutbox;
use Inc\Services\Exam\ExamTime;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Tests\Support\ExamFixtures;

/**
 * Утверждение (8.5): каждая причина пропуска, ровно одно событие на работу, частичный успех массового утверждения.
 */
#[AllowMockObjectsWithoutExpectations]
class ExamApprovalServiceTest extends TestCase {

	use ExamFixtures;

	private const ACTOR = 10;

	private AssessmentAttemptRepository&MockObject $attempts;
	private AssessmentAnswerRepository&MockObject $answers;
	private ExamParticipationRepository&MockObject $participations;
	private ExamEventRepository&MockObject $events;
	private ExamAccessGuard&MockObject $guard;
	private ExamOutbox&MockObject $outbox;
	private AutoGradeService&MockObject $autoGrade;
	private LogEventDispatcherInterface&MockObject $logEvents;
	private ExamApprovalService $service;

	/** @var array<int, AttemptDTO> */
	private array $attemptStore = array();

	protected function setUp(): void {
		parent::setUp();

		$this->attempts       = $this->createMock( AssessmentAttemptRepository::class );
		$this->answers        = $this->createMock( AssessmentAnswerRepository::class );
		$this->participations = $this->createMock( ExamParticipationRepository::class );
		$this->events         = $this->createMock( ExamEventRepository::class );
		$this->guard          = $this->createMock( ExamAccessGuard::class );
		$this->outbox         = $this->createMock( ExamOutbox::class );
		$this->autoGrade      = $this->createMock( AutoGradeService::class );
		$this->logEvents      = $this->createMock( LogEventDispatcherInterface::class );
		$time                 = $this->createStub( ExamTime::class );
		$time->method( 'nowLocal' )->willReturn( '2026-03-10 15:00:00' );

		$this->attempts->method( 'find' )->willReturnCallback( fn ( int $id ): ?AttemptDTO => $this->attemptStore[ $id ] ?? null );
		$this->attempts->method( 'approve' )->willReturn( true );
		$this->participations->method( 'find' )->willReturn( $this->participation() );
		$this->participations->method( 'findForUpdate' )->willReturn( $this->participation() );
		$this->events->method( 'find' )->willReturn( $this->examEvent() );
		$this->guard->method( 'canManageEvent' )->willReturn( true );
		$this->attemptStore[5] = $this->attempt();

		$this->service = new ExamApprovalService( $this->attempts, $this->answers, $this->participations, $this->events, $this->guard, $this->outbox, $time, $this->autoGrade, $this->logEvents );
	}

	/** @param array<string, mixed> $override */
	private function attempt( array $override = array() ): AttemptDTO {
		return AttemptDTO::fromArray( array_merge( array(
			'id' => 5, 'assessment_id' => 500, 'student_person_id' => 1, 'attempt_number' => 1,
			'started_at' => '2026-03-10 10:00:00', 'deadline_at' => '2026-03-10 13:55:00', 'status' => 'graded',
			'exam_participation_id' => 70, 'exam_registration_id' => 20, 'result_version' => 4,
		), $override ) );
	}

	private function participation( string $audience = 'student' ): ExamParticipationDTO {
		return ExamParticipationDTO::fromArray( array(
			'id' => 70, 'event_id' => 3, 'participant_id' => 4, 'audience' => $audience, 'transfer_allowed' => 0, 'version' => 6,
			'created_at' => '2026-03-01 00:00:00', 'updated_at' => '2026-03-01 00:00:00',
		) );
	}

	public function test_attempt_not_found_or_not_exam_is_skipped(): void {
		self::assertSame( array( 'status' => 'skipped', 'reason' => 'not_found' ), $this->service->approve( self::ACTOR, 999, 4 ) );

		$this->attemptStore[6] = $this->attempt( array( 'id' => 6, 'exam_participation_id' => null ) );
		self::assertSame( 'not_found', $this->service->approve( self::ACTOR, 6, 4 )['reason'] );
	}

	public function test_no_access_to_event_is_skipped(): void {
		$guard = $this->createMock( ExamAccessGuard::class );
		$guard->method( 'canManageEvent' )->willReturn( false );
		$service = new ExamApprovalService( $this->attempts, $this->answers, $this->participations, $this->events, $guard, $this->outbox, $this->createStub( ExamTime::class ), $this->autoGrade, $this->logEvents );
		$this->attempts->expects( self::never() )->method( 'approve' );

		self::assertSame( 'no_access', $service->approve( self::ACTOR, 5, 4 )['reason'] );
	}

	public function test_guest_participation_is_skipped(): void {
		$participations = $this->createMock( ExamParticipationRepository::class );
		$participations->method( 'find' )->willReturn( $this->participation( 'guest' ) );
		$service = new ExamApprovalService( $this->attempts, $this->answers, $participations, $this->events, $this->guard, $this->outbox, $this->createStub( ExamTime::class ), $this->autoGrade, $this->logEvents );
		$this->attempts->expects( self::never() )->method( 'approve' );

		self::assertSame( 'guest', $service->approve( self::ACTOR, 5, 4 )['reason'] );
	}

	public function test_not_submitted_attempt_is_skipped(): void {
		$this->attemptStore[5] = $this->attempt( array( 'status' => 'in_progress' ) );
		$this->attempts->expects( self::never() )->method( 'approve' );

		self::assertSame( 'not_submitted', $this->service->approve( self::ACTOR, 5, 4 )['reason'] );
	}

	public function test_pending_manual_tasks_block_approval(): void {
		$this->answers->method( 'hasPendingAnswers' )->willReturn( true );
		$this->attempts->expects( self::never() )->method( 'approve' );

		self::assertSame( 'pending_review', $this->service->approve( self::ACTOR, 5, 4 )['reason'] );
	}

	public function test_stale_version_is_skipped(): void {
		$this->answers->method( 'hasPendingAnswers' )->willReturn( false );
		$this->attempts->expects( self::never() )->method( 'approve' );

		self::assertSame( 'stale', $this->service->approve( self::ACTOR, 5, 3 )['reason'] );
	}

	public function test_approve_writes_outbox_once(): void {
		$this->answers->method( 'hasPendingAnswers' )->willReturn( false );
		$this->attempts->expects( self::once() )->method( 'approve' )->with( 5, self::ACTOR, '2026-03-10 15:00:00' );
		$this->outbox->expects( self::once() )->method( 'add' )->with(
			ExamOutboxEvent::AttemptApproved,
			'participation',
			70,
			6,
			array( 'attempt_id' => 5, 'participation_id' => 70, 'result_version' => 4 )
		);

		self::assertSame( array( 'status' => 'approved' ), $this->service->approve( self::ACTOR, 5, 4 ) );
	}

	public function test_second_approve_is_already_approved_without_outbox(): void {
		$this->attemptStore[5] = $this->attempt( array( 'approved_at' => '2026-03-10 15:00:00' ) );
		$this->outbox->expects( self::never() )->method( 'add' );
		$this->attempts->expects( self::never() )->method( 'approve' );

		self::assertSame( 'already_approved', $this->service->approve( self::ACTOR, 5, 4 )['reason'] );
	}

	public function test_oge_exam_attempt_requires_explicit_approval(): void {
		// Проверка ОГЭ закончена (ручных заданий нет), но сама по себе работа не утверждается: нужен явный вызов approve().
		$this->answers->method( 'hasPendingAnswers' )->willReturn( false );
		$this->attempts->expects( self::never() )->method( 'approve' );
		$this->outbox->expects( self::never() )->method( 'add' );

		self::assertFalse( $this->attemptStore[5]->isApproved() );
		// Чтение «готова ли работа» (ExamConductService::resultStatus) ничего не утверждает — approve() не вызывался.
	}

	public function test_approve_many_is_partial_success(): void {
		$this->answers->method( 'hasPendingAnswers' )->willReturnCallback( static fn ( int $id ): bool => 6 === $id );
		$this->attemptStore[6] = $this->attempt( array( 'id' => 6 ) );
		$this->attemptStore[7] = $this->attempt( array( 'id' => 7 ) );

		$result = $this->service->approveMany( self::ACTOR, array(
			array( 'attempt_id' => 5, 'result_version' => 4 ),
			array( 'attempt_id' => 6, 'result_version' => 4 ),
			array( 'attempt_id' => 7, 'result_version' => 4 ),
		) );

		self::assertSame( 2, $result['approved'] );
		self::assertSame( array( array( 'attempt_id' => 6, 'reason' => 'pending_review', 'reason_label' => 'Проверка не завершена' ) ), $result['skipped'] );
	}

	public function test_approve_many_runs_each_item_in_own_transaction(): void {
		$this->answers->method( 'hasPendingAnswers' )->willReturn( false );
		$this->attemptStore[6] = $this->attempt( array( 'id' => 6 ) );
		$locks                 = 0;
		$participations        = $this->createMock( ExamParticipationRepository::class );
		$participations->method( 'find' )->willReturn( $this->participation() );
		$participations->method( 'findForUpdate' )->willReturnCallback( function () use ( &$locks ): ExamParticipationDTO {
			++$locks;
			return $this->participation();
		} );
		$service = new ExamApprovalService( $this->attempts, $this->answers, $participations, $this->events, $this->guard, $this->outbox, $this->createStub( ExamTime::class ), $this->autoGrade, $this->logEvents );

		$service->approveMany( self::ACTOR, array( array( 'attempt_id' => 5, 'result_version' => 4 ), array( 'attempt_id' => 6, 'result_version' => 4 ) ) );

		self::assertSame( 2, $locks, 'Блокировка участия берётся для каждой работы отдельно.' );
	}

	public function test_approve_many_survives_exception_of_one_item(): void {
		$this->answers->method( 'hasPendingAnswers' )->willReturn( false );
		$this->attemptStore[6] = $this->attempt( array( 'id' => 6 ) );
		$calls                 = 0;
		$this->attempts->method( 'approve' )->willReturnCallback( static function () use ( &$calls ): bool {
			if ( 1 === ++$calls ) {
				throw new \RuntimeException( 'db down' );
			}
			return true;
		} );

		$result = $this->service->approveMany( self::ACTOR, array( array( 'attempt_id' => 5, 'result_version' => 4 ), array( 'attempt_id' => 6, 'result_version' => 4 ) ) );

		self::assertSame( 1, $result['approved'] );
		self::assertSame( 'error', $result['skipped'][0]['reason'] );
	}

	/* ── 8.6 Исправление результата ───────────────────────────────────────── */

	private function approvedAttempt( float $total = 20.0 ): AttemptDTO {
		return $this->attempt( array( 'approved_at' => '2026-03-10 15:00:00', 'total_score' => $total, 'max_score' => 29 ) );
	}

	private function answer( int $taskId, float $score, float $max, ?array $criteria = null ): AttemptAnswerDTO {
		return new AttemptAnswerDTO( 1, 5, $taskId, 'Ответ ученика', true, $score, $max, 10, '2026-03-10 14:00:00', null, $criteria );
	}

	/** @param list<array{task_id: int, score: float}> $changes */
	private function correct( array $changes, string $reason = 'Пересчёт критериев', int $version = 4 ): AttemptDTO {
		return $this->service->correct( self::ACTOR, 5, $changes, $reason, $version );
	}

	private function assertCoded( ErrorCode $code, callable $call ): void {
		try {
			$call();
			self::fail( 'Ожидался отказ ' . $code->value );
		} catch ( CodedException $e ) {
			self::assertSame( $code, $e->errorCode );
		}
	}

	public function test_correct_requires_reason(): void {
		$this->attempts->expects( self::never() )->method( 'bumpResultVersion' );

		$this->assertCoded( ErrorCode::ExamConflict, fn () => $this->correct( array( array( 'task_id' => 1, 'score' => 1.0 ) ), '  ' ) );
	}

	public function test_correct_requires_approved_attempt(): void {
		$this->attempts->expects( self::never() )->method( 'bumpResultVersion' );

		$this->assertCoded( ErrorCode::ExamConflict, fn () => $this->correct( array( array( 'task_id' => 1, 'score' => 1.0 ) ) ) );
	}

	public function test_correct_with_stale_version_is_rejected_and_changes_nothing(): void {
		$this->attemptStore[5] = $this->approvedAttempt();
		$this->attempts->method( 'bumpResultVersion' )->willReturn( false );
		$this->answers->expects( self::never() )->method( 'upsert' );
		$this->outbox->expects( self::never() )->method( 'add' );

		$this->assertCoded( ErrorCode::ExamStale, fn () => $this->correct( array( array( 'task_id' => 1, 'score' => 1.0 ) ), 'Причина', 3 ) );
	}

	public function test_correct_never_changes_answer_text(): void {
		$this->attemptStore[5] = $this->approvedAttempt();
		$this->attempts->method( 'bumpResultVersion' )->willReturn( true );
		$this->answers->method( 'findByAttemptAndTask' )->willReturn( $this->answer( 1, 1.0, 2.0 ) );
		$this->autoGrade->method( 'finalize' )->willReturn( $this->approvedAttempt( 21.0 ) );
		$this->answers->expects( self::once() )->method( 'upsert' )->with(
			5,
			1,
			self::callback( static function ( array $row ): bool {
				return ! array_key_exists( 'answer_text', $row ) && 2.0 === $row['score'] && 1 === $row['is_correct'] && 10 === $row['graded_by_user_id'];
			} )
		)->willReturn( true );

		$this->correct( array( array( 'task_id' => 1, 'score' => 2.0 ) ) );
	}

	public function test_correct_rejects_score_above_max(): void {
		$this->attemptStore[5] = $this->approvedAttempt();
		$this->attempts->method( 'bumpResultVersion' )->willReturn( true );
		$this->answers->method( 'findByAttemptAndTask' )->willReturn( $this->answer( 1, 1.0, 2.0 ) );
		$this->answers->expects( self::never() )->method( 'upsert' );

		$this->assertCoded( ErrorCode::ExamConflict, fn () => $this->correct( array( array( 'task_id' => 1, 'score' => 3.0 ) ) ) );
		$this->assertCoded( ErrorCode::ExamConflict, fn () => $this->correct( array( array( 'task_id' => 1, 'score' => -1.0 ) ) ) );
	}

	public function test_correct_recalculates_totals_and_bumps_version(): void {
		$this->attemptStore[5] = $this->approvedAttempt();
		$this->attempts->expects( self::once() )->method( 'bumpResultVersion' )->with( 5, 4 )->willReturn( true );
		$this->answers->method( 'findByAttemptAndTask' )->willReturn( $this->answer( 1, 1.0, 2.0 ) );
		$this->answers->method( 'upsert' )->willReturn( true );
		$this->autoGrade->expects( self::once() )->method( 'finalize' )->willReturn( $this->approvedAttempt( 21.0 ) );

		self::assertSame( 21.0, $this->correct( array( array( 'task_id' => 1, 'score' => 2.0 ) ) )->totalScore );
	}

	public function test_correct_clears_criteria_scores_that_described_the_old_score(): void {
		$this->attemptStore[5] = $this->approvedAttempt();
		$this->attempts->method( 'bumpResultVersion' )->willReturn( true );
		$this->answers->method( 'findByAttemptAndTask' )->willReturn( $this->answer( 1, 1.0, 3.0, array( 0 => 1.0 ) ) );
		$this->autoGrade->method( 'finalize' )->willReturn( $this->approvedAttempt() );
		$this->answers->expects( self::once() )->method( 'upsert' )->with( 5, 1, self::callback( static fn ( array $row ): bool => array_key_exists( 'criteria_scores', $row ) && null === $row['criteria_scores'] ) )->willReturn( true );

		$this->correct( array( array( 'task_id' => 1, 'score' => 2.0 ) ) );
	}

	public function test_correct_logs_old_and_new_values_with_actor_and_reason(): void {
		$this->attemptStore[5] = $this->approvedAttempt( 20.0 );
		$this->attempts->method( 'bumpResultVersion' )->willReturn( true );
		$this->answers->method( 'findByAttemptAndTask' )->willReturn( $this->answer( 7, 1.0, 2.0 ) );
		$this->answers->method( 'upsert' )->willReturn( true );
		$this->autoGrade->method( 'finalize' )->willReturn( $this->approvedAttempt( 21.0 ) );

		$this->logEvents->expects( self::once() )->method( 'dispatch' )->with(
			LogEvent::ExamResultCorrected,
			self::callback( static function ( $event ): bool {
				return self::ACTOR === $event->actorUserId && 5 === $event->entityId
					&& str_contains( (string) $event->oldLabel, '№7: 1→2' )
					&& str_contains( (string) $event->oldLabel, 'Итог 20→21' )
					&& str_contains( (string) $event->oldLabel, 'Причина: Пересчёт критериев' );
			} )
		);

		$this->correct( array( array( 'task_id' => 7, 'score' => 2.0 ) ) );
	}

	public function test_correct_writes_result_corrected_outbox(): void {
		$this->attemptStore[5] = $this->approvedAttempt( 20.0 );
		$this->attempts->method( 'bumpResultVersion' )->willReturn( true );
		$this->answers->method( 'findByAttemptAndTask' )->willReturn( $this->answer( 7, 1.0, 2.0 ) );
		$this->answers->method( 'upsert' )->willReturn( true );
		$this->autoGrade->method( 'finalize' )->willReturn( $this->approvedAttempt( 21.0 ) );

		$this->outbox->expects( self::once() )->method( 'add' )->with(
			ExamOutboxEvent::ResultCorrected,
			'participation',
			70,
			6,
			array(
				'attempt_id'       => 5,
				'participation_id' => 70,
				'reason'           => 'Пересчёт критериев',
				'result_version'   => 5,
				'old_total'        => 20.0,
				'new_total'        => 21.0,
				'changes'          => array( array( 'task_id' => 7, 'old' => 1.0, 'new' => 2.0 ) ),
			)
		);

		$this->correct( array( array( 'task_id' => 7, 'score' => 2.0 ) ) );
	}
}
