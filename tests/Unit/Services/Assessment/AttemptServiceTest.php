<?php

declare( strict_types=1 );

namespace Unit\Services\Assessment;

use Inc\Contracts\ClockInterface;
use Inc\Contracts\LogEventDispatcherInterface;
use Inc\DTO\Assessment\AssessmentDTO;
use Inc\DTO\Assessment\AttemptDTO;
use Inc\Enums\Assessment\AssessmentKind;
use Inc\Enums\Assessment\ScoringPolicy;
use Inc\Managers\Assessment\AssessmentManager;
use Inc\Repositories\WPDBRepositories\AssessmentAnswerRepository;
use Inc\Repositories\WPDBRepositories\AssessmentAttemptRepository;
use Inc\DTO\Course\GroupLessonDTO;
use Inc\Services\Assessment\AssessmentAccessPolicy;
use Inc\Services\Assessment\AttemptRevealPolicy;
use Inc\Services\Assessment\AttemptService;
use Inc\Services\Assessment\AutoGradeService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * T16.11 / T16.8: блок старта незавершённой ЕГЭ-работы (D16.3.б); Control не блокируется.
 */
class AttemptServiceTest extends TestCase {

	private AssessmentAttemptRepository&MockObject $attempts;
	private AssessmentAnswerRepository&MockObject  $answers;
	private AssessmentManager&MockObject           $assessments;
	private AutoGradeService&MockObject            $autoGrade;
	private LogEventDispatcherInterface&MockObject $dispatcher;
	private ClockInterface&MockObject              $clock;
	private AssessmentAccessPolicy&MockObject      $access;
	private AttemptRevealPolicy&MockObject         $revealPolicy;
	private AttemptService                         $service;

	protected function setUp(): void {
		parent::setUp();
		$this->attempts     = $this->createMock( AssessmentAttemptRepository::class );
		$this->answers      = $this->createMock( AssessmentAnswerRepository::class );
		$this->assessments  = $this->createMock( AssessmentManager::class );
		$this->autoGrade    = $this->createMock( AutoGradeService::class );
		$this->dispatcher   = $this->createMock( LogEventDispatcherInterface::class );
		$this->clock        = $this->createMock( ClockInterface::class );
		$this->access       = $this->createMock( AssessmentAccessPolicy::class );
		$this->revealPolicy = $this->createMock( AttemptRevealPolicy::class );

		$this->clock->method( 'now' )->willReturn( '2026-06-01 10:00:00' );
		// Политика сама возвращает занятие: попытка привязывается к нему, даже если
		// ученик пришёл по прямому пермалинку без `from_gl`.
		$this->access->method( 'resolveAccessibleLesson' )->willReturn( $this->accessibleLesson() );

		$this->service = new AttemptService(
			$this->attempts,
			$this->answers,
			$this->assessments,
			$this->autoGrade,
			$this->dispatcher,
			$this->clock,
			$this->access,
			$this->revealPolicy,
			$this->createMock( \Inc\Repositories\WPDBRepositories\PersonRepository::class ),
		);
	}

	private function assessment( AssessmentKind $kind ): AssessmentDTO {
		return new AssessmentDTO(
			id: 1, subjectKey: 'inf', title: 'Работа', taskIds: array( 10 ),
			timeLimit: 0, attemptsAllowed: 0, passScore: 0.0,
			scoringPolicy: ScoringPolicy::Highest, status: 'publish',
			kind: $kind, taskPoints: array(), scoreMap: array(),
		);
	}

	private function seededAttempt(): AttemptDTO {
		return AttemptDTO::fromArray( array(
			'id' => 5, 'assessment_id' => 1, 'student_person_id' => 99,
			'group_id' => null, 'attempt_number' => 1,
			'started_at' => '2026-06-01 10:00:00', 'deadline_at' => '2026-06-01 11:00:00',
			'status' => 'in_progress',
		) );
	}

