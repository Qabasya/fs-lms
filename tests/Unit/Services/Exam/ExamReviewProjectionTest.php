<?php

declare( strict_types=1 );

namespace Tests\Unit\Services\Exam;

use Inc\DTO\Assessment\AttemptDTO;
use Inc\DTO\Exam\ExamEventDTO;
use Inc\DTO\Exam\ExamParticipationDTO;
use Inc\Enums\Assessment\AttemptStatus;
use Inc\Repositories\WPDBRepositories\AssessmentAttemptRepository;
use Inc\Repositories\WPDBRepositories\ExamEventRepository;
use Inc\Repositories\WPDBRepositories\ExamParticipationRepository;
use Inc\Services\Assessment\AttemptService;
use Inc\Services\Course\WorkDetailService;
use Inc\Services\Exam\ExamReviewProjection;
use Inc\Services\Exam\ExamScoreService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ExamReviewProjectionTest extends TestCase {

	private WorkDetailService&MockObject $details;
	private AssessmentAttemptRepository&MockObject $attempts;
	private ExamParticipationRepository&MockObject $participations;
	private ExamEventRepository&MockObject $events;
	private AttemptService&MockObject $attemptService;
	private ExamScoreService&MockObject $scores;
	private ExamReviewProjection $projection;

	protected function setUp(): void {
		parent::setUp();
		$this->details        = $this->createMock( WorkDetailService::class );
		$this->attempts       = $this->createMock( AssessmentAttemptRepository::class );
		$this->participations = $this->createMock( ExamParticipationRepository::class );
		$this->events         = $this->createMock( ExamEventRepository::class );
		$this->attemptService = $this->createMock( AttemptService::class );
		$this->scores         = $this->createMock( ExamScoreService::class );

		$this->projection = new ExamReviewProjection(
			$this->details, $this->attempts, $this->participations, $this->events, $this->attemptService, $this->scores,
		);
	}

	private function attempt( int $id = 11, ?int $participationId = 7, int $resultVersion = 3 ): AttemptDTO {
		return new AttemptDTO(
			id: $id, assessmentId: 5, studentPersonId: 3, groupId: null, attemptNumber: 1,
			startedAt: '2026-03-10 10:00:00', deadlineAt: '2026-03-10 13:55:00', submittedAt: '2026-03-10 12:00:00',
			status: AttemptStatus::Graded, totalScore: 18.0, maxScore: 29.0, gradedByUserId: null,
			createdAt: '2026-03-10 10:00:00', updatedAt: '2026-03-10 12:00:00',
			examParticipationId: $participationId, examRegistrationId: 2, resultVersion: $resultVersion,
		);
	}

	/** @return array<string, mixed> */
	private function detail(): array {
		return array(
			'kind' => 'exam', 'title' => 'ЕГЭ', 'status' => 'graded', 'score' => 18.0, 'max_score' => 29.0,
			'attempt_id' => 11, 'group_id' => 0, 'student_name' => 'Иванов Иван', 'review_url' => 'https://example.test/sheet',
			'gradable' => false, 'submission_id' => null,
			'tasks' => array(
				array(
					'n' => 1, 'task_id' => 42, 'unit_key' => 'n:1', 'number' => '1', 'anchor' => 'u-abc',
					'condition' => '<p>Условие</p>', 'answer' => '5', 'code' => 'print(5)', 'files' => array( array( 'url' => 'x' ) ),
					'correct' => '5', 'solution' => null, 'verdict' => 'correct', 'score' => 1.0, 'max_score' => 1.0,
					'manual' => true, 'manually_graded' => false, 'feedback' => 'Хорошо',
					'criteria' => array( array( 'label' => 'К1', 'max_points' => 2.0, 'awarded' => 1.0 ) ),
					'oge_rubric' => array( 'max_points' => 3, 'html' => '<p>Уровни</p>' ), 'review_url' => 'https://example.test/t',
				),
			),
		);
	}

	public function test_read_only_unrevealed_has_no_tasks_and_scores(): void {
		$this->attempts->method( 'find' )->willReturn( $this->attempt() );
		$this->attemptService->method( 'isRevealed' )->willReturn( false );
		$this->details->expects( self::never() )->method( 'forWork' );

		$result = $this->projection->forViewer( 11, ExamReviewProjection::MODE_READ_ONLY );

		self::assertSame( array( 'revealed' => false, 'status' => 'graded' ), $result );
	}

	public function test_read_only_revealed_has_no_grading_identifiers(): void {
		$this->attempts->method( 'find' )->willReturn( $this->attempt() );
		$this->attemptService->method( 'isRevealed' )->willReturn( true );
		$this->details->method( 'forWork' )->willReturn( $this->detail() );

		$result = $this->projection->forViewer( 11, ExamReviewProjection::MODE_READ_ONLY );

		self::assertTrue( $result['revealed'] );
		foreach ( array( 'attempt_id', 'group_id', 'student_name', 'review_url', 'gradable', 'submission_id' ) as $key ) {
			self::assertArrayNotHasKey( $key, $result, "Корень не должен содержать {$key}" );
		}
		foreach ( array( 'task_id', 'manual', 'oge_rubric', 'review_url' ) as $key ) {
			self::assertArrayNotHasKey( $key, $result['tasks'][0], "Задача не должна содержать {$key}" );
		}
		// Данные для показа остаются: набранные по критериям баллы, ответ, код, файлы, комментарий.
		self::assertSame( 1.0, $result['tasks'][0]['criteria'][0]['awarded'] );
		self::assertSame( 'print(5)', $result['tasks'][0]['code'] );
		self::assertCount( 1, $result['tasks'][0]['files'] );
		self::assertSame( 'Хорошо', $result['tasks'][0]['feedback'] );
		self::assertSame( 'u-abc', $result['tasks'][0]['anchor'] );
	}

	public function test_oge_files_code_and_tables_are_kept_in_read_only(): void {
		// ОГЭ: ответ-таблица, программа и прикреплённые файлы — это то, что ученик сдал; после раскрытия он должен всё это увидеть.
		// Оценочные поля (рубрика уровней, идентификаторы задания, ссылка проверки) в режиме «только чтение» не отдаются.
		$detail                         = $this->detail();
		$detail['tasks'][0]['answer']   = "Имя|Балл\nАня|5\nБорис|4";
		$detail['tasks'][0]['code']     = "for i in range(3):\n    print(i)";
		$detail['tasks'][0]['files']    = array( array( 'name' => 'work.xlsx', 'url' => 'https://example.test/work.xlsx' ), array( 'name' => 'prog.py', 'url' => 'https://example.test/prog.py' ) );
		$this->attempts->method( 'find' )->willReturn( $this->attempt() );
		$this->attemptService->method( 'isRevealed' )->willReturn( true );
		$this->details->method( 'forWork' )->willReturn( $detail );

		$task = $this->projection->forViewer( 11, ExamReviewProjection::MODE_READ_ONLY )['tasks'][0];

		self::assertSame( "Имя|Балл\nАня|5\nБорис|4", $task['answer'], 'Таблица ответа сохраняет построчную структуру.' );
		self::assertSame( "for i in range(3):\n    print(i)", $task['code'], 'Код сохраняет переносы и отступы.' );
		self::assertSame( array( 'work.xlsx', 'prog.py' ), array_column( $task['files'], 'name' ) );
		self::assertArrayNotHasKey( 'oge_rubric', $task );
		self::assertArrayNotHasKey( 'task_id', $task );
	}

	public function test_manage_has_result_version(): void {
		$this->attempts->method( 'find' )->willReturn( $this->attempt( 11, 7, 3 ) );
		$this->attemptService->expects( self::never() )->method( 'isRevealed' );
		$this->details->method( 'forWork' )->willReturn( $this->detail() );

		$result = $this->projection->forViewer( 11, ExamReviewProjection::MODE_MANAGE );

		self::assertSame( 3, $result['result_version'] );
		self::assertSame( 42, $result['tasks'][0]['task_id'] );
		self::assertSame( 11, $result['attempt_id'] );
	}

	public function test_course_attempt_is_not_exposed(): void {
		$this->attempts->method( 'find' )->willReturn( $this->attempt( 11, null ) );

		self::assertNull( $this->projection->forViewer( 11, ExamReviewProjection::MODE_MANAGE ) );
	}

	public function test_unknown_attempt_returns_null(): void {
		$this->attempts->method( 'find' )->willReturn( null );

		self::assertNull( $this->projection->forViewer( 99, ExamReviewProjection::MODE_READ_ONLY ) );
	}

	public function test_unknown_mode_is_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );

		$this->projection->forViewer( 11, 'edit' );
	}

	/* ── forStudent: попытка определяется участием, а не запросом ── */

	private function participation( ?int $currentAttemptId = 11 ): ExamParticipationDTO {
		return ExamParticipationDTO::fromArray( array(
			'id' => 7, 'event_id' => 1, 'participant_id' => 4, 'audience' => 'student', 'current_attempt_id' => $currentAttemptId, 'transfer_allowed' => 0,
			'version' => 1, 'created_at' => '2026-03-01 00:00:00', 'updated_at' => '2026-03-01 00:00:00',
		) );
	}

	private function event(): ExamEventDTO {
		return ExamEventDTO::fromArray( array(
			'id' => 1, 'subject_key' => 'inf', 'title' => 'Экзамен', 'owner_user_id' => 1, 'status' => 'published',
			'period_from' => '2026-03-01', 'period_to' => '2026-03-31', 'guest_registration_enabled' => 0, 'version' => 1,
			'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
		) );
	}

	public function test_student_review_is_unavailable_without_participation(): void {
		$this->participations->method( 'findByEventAndPerson' )->willReturn( null );
		$this->events->method( 'find' )->willReturn( $this->event() );

		self::assertNull( $this->projection->forStudent( 3, 1 ) );
	}

	public function test_student_review_is_unavailable_without_attempt(): void {
		$this->participations->method( 'findByEventAndPerson' )->willReturn( $this->participation( null ) );
		$this->events->method( 'find' )->willReturn( $this->event() );

		self::assertNull( $this->projection->forStudent( 3, 1 ) );
	}

	public function test_student_review_of_attempt_of_another_participation_is_denied(): void {
		$this->participations->method( 'findByEventAndPerson' )->willReturn( $this->participation( 11 ) );
		$this->events->method( 'find' )->willReturn( $this->event() );
		// Попытка 11 принадлежит участию 8, а не участию ученика (7) — рассинхрон данных не должен открывать чужое.
		$this->attempts->method( 'find' )->willReturn( $this->attempt( 11, 8 ) );

		self::assertNull( $this->projection->forStudent( 3, 1 ) );
	}

	public function test_student_review_before_approval_returns_unrevealed_without_tasks(): void {
		$this->participations->method( 'findByEventAndPerson' )->willReturn( $this->participation() );
		$this->events->method( 'find' )->willReturn( $this->event() );
		$this->attempts->method( 'find' )->willReturn( $this->attempt() );
		$this->attemptService->method( 'isRevealed' )->willReturn( false );
		$this->details->expects( self::never() )->method( 'forWork' );
		$this->scores->expects( self::never() )->method( 'summarize' );

		$result = $this->projection->forStudent( 3, 1 );

		self::assertSame( array( 'revealed' => false, 'status' => 'graded' ), $result );
	}

	public function test_student_review_after_approval_has_result_and_units(): void {
		$this->participations->method( 'findByEventAndPerson' )->willReturn( $this->participation() );
		$this->events->method( 'find' )->willReturn( $this->event() );
		$this->attempts->method( 'find' )->willReturn( $this->attempt() );
		$this->attemptService->method( 'isRevealed' )->willReturn( true );
		$this->details->method( 'forWork' )->willReturn( $this->detail() );
		$this->scores->method( 'summarize' )->willReturn( array( 'primary' => 18 ) );
		// Единицы считаются по неочищенному разбору: им нужен task_id.
		$this->scores->expects( self::once() )->method( 'units' )
			->with( self::anything(), self::callback( static fn ( array $tasks ): bool => 42 === $tasks[0]['task_id'] ) )
			->willReturn( array( array( 'number' => '1' ) ) );

		$result = $this->projection->forStudent( 3, 1 );

		self::assertTrue( $result['revealed'] );
		self::assertSame( 18, $result['result']['primary'] );
		self::assertSame( '1', $result['units'][0]['number'] );
		self::assertArrayNotHasKey( 'task_id', $result['tasks'][0] );
	}
}
