<?php

declare( strict_types=1 );

namespace Unit\Services\Course;

use Inc\Contracts\ClockInterface;
use Inc\DTO\Course\GroupLessonDTO;
use Inc\DTO\Course\SubmissionDTO;
use Inc\DTO\Course\WorkDTO;
use Inc\Enums\Course\SubmissionStatus;
use Inc\Enums\Course\WorkType;
use Inc\Managers\Course\LessonManager;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Repositories\WPDBRepositories\SubmissionRepository;
use Inc\Services\Course\HomeworkDeadlineService;
use Inc\Services\Course\PassedWorksService;
use Inc\Services\Course\SubmissionService;
use Inc\Services\Course\TeacherGroupResolver;
use PHPUnit\Framework\TestCase;

/** Вкладка «Прошедшие»: кто сдал работу занятия, а кому её можно засчитать. */
class PassedWorksServiceTest extends TestCase {

	private TeacherGroupResolver&\PHPUnit\Framework\MockObject\MockObject $teacherGroups;
	private GroupLessonRepository&\PHPUnit\Framework\MockObject\MockObject $groupLessons;
	private StudentRecordRepository&\PHPUnit\Framework\MockObject\MockObject $records;
	private SubmissionRepository&\PHPUnit\Framework\MockObject\MockObject $submissions;
	private SubmissionService&\PHPUnit\Framework\MockObject\MockObject $submissionService;
	private HomeworkDeadlineService&\PHPUnit\Framework\MockObject\MockObject $deadlines;
	private PassedWorksService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->teacherGroups     = $this->createMock( TeacherGroupResolver::class );
		$this->groupLessons      = $this->createMock( GroupLessonRepository::class );
		$this->records           = $this->createMock( StudentRecordRepository::class );
		$this->submissions       = $this->createMock( SubmissionRepository::class );
		$this->submissionService = $this->createMock( SubmissionService::class );
		$this->deadlines         = $this->createMock( HomeworkDeadlineService::class );
		$clock                   = $this->createMock( ClockInterface::class );
		$clock->method( 'now' )->willReturn( '2026-10-20 12:00:00' );

		$this->teacherGroups->method( 'idsFor' )->willReturn( array( 1 ) );
		$this->records->method( 'findActiveByGroupId' )->willReturn( array(
			(object) array( 'studentPersonId' => 10, 'snapshotLastName' => 'Иванов', 'snapshotFirstName' => 'Иван' ),
			(object) array( 'studentPersonId' => 11, 'snapshotLastName' => 'Петров', 'snapshotFirstName' => 'Пётр' ),
		) );

		$this->service = new PassedWorksService(
			$this->teacherGroups,
			$this->createStub( GroupsRepository::class ),
			$this->groupLessons,
			$this->records,
			$this->submissions,
			$this->submissionService,
			$this->deadlines,
			$this->createStub( LessonManager::class ),
			$clock
		);
	}

	private function row( string $scheduledAt = '2026-10-19 10:00:00' ): GroupLessonDTO {
		return new GroupLessonDTO(
			id: 5, groupId: 1, lessonId: 10, position: 0, workIdsSnapshot: null, extraWorkIds: array(),
			scheduledAt: $scheduledAt, endsAt: null, isPinned: false, teacherUserId: null, visibility: 'open',
			openedAt: null, homeworkDueAt: null, allowLate: true, recordingUrl: null, createdByUserId: null, updatedByUserId: null
		);
	}

	private function work(): WorkDTO {
		return new WorkDTO( 3, 'inf', 'Практика', WorkType::Practice, array( 101, 102 ), '', 1, 'publish' );
	}

	private function submission( int $personId ): SubmissionDTO {
		return new SubmissionDTO( 70, $personId, 5, 3, WorkType::Practice, null, '{}', null, null, SubmissionStatus::Graded, 2.0, 2.0, null, null, '2026-10-19 11:00:00', null, '', '' );
	}

	public function test_list_counts_submitted_and_missing_students_of_past_lessons_only(): void {
		$this->groupLessons->method( 'listByGroup' )->willReturn( array( $this->row(), $this->row( '2026-10-25 10:00:00' ) ) );
		$this->deadlines->method( 'assignedWorks' )->willReturn( array( $this->work() ) );
		$this->submissions->method( 'listAggregatesByGroupLesson' )->willReturn( array( $this->submission( 10 ) ) );

		$items = $this->service->list( 7, false );

		self::assertCount( 1, $items, 'будущее занятие не попадает' );
		self::assertSame( 2, $items[0]['total'] );
		self::assertSame( 1, $items[0]['submitted'] );
		self::assertSame( 1, $items[0]['missing'] );
	}

	public function test_credit_without_ids_credits_only_students_without_submission(): void {
		$this->groupLessons->method( 'find' )->willReturn( $this->row() );
		$this->submissions->method( 'listAggregatesByGroupLesson' )->willReturn( array( $this->submission( 10 ) ) );
		$this->submissionService->expects( $this->once() )->method( 'creditWork' )->with( 11, 5, 3, 7 )->willReturn( true );

		self::assertSame( 1, $this->service->credit( 5, 3, array(), 7, false ) );
	}

	public function test_credit_is_denied_for_group_outside_access(): void {
		$this->groupLessons->method( 'find' )->willReturn( new GroupLessonDTO(
			id: 5, groupId: 99, lessonId: 10, position: 0, workIdsSnapshot: null, extraWorkIds: array(),
			scheduledAt: '2026-10-19 10:00:00', endsAt: null, isPinned: false, teacherUserId: null, visibility: 'open',
			openedAt: null, homeworkDueAt: null, allowLate: true, recordingUrl: null, createdByUserId: null, updatedByUserId: null
		) );
		$this->submissionService->expects( $this->never() )->method( 'creditWork' );

		self::assertNull( $this->service->credit( 5, 3, array(), 7, false ) );
	}
}