	/** Станция с любым составом (не все номера, не на своих местах) стартует как обычная работа. */
	public function test_station_start_not_blocked_by_composition(): void {
		$this->assessments->method( 'get' )->willReturn( $this->assessment( AssessmentKind::EgeComputer ) );
		$this->attempts->method( 'countByAssessmentAndStudent' )->willReturn( 0 );
		$this->attempts->method( 'nextAttemptNumber' )->willReturn( 1 );
		$this->attempts->method( 'create' )->willReturn( 5 );
		$this->attempts->method( 'find' )->willReturn( $this->seededAttempt() );

		$attempt = $this->service->start( 99, 1, null );

		$this->assertSame( 5, $attempt->id );
	}

	public function test_control_start(): void {
		$this->assessments->method( 'get' )->willReturn( $this->assessment( AssessmentKind::Control ) );
		$this->attempts->method( 'countByAssessmentAndStudent' )->willReturn( 0 );
		$this->attempts->method( 'nextAttemptNumber' )->willReturn( 1 );
		$this->attempts->method( 'create' )->willReturn( 5 );
		$this->attempts->method( 'find' )->willReturn( $this->seededAttempt() );

		$attempt = $this->service->start( 99, 1, null );

		$this->assertSame( 5, $attempt->id );
	}

	/**
	 * Прямой заход (без `from_gl`): занятие и группа берутся из политики,
	 * иначе попытка осталась бы без привязки и выпала из отчётов.
	 */
	public function test_start_binds_attempt_to_accessible_lesson(): void {
		$this->assessments->method( 'get' )->willReturn( $this->assessment( AssessmentKind::Control ) );
		$this->attempts->method( 'countByAssessmentAndStudent' )->willReturn( 0 );
		$this->attempts->method( 'nextAttemptNumber' )->willReturn( 1 );
		$this->attempts->method( 'find' )->willReturn( $this->seededAttempt() );
		$this->attempts->expects( $this->once() )
			->method( 'create' )
			->with( $this->callback(
				static fn( $dto ): bool => 77 === $dto->groupLessonId && 9 === $dto->groupId
			) )
			->willReturn( 5 );

		$this->service->start( 99, 1, null, null );
	}

	/** Контекст из плеера важнее: пришёл `from_gl` — используем его. */
	public function test_explicit_lesson_from_player_wins(): void {
		$this->assessments->method( 'get' )->willReturn( $this->assessment( AssessmentKind::Control ) );
		$this->attempts->method( 'countByAssessmentAndStudent' )->willReturn( 0 );
		$this->attempts->method( 'nextAttemptNumber' )->willReturn( 1 );
		$this->attempts->method( 'find' )->willReturn( $this->seededAttempt() );
		$this->attempts->expects( $this->once() )
			->method( 'create' )
			->with( $this->callback(
				static fn( $dto ): bool => 55 === $dto->groupLessonId && 3 === $dto->groupId
			) )
			->willReturn( 5 );

		$this->service->start( 99, 1, 3, 55 );
	}

	/* ── D18: getResult() — гейт по AttemptRevealPolicy (не только UI finish.php,
	   но и сам AJAX-эндпоинт get_attempt_result, доступный станции даже после сдачи) ── */

	private function submittedAttempt(): AttemptDTO {
		return AttemptDTO::fromArray( array(
			'id' => 5, 'assessment_id' => 1, 'student_person_id' => 99,
			'group_id' => null, 'attempt_number' => 1,
			'started_at' => '2026-06-01 10:00:00', 'deadline_at' => '2026-06-01 11:00:00',
			'status' => 'submitted',
		) );
	}

	private function gradedAnswer(): \Inc\DTO\Assessment\AttemptAnswerDTO {
		return \Inc\DTO\Assessment\AttemptAnswerDTO::fromArray( array(
			'id' => 1, 'attempt_id' => 5, 'task_id' => 10, 'answer_text' => 'мой ответ',
			'is_correct' => 1, 'score' => 2.0, 'max_score' => 2.0, 'grader_note' => 'молодец',
		) );
	}

