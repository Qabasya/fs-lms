<?php

declare( strict_types=1 );

namespace Unit\Callbacks\Course;

use Inc\Callbacks\Course\BatchSubmissionCallbacks;
use Inc\DTO\Course\SubmissionDTO;
use Inc\DTO\Person\PersonDTO;
use Inc\Enums\Course\SubmissionStatus;
use Inc\Enums\Course\WorkType;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Repositories\WPDBRepositories\SubmissionRepository;
use Inc\Services\Course\GroupAccessGuard;
use Inc\Services\Course\SubmissionService;
use PHPUnit\Framework\TestCase;

/**
 * Тесты пакетной сдачи работы. Фокус — T14.11: ответ SubmitBatchWork несёт
 * per-task вердикты (из answer_text агрегатной строки) для экрана результатов плеера.
 * D4 (.docs/Tasks.md): ajaxGradeBatchTask() — поштучное оценивание submission-работы,
 * та же проверка доступа (canWriteJournal), что у GradingCallbacks::ajaxSaveGrade().
 */
class BatchSubmissionCallbacksTest extends TestCase {

	private SubmissionService        $service;
	private PersonRepository         $persons;
	private SubmissionRepository     $submissionRepo;
	private GroupLessonRepository    $groupLessons;
	private GroupAccessGuard         $guard;
	private BatchSubmissionCallbacks $cb;

	protected function setUp(): void {
		parent::setUp();
		fs_test_reset_ajax();
		$GLOBALS['_fs_test_user_id'] = 7;

		$this->service        = $this->createMock( SubmissionService::class );
		$this->persons        = $this->createMock( PersonRepository::class );
		$this->submissionRepo = $this->createMock( SubmissionRepository::class );
		$this->groupLessons   = $this->createMock( GroupLessonRepository::class );
		$this->guard          = $this->createMock( GroupAccessGuard::class );
		$this->cb             = new BatchSubmissionCallbacks(
			$this->service, $this->persons, $this->submissionRepo, $this->groupLessons, $this->guard
		);
	}

	private function aggregate( string $verdictsJson, float $score, float $maxScore ): SubmissionDTO {
		return new SubmissionDTO(
			id             : 10,
			studentPersonId: 99,
			groupLessonId  : 5,
			workId         : 55,
			workType       : WorkType::Practice,
			taskId         : null,
			answerText     : $verdictsJson,
			attachmentId   : null,
			dueAt          : null,
			status         : SubmissionStatus::PendingReview,
			score          : $score,
			maxScore       : $maxScore,
			feedback       : null,
			gradedByUserId : null,
			submittedAt    : '2026-07-03 12:00:00',
			gradedAt       : null,
			createdAt      : '2026-07-03 12:00:00',
			updatedAt      : '2026-07-03 12:00:00',
		);
	}

	public function test_submit_batch_returns_per_task_verdicts(): void {
		$this->persons->method( 'findByWpUserId' )->with( 7 )
			->willReturn( PersonDTO::fromArray( array( 'id' => 99, 'created_at' => '2024-01-01 00:00:00', 'updated_at' => '2024-01-01 00:00:00' ) ) );

		$verdicts = '{"71":{"verdict":"correct","score":1,"maxScore":1},"72":{"verdict":"pending","score":0,"maxScore":1}}';
		$this->service->method( 'submitBatch' )
			->with( 99, 5, 55, array( '71' => array( 'o2' ), '72' => 'текст' ) )
			->willReturn( $this->aggregate( $verdicts, 1.0, 2.0 ) );

		$_POST = array(
			'group_lesson_id' => '5',
			'work_id'         => '55',
			'answers'         => '{"71":["o2"],"72":"текст"}',
		);

		$r = fs_test_capture_json( fn() => $this->cb->ajaxSubmitBatchWork() );

		self::assertTrue( $r->success );
		self::assertSame( 'pending_review', $r->payload['status'] );
		self::assertSame( 1, $r->payload['correct'] );
		self::assertSame( 2, $r->payload['total'] );
		self::assertSame( 'correct', $r->payload['per_task']['71']['verdict'] );
		self::assertSame( 'pending', $r->payload['per_task']['72']['verdict'] );
		self::assertSame( '2026-07-03 12:00:00', $r->payload['submitted_at'] );
	}

