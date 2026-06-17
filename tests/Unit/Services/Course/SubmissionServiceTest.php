<?php

declare( strict_types=1 );

namespace Unit\Services\Course;

use Inc\Contracts\LogEventDispatcherInterface;
use Inc\DTO\Course\GradeDTO;
use Inc\DTO\Course\GroupLessonDTO;
use Inc\DTO\Course\SubmissionDTO;
use Inc\DTO\Course\SubmissionInputDTO;
use Inc\DTO\Course\WorkDTO;
use Inc\Enums\LogEvent;
use Inc\Enums\SubmissionStatus;
use Inc\Enums\WorkType;
use Inc\Managers\MediaManager;
use Inc\Managers\LessonManager;
use Inc\Managers\WorkManager;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Repositories\WPDBRepositories\SubmissionRepository;
use Inc\Services\Course\EffectiveWorksResolver;
use Inc\Services\Course\LessonAccessPolicy;
use Inc\Services\Course\SubmissionService;
use PHPUnit\Framework\TestCase;

class SubmissionServiceTest extends TestCase {

	private SubmissionRepository&\PHPUnit\Framework\MockObject\MockObject $submissions;
	private GroupLessonRepository&\PHPUnit\Framework\MockObject\MockObject $groupLessons;
	private EffectiveWorksResolver&\PHPUnit\Framework\MockObject\MockObject $resolver;
	private WorkManager&\PHPUnit\Framework\MockObject\MockObject $workManager;
	private LessonManager&\PHPUnit\Framework\MockObject\MockObject $lessonManager;
	private MediaManager&\PHPUnit\Framework\MockObject\MockObject $mediaManager;
	private PersonRepository&\PHPUnit\Framework\MockObject\MockObject $personRepo;
	private LessonAccessPolicy&\PHPUnit\Framework\MockObject\MockObject $policy;
	private LogEventDispatcherInterface&\PHPUnit\Framework\MockObject\MockObject $dispatcher;
	private SubmissionService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->submissions  = $this->createMock( SubmissionRepository::class );
		$this->groupLessons = $this->createMock( GroupLessonRepository::class );
		$this->resolver     = $this->createMock( EffectiveWorksResolver::class );
		$this->workManager  = $this->createMock( WorkManager::class );
		$this->lessonManager= $this->createMock( LessonManager::class );
		$this->mediaManager = $this->createMock( MediaManager::class );
		$this->personRepo   = $this->createMock( PersonRepository::class );
		$this->policy       = $this->createMock( LessonAccessPolicy::class );
		$this->dispatcher   = $this->createMock( LogEventDispatcherInterface::class );

		$this->service = new SubmissionService(
			$this->submissions,
			$this->groupLessons,
			$this->resolver,
			$this->workManager,
			$this->lessonManager,
			$this->mediaManager,
			$this->personRepo,
			$this->policy,
			$this->dispatcher,
		);
	}

	private function makeRow( int $workId = 3, bool $allowLate = true, ?string $dueAt = null ): GroupLessonDTO {
		return new GroupLessonDTO(
			id              : 5,
			groupId         : 1,
			lessonId        : 10,
			position        : 0,
			workIdsSnapshot : null,
			extraWorkIds    : [],
			scheduledAt     : null,
			teacherUserId   : null,
			visibility      : 'open',
			openedAt        : '2024-01-01 00:00:00',
			homeworkDueAt   : $dueAt,
			allowLate       : $allowLate,
			recordingUrl    : null,
			createdByUserId : null,
			updatedByUserId : null,
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

	public function test_submit_creates_new_row_when_no_existing(): void {
		$this->policy->method( 'canSubmit' )->willReturn( true );
		$this->groupLessons->method( 'find' )->willReturn( $this->makeRow() );
		$this->resolver->method( 'resolve' )->willReturn( [ $this->makeWork( 3 ) ] );
		$this->workManager->method( 'get' )->willReturn( $this->makeWork( 3 ) );
		$this->submissions->method( 'findForWork' )->willReturn( null );
		$this->submissions->expects( $this->once() )->method( 'create' )->willReturn( 99 );
		$this->dispatcher->expects( $this->once() )->method( 'dispatch' )
			->with( LogEvent::SubmissionMade );

		$id = $this->service->submit( 10, 5, 3, null, 'answer' );
		$this->assertSame( 99, $id );
	}

	public function test_submit_updates_existing_returned_submission(): void {
		$existing = $this->makeSubmission( 7, SubmissionStatus::Returned );

		$this->policy->method( 'canSubmit' )->willReturn( true );
		$this->groupLessons->method( 'find' )->willReturn( $this->makeRow() );
		$this->resolver->method( 'resolve' )->willReturn( [ $this->makeWork( 3 ) ] );
		$this->workManager->method( 'get' )->willReturn( $this->makeWork( 3 ) );
		$this->submissions->method( 'findForWork' )->willReturn( $existing );
		$this->submissions->expects( $this->once() )->method( 'update' )
			->with( 7, $this->arrayHasKey( 'status' ) );
		$this->submissions->expects( $this->never() )->method( 'create' );

		$id = $this->service->submit( 10, 5, 3, null, 'new answer' );
		$this->assertSame( 7, $id );
	}

	public function test_submit_throws_when_canSubmit_false(): void {
		$this->policy->method( 'canSubmit' )->willReturn( false );

		$this->expectException( \InvalidArgumentException::class );
		$this->service->submit( 10, 5, 3, null, 'text' );
	}

	public function test_submit_throws_when_work_not_in_effective_set(): void {
		$this->policy->method( 'canSubmit' )->willReturn( true );
		$this->groupLessons->method( 'find' )->willReturn( $this->makeRow() );
		$this->resolver->method( 'resolve' )->willReturn( [ $this->makeWork( 3 ) ] );

		$this->expectException( \InvalidArgumentException::class );
		$this->service->submit( 10, 5, 999, null, 'text' ); // work 999 not in set
	}

	public function test_submit_throws_when_late_and_allow_late_false(): void {
		$dueAt = '2000-01-01 00:00:00'; // far in the past
		$this->policy->method( 'canSubmit' )->willReturn( true );
		$this->groupLessons->method( 'find' )->willReturn( $this->makeRow( 3, false, $dueAt ) );
		$this->resolver->method( 'resolve' )->willReturn( [ $this->makeWork( 3 ) ] );
		$this->workManager->method( 'get' )->willReturn( $this->makeWork( 3 ) );

		$this->expectException( \InvalidArgumentException::class );
		$this->service->submit( 10, 5, 3, null, 'text' );
	}

	public function test_submit_succeeds_when_late_and_allow_late_true(): void {
		$dueAt = '2000-01-01 00:00:00';
		$this->policy->method( 'canSubmit' )->willReturn( true );
		$this->groupLessons->method( 'find' )->willReturn( $this->makeRow( 3, true, $dueAt ) );
		$this->resolver->method( 'resolve' )->willReturn( [ $this->makeWork( 3 ) ] );
		$this->workManager->method( 'get' )->willReturn( $this->makeWork( 3 ) );
		$this->submissions->method( 'findForWork' )->willReturn( null );
		$this->submissions->method( 'create' )->willReturn( 1 );

		$this->expectNotToPerformAssertions();
		$this->service->submit( 10, 5, 3, null, 'text' );
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