	public function test_get_result_scrubs_verdict_when_not_revealed(): void {
		$this->attempts->method( 'find' )->willReturn( $this->submittedAttempt() );
		$this->assessments->method( 'get' )->willReturn( $this->assessment( AssessmentKind::OgeComputer ) );
		$this->answers->method( 'listByAttempt' )->willReturn( array( $this->gradedAnswer() ) );
		$this->revealPolicy->method( 'isRevealed' )->willReturn( false );

		$result = $this->service->getResult( 5, 99 );
		$answer = $result['answers'][0];

		self::assertSame( 'мой ответ', $answer->answerText ); // свой ответ не секрет
		self::assertNull( $answer->isCorrect );
		self::assertNull( $answer->score );
		self::assertNull( $answer->criteriaScores );
		self::assertNull( $answer->graderNote );
	}

	public function test_get_result_keeps_verdict_when_revealed(): void {
		$this->attempts->method( 'find' )->willReturn( $this->submittedAttempt() );
		$this->assessments->method( 'get' )->willReturn( $this->assessment( AssessmentKind::OgeComputer ) );
		$this->answers->method( 'listByAttempt' )->willReturn( array( $this->gradedAnswer() ) );
		$this->revealPolicy->method( 'isRevealed' )->willReturn( true );

		$answer = $this->service->getResult( 5, 99 )['answers'][0];

		self::assertTrue( $answer->isCorrect );
		self::assertSame( 2.0, $answer->score );
	}

	/** Assessment не найден (крайний случай) — fail closed, зачищаем. */
	public function test_get_result_scrubs_when_assessment_missing(): void {
		$this->attempts->method( 'find' )->willReturn( $this->submittedAttempt() );
		$this->assessments->method( 'get' )->willReturn( null );
		$this->answers->method( 'listByAttempt' )->willReturn( array( $this->gradedAnswer() ) );
		$this->revealPolicy->expects( $this->never() )->method( 'isRevealed' );

		$answer = $this->service->getResult( 5, 99 )['answers'][0];

		self::assertNull( $answer->isCorrect );
		self::assertNull( $answer->score );
	}

	/** Занятие, через которое контрольная доступна ученику. */
	private function accessibleLesson(): GroupLessonDTO {
		return new GroupLessonDTO(
			id: 77, groupId: 9, lessonId: 1, position: 0, workIdsSnapshot: null, extraWorkIds: array(),
			scheduledAt: '2026-08-01 10:00:00', endsAt: null, isPinned: false, teacherUserId: null,
			visibility: 'open', openedAt: null, homeworkDueAt: null, allowLate: true, recordingUrl: null,
			createdByUserId: null, updatedByUserId: null, workDeadlines: array(),
		);
	}

	private function examAttempt( string $status = 'in_progress', string $deadline = '2026-06-01 11:00:00' ): AttemptDTO {
		return AttemptDTO::fromArray( array(
			'id' => 5, 'assessment_id' => 1, 'student_person_id' => 99, 'group_id' => null, 'attempt_number' => 1,
			'started_at' => '2026-06-01 10:00:00', 'deadline_at' => $deadline, 'status' => $status,
			'total_score' => 18, 'max_score' => 29, 'exam_participation_id' => 7, 'exam_registration_id' => 3,
		) );
	}

	/** Обход дедлайна: по старому маршруту официальную попытку сохранять и сдавать нельзя вовсе. */
	public function test_save_answer_refuses_exam_attempt_on_the_legacy_path(): void {
		$this->attempts->method( 'find' )->willReturn( $this->examAttempt() );
		$this->answers->expects( self::never() )->method( 'upsert' );

		$this->expectException( \RuntimeException::class );

		$this->service->saveAnswer( 5, 10, 'ответ', 99 );
	}

	public function test_submit_refuses_exam_attempt_on_the_legacy_path_even_after_deadline(): void {
		$this->attempts->method( 'find' )->willReturn( $this->examAttempt( 'in_progress', '2026-06-01 09:00:00' ) );
		$this->attempts->expects( self::never() )->method( 'update' );

		$this->expectException( \RuntimeException::class );

		$this->service->submit( 5, 99 );
	}

