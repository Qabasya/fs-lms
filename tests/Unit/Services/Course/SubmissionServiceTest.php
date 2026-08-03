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
use Inc\Enums\Log\LogEvent;
use Inc\Enums\Course\AttemptSource;
use Inc\Enums\Course\SubmissionStatus;
use Inc\Enums\Course\WorkType;
use Inc\Managers\Wp\MediaManager;
use Inc\Managers\Course\WorkManager;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
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
		$this->taskAttempts->method( 'countByStepTask' )->willReturn( 1 );
		$this->taskAttempts->expects( $this->once() )
			->method( 'create' )
			->with(
				10,
				5,
				AttemptSource::workStepKey( 3 ),
				1,
				2,               // предыдущая попытка была одна → эта вторая
				'a',
				true,
				1.0,
				1.0,
				array()
			);

		$this->service->submitBatch( 10, 5, 3, array( 1 => 'a' ) );
	}

	/** Общая обвязка успешного пути submitBatch: доступ, работа, пустой вердикт. */
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
}
