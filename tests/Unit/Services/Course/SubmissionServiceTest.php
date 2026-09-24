<?php

declare( strict_types=1 );

namespace Unit\Services\Course;

use Inc\Contracts\ClockInterface;
use Inc\Contracts\LogEventDispatcherInterface;
use Inc\DTO\Course\BatchCheckResultDTO;
use Inc\DTO\Course\GradeDTO;
use Inc\DTO\Course\GroupLessonDTO;
use Inc\DTO\Course\SubmissionDTO;
use Inc\DTO\Course\SubmissionInputDTO;
use Inc\DTO\Course\WorkDTO;
use Inc\DTO\Course\WorkTimingDTO;
use Inc\DTO\Log\Events\LearningEvent;
use Inc\DTO\Person\PersonDTO;
use Inc\Enums\Log\LogEvent;
use Inc\Enums\Course\AttemptSource;
use Inc\Enums\Course\SubmissionStatus;
use Inc\Enums\Course\WorkType;
use Inc\Managers\Wp\MediaManager;
use Inc\Managers\Course\WorkManager;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Repositories\WPDBRepositories\SubmissionRepository;
use Inc\Repositories\WPDBRepositories\TaskAttemptRepository;
use Inc\Services\Course\BatchCheckService;
use Inc\Services\Course\EffectiveWorksResolver;
use Inc\Services\Course\LessonAccessPolicy;
use Inc\Services\Course\SubmissionService;
use PHPUnit\Framework\TestCase;

class SubmissionServiceTest extends TestCase {

	private SubmissionRepository&\PHPUnit\Framework\MockObject\MockObject $submissions;
	private GroupLessonRepository&\PHPUnit\Framework\MockObject\MockObject $groupLessons;
	private EffectiveWorksResolver&\PHPUnit\Framework\MockObject\MockObject $resolver;
	private WorkManager&\PHPUnit\Framework\MockObject\MockObject $workManager;
	private MediaManager&\PHPUnit\Framework\MockObject\MockObject $mediaManager;
	private LessonAccessPolicy&\PHPUnit\Framework\MockObject\MockObject $policy;
	private LogEventDispatcherInterface&\PHPUnit\Framework\MockObject\MockObject $dispatcher;
	private BatchCheckService&\PHPUnit\Framework\MockObject\MockObject $batchChecker;
	private ClockInterface&\PHPUnit\Framework\MockObject\MockObject $clock;
	private TaskAttemptRepository&\PHPUnit\Framework\MockObject\MockObject $taskAttempts;
	private PersonRepository&\PHPUnit\Framework\MockObject\MockObject $persons;
	private SubmissionService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->submissions  = $this->createMock( SubmissionRepository::class );
		$this->groupLessons = $this->createMock( GroupLessonRepository::class );
		$this->resolver     = $this->createMock( EffectiveWorksResolver::class );
		$this->workManager  = $this->createMock( WorkManager::class );
		$this->mediaManager = $this->createMock( MediaManager::class );
		$this->policy       = $this->createMock( LessonAccessPolicy::class );
		$this->batchChecker = $this->createMock( BatchCheckService::class );
		$this->dispatcher   = $this->createMock( LogEventDispatcherInterface::class );
		$this->clock        = $this->createMock( ClockInterface::class );
		$this->clock->method( 'now' )->willReturn( '2024-06-01 12:00:00' );
		$this->taskAttempts = $this->createMock( TaskAttemptRepository::class );
		$this->persons      = $this->createMock( PersonRepository::class );