	public function test_expire_if_overdue_ignores_exam_attempt(): void {
		$this->attempts->method( 'find' )->willReturn( $this->examAttempt( 'in_progress', '2026-06-01 09:00:00' ) );
		$this->attempts->expects( self::never() )->method( 'update' );

		self::assertFalse( $this->service->expireIfOverdue( 5 ) );
	}

	public function test_save_answer_for_rejects_task_outside_assessment(): void {
		$this->assessments->method( 'get' )->willReturn( $this->assessment( AssessmentKind::EgeComputer ) );
		$this->answers->expects( self::never() )->method( 'upsert' );

		$this->expectException( \InvalidArgumentException::class );

		$this->service->saveAnswerFor( $this->examAttempt(), 999, 'ответ' );
	}

	public function test_submit_for_writes_given_submission_time_and_grades(): void {
		$attempt = $this->examAttempt();
		$graded  = $this->examAttempt( 'graded' );
		$this->attempts->expects( self::once() )->method( 'update' )
			->with( 5, array( 'status' => 'submitted', 'submitted_at' => '2026-06-01 11:00:00' ) )
			->willReturn( true );
		$this->attempts->method( 'find' )->willReturn( $this->examAttempt( 'submitted' ) );
		$this->autoGrade->expects( self::once() )->method( 'gradeAttempt' )->willReturn( $graded );

		self::assertSame( $graded, $this->service->submitFor( $attempt, '2026-06-01 11:00:00' ) );
	}

	public function test_submit_for_defaults_to_clock_time(): void {
		$this->attempts->expects( self::once() )->method( 'update' )
			->with( 5, array( 'status' => 'submitted', 'submitted_at' => '2026-06-01 10:00:00' ) )
			->willReturn( true );
		$this->attempts->method( 'find' )->willReturn( $this->examAttempt( 'submitted' ) );
		$this->autoGrade->method( 'gradeAttempt' )->willReturn( $this->examAttempt( 'graded' ) );

		$this->service->submitFor( $this->examAttempt() );
	}

	public function test_submit_for_fails_loudly_when_status_was_not_written(): void {
		$this->attempts->method( 'update' )->willReturn( false );
		$this->autoGrade->expects( self::never() )->method( 'gradeAttempt' );
		$this->dispatcher->expects( self::never() )->method( 'dispatch' );

		$this->expectException( \RuntimeException::class );

		$this->service->submitFor( $this->examAttempt() );
	}

	public function test_get_result_hides_totals_when_not_revealed(): void {
		$this->attempts->method( 'find' )->willReturn( $this->examAttempt( 'submitted' ) );
		$this->assessments->method( 'get' )->willReturn( $this->assessment( AssessmentKind::EgeComputer ) );
		$this->answers->method( 'listByAttempt' )->willReturn( array() );
		$this->revealPolicy->method( 'isRevealed' )->willReturn( false );

		$attempt = $this->service->getResult( 5, 99 )['attempt'];

		self::assertNull( $attempt->totalScore );
		self::assertNull( $attempt->maxScore );
	}

	public function test_get_result_keeps_totals_when_revealed(): void {
		$this->attempts->method( 'find' )->willReturn( $this->examAttempt( 'submitted' ) );
		$this->assessments->method( 'get' )->willReturn( $this->assessment( AssessmentKind::EgeComputer ) );
		$this->answers->method( 'listByAttempt' )->willReturn( array() );
		$this->revealPolicy->method( 'isRevealed' )->willReturn( true );

		self::assertSame( 18.0, $this->service->getResult( 5, 99 )['attempt']->totalScore );
	}

	public function test_is_revealed_delegates_to_policy_and_fails_closed_without_assessment(): void {
		$this->assessments->method( 'get' )->willReturnOnConsecutiveCalls( $this->assessment( AssessmentKind::Control ), null );
		$this->revealPolicy->expects( self::once() )->method( 'isRevealed' )->willReturn( true );

		self::assertTrue( $this->service->isRevealed( $this->examAttempt( 'submitted' ) ) );
		self::assertFalse( $this->service->isRevealed( $this->examAttempt( 'submitted' ) ), 'нет работы — не раскрываем' );
	}
}
