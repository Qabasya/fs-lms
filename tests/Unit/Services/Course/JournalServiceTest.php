<?php

declare( strict_types=1 );

namespace Unit\Services\Course;

use Inc\DTO\Course\GroupLessonDTO;
use Inc\DTO\Enrollment\StudentRecordDTO;
use Inc\Managers\Course\LessonManager;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\RoomRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Services\Course\AttendanceService;
use Inc\Services\Course\GradebookService;
use Inc\Services\Course\JournalService;
use Inc\Services\Course\LessonProgressService;
use PHPUnit\Framework\TestCase;

class JournalServiceTest extends TestCase {

	private StudentRecordRepository&\PHPUnit\Framework\MockObject\MockObject $records;
	private GroupLessonRepository&\PHPUnit\Framework\MockObject\MockObject $groupLessons;
	private LessonManager&\PHPUnit\Framework\MockObject\MockObject $lessons;
	private AttendanceService&\PHPUnit\Framework\MockObject\MockObject $attendance;
	private GradebookService&\PHPUnit\Framework\MockObject\MockObject $gradebook;
	private RoomRepository&\PHPUnit\Framework\MockObject\MockObject $rooms;
	private GroupsRepository&\PHPUnit\Framework\MockObject\MockObject $groups;
	private LessonProgressService&\PHPUnit\Framework\MockObject\MockObject $progress;
	private JournalService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->records      = $this->createMock( StudentRecordRepository::class );
		$this->groupLessons = $this->createMock( GroupLessonRepository::class );
		$this->lessons      = $this->createMock( LessonManager::class );
		$this->attendance   = $this->createMock( AttendanceService::class );
		$this->gradebook    = $this->createMock( GradebookService::class );
		$this->rooms        = $this->createMock( RoomRepository::class );
		$this->groups       = $this->createMock( GroupsRepository::class );
		$this->progress     = $this->createMock( LessonProgressService::class );

		$this->rooms->method( 'findAll' )->willReturn( array() );
		$this->gradebook->method( 'forGroup' )->willReturn( array() );

		$this->service = new JournalService(
			$this->records,
			$this->groupLessons,
			$this->lessons,
			$this->attendance,
			$this->gradebook,
			$this->rooms,
			$this->groups,
			$this->progress,
		);
	}

	public function test_scheduled_group_uses_attendance_service_matrix(): void {
		$this->groups->method( 'findById' )->willReturn( (object) array( 'access_mode' => 'scheduled' ) );
		$this->records->method( 'findActiveByGroupId' )->willReturn( array( $this->studentRecord( 1 ) ) );
		$this->groupLessons->method( 'listByGroup' )->willReturn( array( $this->glRow( 10, 20 ) ) );

		$this->attendance->expects( self::once() )
			->method( 'matrixForGroup' )
			->with( 5 )
			->willReturn( array( 10 => array( 1 => true ) ) );
		$this->progress->expects( self::never() )->method( 'isLessonCompleted' );

		$result = $this->service->forGroup( 5 );

		self::assertSame( array( 10 => array( 1 => true ) ), $result['attendance'] );
	}

	public function test_open_group_synthesizes_attendance_from_step_completion(): void {
		$this->groups->method( 'findById' )->willReturn( (object) array( 'access_mode' => 'open' ) );
		$this->records->method( 'findActiveByGroupId' )->willReturn( array(
			$this->studentRecord( 1 ),
			$this->studentRecord( 2 ),
		) );
		$this->groupLessons->method( 'listByGroup' )->willReturn( array( $this->glRow( 10, 20 ) ) );

		$this->attendance->expects( self::never() )->method( 'matrixForGroup' );
		$this->progress->method( 'isLessonCompleted' )
			->willReturnMap( array(
				array( 1, 10, true ),
				array( 2, 10, false ),
			) );

		$result = $this->service->forGroup( 5 );

		// Ученик 1 прошёл все шаги — «+»; ученик 2 — ячейка отсутствует (не «false»).
		self::assertSame( array( 10 => array( 1 => true ) ), $result['attendance'] );
		self::assertTrue( $result['open'] );
	}

	public function test_open_group_with_no_completed_lessons_returns_empty_matrix(): void {
		$this->groups->method( 'findById' )->willReturn( (object) array( 'access_mode' => 'open' ) );
		$this->records->method( 'findActiveByGroupId' )->willReturn( array( $this->studentRecord( 1 ) ) );
		$this->groupLessons->method( 'listByGroup' )->willReturn( array( $this->glRow( 10, 20 ) ) );
		$this->progress->method( 'isLessonCompleted' )->willReturn( false );

		$result = $this->service->forGroup( 5 );

		self::assertSame( array(), $result['attendance'] );
	}

	private function glRow( int $id, int $lessonId ): GroupLessonDTO {
		return new GroupLessonDTO(
			id: $id, groupId: 5, lessonId: $lessonId, position: 0, workIdsSnapshot: null, extraWorkIds: array(),
			scheduledAt: null, endsAt: null, isPinned: false, teacherUserId: null, visibility: 'open',
			openedAt: '2026-01-01 00:00:00', homeworkDueAt: null, allowLate: true, recordingUrl: null,
			createdByUserId: null, updatedByUserId: null,
		);
	}

	private function studentRecord( int $personId ): StudentRecordDTO {
		return StudentRecordDTO::fromArray( array(
			'id'                 => $personId,
			'student_person_id'  => $personId,
			'parent_person_id'   => 0,
			'group_id'           => 5,
			'snapshot_last_name' => 'Тест',
			'snapshot_first_name' => 'Ученик',
			'status'             => 'active',
			'enrolled_at'        => '2026-01-01 00:00:00',
			'created_at'         => '2026-01-01 00:00:00',
			'updated_at'         => '2026-01-01 00:00:00',
		) );
	}
}