		$this->service = new SubmissionService(
			$this->submissions,
			$this->groupLessons,
			$this->resolver,
			$this->workManager,
			$this->mediaManager,
			$this->policy,
			$this->batchChecker,
			$this->dispatcher,
			$this->clock,
			$this->taskAttempts,
			$this->persons,
		);
	}

	private function makeRow( int $workId = 3, bool $allowLate = true, ?string $dueAt = null, array $workDeadlines = [] ): GroupLessonDTO {
		return new GroupLessonDTO(
			id              : 5,
			groupId         : 1,
			lessonId        : 10,
			position        : 0,
			workIdsSnapshot : null,
			extraWorkIds    : [],
			scheduledAt     : null,
			endsAt          : null,
			isPinned        : false,
			teacherUserId   : null,
			visibility      : 'open',
			openedAt        : '2024-01-01 00:00:00',
			homeworkDueAt   : $dueAt,
			allowLate       : $allowLate,
			recordingUrl    : null,
			createdByUserId : null,
			updatedByUserId : null,
			workDeadlines   : $workDeadlines,
		);
	}

	private function makeWork( int $id, WorkType $type = WorkType::Practice ): WorkDTO {
		return new WorkDTO(
			id         : $id,
			subjectKey : 'inf',
			title      : "Work #$id",
			workType   : $type,
			itemIds    : [],
			instructions: '',
			authorId   : 1,
			status     : 'publish',
		);
	}

	private function makeSubmission( int $id, SubmissionStatus $status ): SubmissionDTO {
		return new SubmissionDTO(
			id               : $id,
			studentPersonId  : 10,
			groupLessonId    : 5,
			workId           : 3,
			workType         : WorkType::Practice,
			taskId           : null,
			answerText       : 'old answer',
			attachmentId     : null,
			dueAt            : null,
			status           : $status,
			score            : null,
			maxScore         : null,
			feedback         : null,
			gradedByUserId   : null,
			submittedAt      : '2024-01-01 10:00:00',
			gradedAt         : null,
			createdAt        : '2024-01-01 00:00:00',
			updatedAt        : '2024-01-01 00:00:00',
		);
	}

	public function test_submit_batch_throws_when_canSubmit_false(): void {
		$this->policy->method( 'canSubmit' )->willReturn( false );

		$this->expectException( \InvalidArgumentException::class );
		$this->service->submitBatch( 10, 5, 3, [ 1 => 'a' ] );
	}

	public function test_submit_batch_throws_when_work_not_in_effective_set(): void {
		$this->policy->method( 'canSubmit' )->willReturn( true );
		$this->groupLessons->method( 'find' )->willReturn( $this->makeRow() );
		$this->resolver->method( 'resolve' )->willReturn( [ $this->makeWork( 99 ) ] );

		$this->expectException( \InvalidArgumentException::class );
		$this->service->submitBatch( 10, 5, 3, [ 1 => 'a' ] );
	}

	/** D13: просроченный дедлайн занятия закрывает сдачу, если allow_late выключен. */
	public function test_submit_batch_throws_when_late_and_allow_late_false(): void {
		$this->arrangeBatch( $this->makeRow( 3, false, '2000-01-01 00:00:00' ) );

		$this->expectException( \InvalidArgumentException::class );
		$this->service->submitBatch( 10, 5, 3, [ 1 => 'a' ] );
	}

	public function test_submit_batch_succeeds_when_late_and_allow_late_true(): void {
		$this->arrangeBatch( $this->makeRow( 3, true, '2000-01-01 00:00:00' ) );

		self::assertInstanceOf( SubmissionDTO::class, $this->service->submitBatch( 10, 5, 3, [ 1 => 'a' ] ) );
	}

	/** D13: per-work дедлайн важнее legacy homework_due_at занятия. */
	public function test_submit_batch_uses_per_work_deadline_over_legacy_due_at(): void {
		// Занятие без просрочки, но у работы свой дедлайн в прошлом.
		$this->arrangeBatch( $this->makeRow( 3, false, null, [ 3 => '2000-01-01 00:00:00' ] ) );

		$this->expectException( \InvalidArgumentException::class );
		$this->service->submitBatch( 10, 5, 3, [ 1 => 'a' ] );
	}

	public function test_submit_batch_per_work_deadline_in_future_bypasses_expired_legacy_block(): void {
		// Legacy-дедлайн занятия истёк, но у работы свой — в будущем.
		$this->arrangeBatch( $this->makeRow( 3, false, '2000-01-01 00:00:00', [ 3 => '2100-01-01 00:00:00' ] ) );

		self::assertInstanceOf( SubmissionDTO::class, $this->service->submitBatch( 10, 5, 3, [ 1 => 'a' ] ) );
	}

	/**
	 * История пересдач: строка submissions перезаписывается, поэтому каждая
	 * сдача дополнительно уходит в task_attempts с ключом `work:{id}`.
	 */
	public function test_submit_batch_records_attempt_history(): void {
		$this->arrangeBatch( $this->makeRow() );
		// Одна сдача уже была — эта вторая: номер попытки = номер сдачи работы.
		$this->taskAttempts->method( 'maxAttemptNumberByStep' )->willReturn( 1 );
		$this->taskAttempts->expects( $this->once() )
			->method( 'create' )
			->with(
				10,
				5,
				AttemptSource::workStepKey( 3 ),
				1,
				2,               // предыдущая сдача была одна → эта вторая
				'a',
				true,
				1.0,
				1.0,
				array()
			);

		$this->service->submitBatch( 10, 5, 3, array( 1 => 'a' ) );
	}

	/** В ленту «Активность» уходит WP-пользователь ученика, а не ID его персоны. */
	public function test_submit_batch_event_actor_is_wp_user_not_person(): void {
		$this->arrangeBatch( $this->makeRow() );
		$this->persons->method( 'find' )->with( 10 )->willReturn(
			new PersonDTO( 10, 77, 'Иванов', 'Иван', null, null, true, null, null, null, '', '' )
		);
		$this->dispatcher->expects( $this->once() )->method( 'dispatch' )
			->with(
				LogEvent::SubmissionMade,
				$this->callback( fn( LearningEvent $e ) => 77 === $e->actorUserId )
			);

		$this->service->submitBatch( 10, 5, 3, array( 1 => 'a' ) );
	}

	/** Общая обвязка успешного пути submitBatch: доступ, работа, пустой вердикт. */
	/** Замер плеера: длительность раунда — в агрегат и в историю, момент ответа — в историю. */
	public function test_submit_batch_records_timing(): void {
		$this->arrangeBatch( $this->makeRow() );

		$this->taskAttempts->expects( $this->once() )->method( 'create' )->with(
			$this->anything(), $this->anything(), $this->anything(), 1, 1, 'a', true, 1.0, 1.0, array(),
			600,
			'2024-06-01 11:58:00',
		);
		$this->submissions->expects( $this->once() )->method( 'update' )->with(
			1,
			$this->callback( static fn( array $data ): bool => 600 === $data['duration_sec'] )
		);

		$this->service->submitBatch( 10, 5, 3, [ 1 => 'a' ], timing: new WorkTimingDTO( 600, [ 1 => 120 ] ) );
	}

	/** Без замера (старый плеер) колонки остаются пустыми. */
	public function test_submit_batch_without_timing_stores_nulls(): void {
		$this->arrangeBatch( $this->makeRow() );

		$this->taskAttempts->expects( $this->once() )->method( 'create' )->with(
			$this->anything(), $this->anything(), $this->anything(), 1, 1, 'a', true, 1.0, 1.0, array(), null, null,
		);

		$this->service->submitBatch( 10, 5, 3, [ 1 => 'a' ] );
	}

	private function arrangeBatch( GroupLessonDTO $row ): void {
		$this->policy->method( 'canSubmit' )->willReturn( true );
		$this->groupLessons->method( 'find' )->willReturn( $row );
		$this->resolver->method( 'resolve' )->willReturn( [ $this->makeWork( 3 ) ] );
		$this->workManager->method( 'get' )->willReturn( $this->makeWork( 3 ) );
		$this->batchChecker->method( 'check' )->willReturn(
			new BatchCheckResultDTO(
				perTask         : [ 1 => [ 'verdict' => 'correct', 'score' => 1.0, 'maxScore' => 1.0 ] ],
				correctCount    : 1,
				totalCount      : 1,
				weightedScore   : 1.0,
				maxWeightedScore: 1.0,
				hasManual       : false,
			)
		);
		$this->submissions->method( 'findForWork' )->willReturn( null );
		// Первый вызов — до вставки (агрегата ещё нет), второй — после (сервис
		// возвращает свежую строку).
		$this->submissions->method( 'findAggregate' )->willReturnOnConsecutiveCalls(
			null,
			$this->makeSubmission( 1, SubmissionStatus::Submitted )
		);
		$this->submissions->method( 'create' )->willReturn( 1 );
		$this->submissions->method( 'find' )->willReturn( $this->makeSubmission( 1, SubmissionStatus::Submitted ) );
	}

	public function test_grade_updates_status_and_dispatches_event(): void {
		$sub = $this->makeSubmission( 7, SubmissionStatus::Submitted );
		$this->submissions->method( 'find' )->willReturn( $sub );
		$this->groupLessons->method( 'find' )->willReturn( $this->makeRow() );
		$this->submissions->expects( $this->once() )->method( 'update' )
			->with( 7, $this->callback( fn( $d ) => $d['status'] === 'graded' && $d['score'] === 85.0 ) );
		$this->dispatcher->expects( $this->once() )->method( 'dispatch' )
			->with( LogEvent::SubmissionGraded );

		$this->service->grade( 7, new GradeDTO( 85.0, 100.0, 'Well done' ), 99 );
	}

	public function test_grade_throws_when_submission_not_found(): void {
		$this->submissions->method( 'find' )->willReturn( null );

		$this->expectException( \InvalidArgumentException::class );
		$this->service->grade( 999, new GradeDTO( 50, 100, null ), 1 );
	}

	/**
	 * Tasks.md, п. 6: ручной зачёт задания должен доехать и до снимка вердиктов
	 * агрегата — его читает экран результатов УЧЕНИКА. Без синхронизации балл
	 * менялся в журнале, а ученик по-прежнему видел «Неверно».
	 */
	public function test_gradeBatchTask_syncs_aggregate_snapshot(): void {
		$perTaskRow = $this->makePerTaskSubmission( 21, 7 );
		$aggregate  = $this->makeAggregateWithVerdicts( 1, [ 7 => [ 'verdict' => 'incorrect', 'score' => 0.0, 'maxScore' => 1.0 ] ] );

		$this->submissions->method( 'find' )->willReturn( $perTaskRow );
		$this->submissions->method( 'findAggregate' )->willReturn( $aggregate );
		$this->submissions->method( 'listPerTaskByStudentWorkLesson' )->willReturn( [ $perTaskRow ] );

		$updates = [];
		$this->submissions->method( 'update' )->willReturnCallback(
			function ( int $id, array $data ) use ( &$updates ): bool {
				$updates[] = [ $id, $data ];

				return true;
			}
		);

		$this->service->gradeBatchTask( 21, 1.0, '', 99 );

		$snapshot = null;
		foreach ( $updates as [ $id, $data ] ) {
			if ( 1 === $id && isset( $data['answer_text'] ) ) {
				$snapshot = json_decode( (string) $data['answer_text'], true );
			}
		}

		self::assertNotNull( $snapshot, 'Снимок вердиктов агрегата не переписан' );
		self::assertSame( 'correct', $snapshot[7]['verdict'] );
		self::assertEquals( 1.0, $snapshot[7]['score'] );
	}

	public function test_gradeBatchTask_marks_snapshot_incorrect_on_partial_score(): void {
		$perTaskRow = $this->makePerTaskSubmission( 21, 7, 2.0 );
		$aggregate  = $this->makeAggregateWithVerdicts( 1, [ 7 => [ 'verdict' => 'incorrect', 'score' => 0.0, 'maxScore' => 2.0 ] ] );

		$this->submissions->method( 'find' )->willReturn( $perTaskRow );
		$this->submissions->method( 'findAggregate' )->willReturn( $aggregate );
		$this->submissions->method( 'listPerTaskByStudentWorkLesson' )->willReturn( [ $perTaskRow ] );

		$snapshot = null;
		$this->submissions->method( 'update' )->willReturnCallback(
			function ( int $id, array $data ) use ( &$snapshot ): bool {
				if ( 1 === $id && isset( $data['answer_text'] ) ) {
					$snapshot = json_decode( (string) $data['answer_text'], true );
				}

				return true;
			}
		);

		$this->service->gradeBatchTask( 21, 1.0, '', 99 );

		self::assertSame( 'incorrect', $snapshot[7]['verdict'] );
	}

	public function test_gradeBatchTask_throws_on_aggregate_row(): void {
		$this->submissions->method( 'find' )->willReturn( $this->makeSubmission( 1, SubmissionStatus::Submitted ) );

		$this->expectException( \InvalidArgumentException::class );
		$this->service->gradeBatchTask( 1, 1.0, '', 99 );
	}

	private function makePerTaskSubmission( int $id, int $taskId, float $maxScore = 1.0 ): SubmissionDTO {
		return new SubmissionDTO(
			id               : $id,
			studentPersonId  : 10,
			groupLessonId    : 5,
			workId           : 3,
			workType         : WorkType::Practice,
			taskId           : $taskId,
			answerText       : 'ответ ученика',
			attachmentId     : null,
			dueAt            : null,
			status           : SubmissionStatus::Graded,
			score            : 0.0,
			maxScore         : $maxScore,
			feedback         : null,
			gradedByUserId   : null,
			submittedAt      : '2024-01-01 10:00:00',
			gradedAt         : '2024-01-01 10:00:00',
			createdAt        : '2024-01-01 00:00:00',
			updatedAt        : '2024-01-01 00:00:00',
		);
	}

	/** @param array<int, array<string, mixed>> $verdicts Снимок батч-проверки */
	private function makeAggregateWithVerdicts( int $id, array $verdicts ): SubmissionDTO {
		return new SubmissionDTO(
			id               : $id,
			studentPersonId  : 10,
			groupLessonId    : 5,
			workId           : 3,
			workType         : WorkType::Practice,
			taskId           : null,
			answerText       : json_encode( $verdicts ),
			attachmentId     : null,
			dueAt            : null,
			status           : SubmissionStatus::Submitted,
			score            : 0.0,
			maxScore         : 1.0,
			feedback         : null,
			gradedByUserId   : null,
			submittedAt      : '2024-01-01 10:00:00',
			gradedAt         : null,
			createdAt        : '2024-01-01 00:00:00',
			updatedAt        : '2024-01-01 00:00:00',
		);
	}

	public function test_returnForRework_sets_returned_status_and_dispatches(): void {
		$sub = $this->makeSubmission( 7, SubmissionStatus::Submitted );
		$this->submissions->method( 'find' )->willReturn( $sub );
		$this->groupLessons->method( 'find' )->willReturn( $this->makeRow() );
		$this->submissions->expects( $this->once() )->method( 'update' )
			->with( 7, $this->callback( fn( $d ) => $d['status'] === 'returned' ) );
		$this->dispatcher->expects( $this->once() )->method( 'dispatch' )
			->with( LogEvent::SubmissionReturned );

		$this->service->returnForRework( 7, 'Needs revision', 99 );
	}

	// ===== Пересдача (.docs/Tasks.md, п. 1) и лимит сдач работы =====

	/** Общая обвязка пересдачи: доступ, работа с лимитом, агрегат прошлой сдачи. */
	private function arrangeResubmit( SubmissionDTO $aggregate, int $maxAttempts = 0, array $perTaskRows = array() ): void {
		$this->policy->method( 'canSubmit' )->willReturn( true );
		$this->groupLessons->method( 'find' )->willReturn( $this->makeRow() );
		$this->resolver->method( 'resolve' )->willReturn( [ $this->makeWork( 3 ) ] );
		$this->workManager->method( 'get' )->willReturn( new WorkDTO(
			id          : 3,
			subjectKey  : 'inf',
			title       : 'Work #3',
			workType    : WorkType::Practice,
			itemIds     : [ 1, 2 ],
			instructions: '',
			authorId    : 1,
			status      : 'publish',
			maxAttempts : $maxAttempts,
		) );
		$this->submissions->method( 'findAggregate' )->willReturn( $aggregate );
		$this->submissions->method( 'listPerTaskByStudentWorkLesson' )->willReturn( $perTaskRows );
		$this->submissions->method( 'findForWork' )->willReturn( null );
	}

	private function aggregateAfter( int $attempts, array $verdicts ): SubmissionDTO {
		$base = $this->makeAggregateWithVerdicts( 1, $verdicts );

		return SubmissionDTO::fromArray( array(
			'id'                => $base->id,
			'student_person_id' => 10,
			'group_lesson_id'   => 5,
			'work_id'           => 3,
			'work_type'         => 'practice',
			'task_id'           => null,
			'answer_text'       => $base->answerText,
			'status'            => 'graded',
			'created_at'        => '2024-01-01 00:00:00',
			'updated_at'        => '2024-01-01 00:00:00',
			'attempt_count'     => $attempts,
		) );
	}

	public function test_resubmit_rechecks_only_not_credited_tasks(): void {
		$this->arrangeResubmit( $this->aggregateAfter( 1, array(
			1 => array( 'verdict' => 'correct', 'score' => 1.0, 'maxScore' => 1.0 ),
			2 => array( 'verdict' => 'incorrect', 'score' => 0.0, 'maxScore' => 1.0 ),
		) ) );

		$this->batchChecker->expects( $this->once() )->method( 'check' )
			->with( array( 2 => 'исправлено' ) )
			->willReturn( new BatchCheckResultDTO(
				perTask         : [ 2 => [ 'verdict' => 'correct', 'score' => 1.0, 'maxScore' => 1.0 ] ],
				correctCount    : 1,
				totalCount      : 1,
				weightedScore   : 1.0,
				maxWeightedScore: 1.0,
				hasManual       : false,
			) );

		$aggregateUpdate = null;
		$this->submissions->method( 'update' )->willReturnCallback(
			function ( int $id, array $data ) use ( &$aggregateUpdate ): bool {
				if ( array_key_exists( 'attempt_count', $data ) ) {
					$aggregateUpdate = $data;
				}
				return true;
			}
		);

		// Ответ на засчитанное задание 1 из запроса игнорируется.
		$this->service->submitBatch( 10, 5, 3, array( 1 => 'подмена', 2 => 'исправлено' ) );

		self::assertSame( 2, $aggregateUpdate['attempt_count'] );
		self::assertSame( 2.0, $aggregateUpdate['score'] );
		self::assertSame( 2.0, $aggregateUpdate['max_score'] );
		self::assertSame( 'graded', $aggregateUpdate['status'] );
	}

	public function test_task_graded_by_teacher_is_locked(): void {
		$graded = SubmissionDTO::fromArray( array(
			'id'                => 20,
			'student_person_id' => 10,
			'group_lesson_id'   => 5,
			'work_id'           => 3,
			'work_type'         => 'practice',
			'task_id'           => 2,
			'status'            => 'graded',
			'score'             => 0.5,
			'max_score'         => 1.0,
			'graded_by_user_id' => 99,
			'created_at'        => '2024-01-01 00:00:00',
			'updated_at'        => '2024-01-01 00:00:00',
		) );
		$this->arrangeResubmit(
			$this->aggregateAfter( 1, array(
				1 => array( 'verdict' => 'correct', 'score' => 1.0, 'maxScore' => 1.0 ),
				2 => array( 'verdict' => 'incorrect', 'score' => 0.5, 'maxScore' => 1.0 ),
			) ),
			0,
			array( $graded )
		);

		self::assertSame( array( 1, 2 ), $this->service->lockedTaskIds( 10, 5, 3 ) );
	}

	public function test_resubmit_with_everything_credited_is_rejected(): void {
		$this->arrangeResubmit( $this->aggregateAfter( 1, array(
			1 => array( 'verdict' => 'correct', 'score' => 1.0, 'maxScore' => 1.0 ),
		) ) );
		$this->batchChecker->expects( $this->never() )->method( 'check' );

		try {
			$this->service->submitBatch( 10, 5, 3, array( 1 => 'a' ) );
			self::fail( 'Ожидался отказ' );
		} catch ( \Inc\Shared\CodedException $e ) {
			self::assertSame( \Inc\Enums\Log\ErrorCode::WorkNothing, $e->errorCode );
		}
	}

	public function test_work_attempt_limit_blocks_submission(): void {
		$this->arrangeResubmit(
			$this->aggregateAfter( 2, array( 1 => array( 'verdict' => 'incorrect', 'score' => 0.0, 'maxScore' => 1.0 ) ) ),
			2
		);
		$this->batchChecker->expects( $this->never() )->method( 'check' );

		try {
			$this->service->submitBatch( 10, 5, 3, array( 1 => 'a' ) );
			self::fail( 'Ожидался отказ' );
		} catch ( \Inc\Shared\CodedException $e ) {
			self::assertSame( \Inc\Enums\Log\ErrorCode::WorkLimit, $e->errorCode );
		}
	}

	public function test_zero_limit_means_unlimited(): void {
		$this->arrangeResubmit(
			$this->aggregateAfter( 50, array( 1 => array( 'verdict' => 'incorrect', 'score' => 0.0, 'maxScore' => 1.0 ) ) ),
			0
		);
		$this->batchChecker->method( 'check' )->willReturn( new BatchCheckResultDTO(
			perTask         : [ 1 => [ 'verdict' => 'incorrect', 'score' => 0.0, 'maxScore' => 1.0 ] ],
			correctCount    : 0,
			totalCount      : 1,
			weightedScore   : 0.0,
			maxWeightedScore: 1.0,
			hasManual       : false,
		) );

		self::assertInstanceOf( SubmissionDTO::class, $this->service->submitBatch( 10, 5, 3, array( 1 => 'a' ) ) );
	}
}
