<?php

declare( strict_types=1 );

namespace Unit\Services\Course;

use Inc\DTO\Course\GroupLessonDTO;
use Inc\DTO\Enrollment\StudentRecordDTO;
use Inc\Enums\Profile\NotificationType;
use Inc\Repositories\WPDBRepositories\AttendanceRepository;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Services\Course\AttendanceService;
use Inc\Services\Profile\NotificationService;
use PHPUnit\Framework\TestCase;

class AttendanceServiceTest extends TestCase {

	private AttendanceRepository&\PHPUnit\Framework\MockObject\MockObject    $attendance;
	private GroupLessonRepository&\PHPUnit\Framework\MockObject\MockObject   $groupLessons;
	private StudentRecordRepository&\PHPUnit\Framework\MockObject\MockObject $records;
	private NotificationService&\PHPUnit\Framework\MockObject\MockObject     $notifications;
	private AttendanceService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->attendance    = $this->createMock( AttendanceRepository::class );
		$this->groupLessons  = $this->createMock( GroupLessonRepository::class );
		$this->records       = $this->createMock( StudentRecordRepository::class );
		$this->notifications = $this->createMock( NotificationService::class );

		$this->service = new AttendanceService(
			$this->attendance,
			$this->groupLessons,
			$this->records,
			$this->notifications,
		);
	}

	private function lesson( array $overrides = array() ): GroupLessonDTO {
		$base = array(
			'id' => 100, 'group_id' => 5, 'lesson_id' => null, 'position' => 0,
			'kind' => 'group', 'status' => 'scheduled', 'visibility' => 'open',
			'scheduled_at' => '2026-05-01 10:00:00',
		);
		return GroupLessonDTO::fromArray( array_merge( $base, $overrides ) );
	}

	/** Одна Н сразу не уведомляет — «пропущено занятие» решает крон при следующем занятии. */
	public function test_mark_absent_does_not_notify_immediately(): void {
		$this->groupLessons->method( 'find' )->with( 100 )->willReturn( $this->lesson() );
		$this->groupLessons->method( 'listByGroup' )->willReturn( array( $this->lesson() ) );
		$this->attendance->method( 'listByStudent' )->willReturn( array( $this->mark( 100, false ) ) );
		$this->notifications->method( 'guardianUserIds' )->willReturn( array( 88 ) );

		$this->attendance->expects( self::once() )->method( 'upsert' )->with( 100, 10, false, 3 );
		$this->notifications->expects( self::never() )->method( 'push' );

		$this->service->mark( 100, 10, false, 3 );
	}

	/** Третья Н подряд — родителю «Ученик не посещает занятия!», ключ — начало серии. */
	public function test_third_absence_in_a_row_notifies_guardian(): void {
		$this->groupLessons->method( 'find' )->willReturn( $this->lesson( array( 'id' => 103 ) ) );
		$this->groupLessons->method( 'listByGroup' )->willReturn( array(
			$this->lesson( array( 'id' => 100, 'scheduled_at' => '2026-05-01 10:00:00' ) ),
			$this->lesson( array( 'id' => 101, 'scheduled_at' => '2026-05-08 10:00:00' ) ),
			$this->lesson( array( 'id' => 102, 'scheduled_at' => '2026-05-15 10:00:00' ) ),
			$this->lesson( array( 'id' => 103, 'scheduled_at' => '2026-05-22 10:00:00' ) ),
		) );
		$this->attendance->method( 'listByStudent' )->willReturn( array(
			$this->mark( 100, true ),
			$this->mark( 101, false ),
			$this->mark( 102, false ),
			$this->mark( 103, false ),
		) );
		$this->notifications->method( 'guardianUserIds' )->willReturn( array( 88 ) );
		$this->notifications->method( 'studentSnapshotName' )->willReturn( 'Иванов Иван' );

		$this->notifications->expects( self::once() )
			->method( 'push' )
			->with(
				array( 88 ),
				NotificationType::AbsenceStreak,
				'absent_streak:10:101',
				self::callback( static fn( $p ) => 3 === $p['count'] && 'Иванов Иван' === $p['student_name'] ),
				self::anything(),
				5
			);

		$this->service->mark( 103, 10, false, 3 );
	}

	public function test_two_absences_in_a_row_are_not_a_streak(): void {
		$this->groupLessons->method( 'find' )->willReturn( $this->lesson( array( 'id' => 102 ) ) );
		$this->groupLessons->method( 'listByGroup' )->willReturn( array(
			$this->lesson( array( 'id' => 101, 'scheduled_at' => '2026-05-08 10:00:00' ) ),
			$this->lesson( array( 'id' => 102, 'scheduled_at' => '2026-05-15 10:00:00' ) ),
		) );
		$this->attendance->method( 'listByStudent' )->willReturn( array( $this->mark( 101, false ), $this->mark( 102, false ) ) );

		$this->notifications->expects( self::never() )->method( 'push' );

		$this->service->mark( 102, 10, false, 3 );
	}

	private function mark( int $groupLessonId, bool $present ): \Inc\DTO\Course\AttendanceDTO {
		return \Inc\DTO\Course\AttendanceDTO::fromArray( array(
			'id' => $groupLessonId, 'group_lesson_id' => $groupLessonId, 'student_person_id' => 10,
			'is_present' => $present ? 1 : 0, 'marked_by' => 3, 'marked_at' => '2026-05-01 10:00:00',
		) );
	}

	public function test_mark_present_retracts_previous_notification(): void {
		$this->groupLessons->method( 'find' )->willReturn( $this->lesson() );
		$this->notifications->method( 'guardianUserIds' )->willReturn( array( 88 ) );

		$this->notifications->method( 'studentUserId' )->willReturn( 77 );

		// Отзыв и у родителя, и у самого ученика — «пропущено» уходит обоим.
		$this->notifications->expects( self::once() )->method( 'retract' )->with( array( 88, 77 ), 'att:100:10' );
		$this->notifications->expects( self::never() )->method( 'push' );

		$this->service->mark( 100, 10, true, 3 );
	}

	public function test_mark_skips_notification_when_student_has_no_guardian(): void {
		$this->groupLessons->method( 'find' )->willReturn( $this->lesson() );
		$this->notifications->method( 'guardianUserIds' )->willReturn( array() );

		$this->notifications->expects( self::never() )->method( 'push' );
		$this->notifications->expects( self::never() )->method( 'retract' );

		$this->service->mark( 100, 10, false, 3 );
	}

	public function test_mark_still_upserts_when_lesson_missing_but_skips_notification(): void {
		$this->groupLessons->method( 'find' )->willReturn( null );

		$this->attendance->expects( self::once() )->method( 'upsert' )->with( 100, 10, false, 3 );
		$this->notifications->expects( self::never() )->method( 'push' );

		$this->service->mark( 100, 10, false, 3 );
	}

	public function test_mark_all_marks_each_active_student_of_group(): void {
		$this->groupLessons->method( 'find' )->with( 100 )->willReturn( $this->lesson() );
		$this->records->method( 'findActiveByGroupId' )->with( 5 )->willReturn( array(
			$this->record( 10, 'Иванов', 'Иван' ),
			$this->record( 11, 'Петров', 'Пётр' ),
		) );

		$this->attendance->expects( self::exactly( 2 ) )->method( 'upsert' );

		$this->service->markAll( 100, false, 3 );
	}

	public function test_mark_all_noop_when_lesson_missing(): void {
		$this->groupLessons->method( 'find' )->willReturn( null );

		$this->attendance->expects( self::never() )->method( 'upsert' );
		$this->records->expects( self::never() )->method( 'findActiveByGroupId' );

		$this->service->markAll( 100, false, 3 );
	}

	private function record( int $studentId, string $lastName, string $firstName ): StudentRecordDTO {
		return StudentRecordDTO::fromArray( array(
			'id' => $studentId, 'student_person_id' => $studentId, 'parent_person_id' => 900 + $studentId,
			'group_id' => 5, 'snapshot_last_name' => $lastName, 'snapshot_first_name' => $firstName,
			'status' => 'active', 'enrolled_at' => '2026-01-01 00:00:00',
			'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
		) );
	}

	/** Del в журнале: отметка снимается, «пропущено занятие» по ней отзывается. */
	public function test_clear_deletes_mark_and_retracts_missed_notification(): void {
		$this->notifications->method( 'guardianUserIds' )->willReturn( array( 88 ) );
		$this->notifications->method( 'studentUserId' )->willReturn( 77 );

		$this->attendance->expects( self::once() )->method( 'delete' )->with( 100, 10 );
		$this->notifications->expects( self::once() )->method( 'retract' )->with( array( 88, 77 ), 'att:100:10' );

		$this->service->clear( 100, 10 );
	}
}