	public function test_submit_batch_with_non_json_aggregate_returns_empty_per_task(): void {
		$this->persons->method( 'findByWpUserId' )->willReturn(
			PersonDTO::fromArray( array( 'id' => 99, 'created_at' => '2024-01-01 00:00:00', 'updated_at' => '2024-01-01 00:00:00' ) )
		);
		$this->service->method( 'submitBatch' )->willReturn( $this->aggregate( 'не json', 0.0, 2.0 ) );

		$_POST = array( 'group_lesson_id' => '5', 'work_id' => '55', 'answers' => '{}' );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxSubmitBatchWork() );

		self::assertTrue( $r->success );
		self::assertSame( array(), $r->payload['per_task'] );
	}

	public function test_submit_batch_invalid_answers_errors(): void {
		$this->service->expects( $this->never() )->method( 'submitBatch' );
		$_POST = array( 'group_lesson_id' => '5', 'work_id' => '55', 'answers' => 'мусор' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxSubmitBatchWork() )->success );
	}

	public function test_submit_batch_access_violation_errors(): void {
		$this->persons->method( 'findByWpUserId' )->willReturn(
			PersonDTO::fromArray( array( 'id' => 99, 'created_at' => '2024-01-01 00:00:00', 'updated_at' => '2024-01-01 00:00:00' ) )
		);
		$this->service->method( 'submitBatch' )
			->willThrowException( new \InvalidArgumentException( 'Сдача недоступна.' ) );

		$_POST = array( 'group_lesson_id' => '5', 'work_id' => '55', 'answers' => '{}' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxSubmitBatchWork() )->success );
	}

	// ── ajaxGradeBatchTask (D4) ───────────────────────────────────────────────

	private function perTaskRow( int $id, int $groupLessonId ): SubmissionDTO {
		return new SubmissionDTO(
			id: $id, studentPersonId: 99, groupLessonId: $groupLessonId, workId: 55, workType: WorkType::Practice,
			taskId: 71, answerText: 'ответ', attachmentId: null, dueAt: null,
			status: SubmissionStatus::PendingReview, score: null, maxScore: 1.0, feedback: null,
			gradedByUserId: null, submittedAt: '2026-07-03 12:00:00', gradedAt: null,
			createdAt: '', updatedAt: '',
		);
	}

	public function test_grade_batch_task_not_found_errors(): void {
		$this->submissionRepo->method( 'find' )->willReturn( null );
		$this->service->expects( $this->never() )->method( 'gradeBatchTask' );
		$_POST = array( 'submission_id' => '501', 'score' => '1' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxGradeBatchTask() )->success );
	}

	public function test_grade_batch_task_denies_without_group_access(): void {
		$this->submissionRepo->method( 'find' )->willReturn( $this->perTaskRow( 501, 5 ) );
		$this->groupLessons->method( 'find' )->with( 5 )->willReturn(
			\Inc\DTO\Course\GroupLessonDTO::fromArray( array( 'id' => 5, 'group_id' => 7, 'position' => 1 ) )
		);
		$this->guard->method( 'canWriteJournal' )->with( 7, 7 )->willReturn( false );
		$this->service->expects( $this->never() )->method( 'gradeBatchTask' );
		$_POST = array( 'submission_id' => '501', 'score' => '1' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxGradeBatchTask() )->success );
	}

	public function test_grade_batch_task_grades_when_access_allowed(): void {
		$this->submissionRepo->method( 'find' )->willReturn( $this->perTaskRow( 501, 5 ) );
		$this->groupLessons->method( 'find' )->with( 5 )->willReturn(
			\Inc\DTO\Course\GroupLessonDTO::fromArray( array( 'id' => 5, 'group_id' => 7, 'position' => 1 ) )
		);
		$this->guard->method( 'canWriteJournal' )->willReturn( true );
		$this->service->expects( $this->once() )
			->method( 'gradeBatchTask' )
			->with( 501, 1.0, 'Хорошо', 7 );
		$_POST = array( 'submission_id' => '501', 'score' => '1', 'feedback' => 'Хорошо' );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxGradeBatchTask() );

		self::assertTrue( $r->success );
		self::assertSame( 501, $r->payload['submission_id'] );
	}
}
