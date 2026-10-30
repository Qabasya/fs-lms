<?php

declare( strict_types=1 );

namespace Unit\Services\Course;

use Inc\DTO\Course\AttendanceDTO;
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
		$this->attendance->method( 'listByGroup' )->willReturn( array( $this->mark( 100, false ) ) );
		$this->notifications->method( 'guardianUserIds' )->willReturn( array( 88 ) );

		$this->attendance->expects( self::once() )->method( 'upsert' )->with( 100, 10, false, 3 );
		$this->notifications->expects( self::never() )->method( 'push' );

		$this->service->mark( 100, 10, false, 3 );
	}

	/** Третья Н подряд — родителю «Ученик не посещает занятия!» (администратор узнал раньше), ключ — начало серии. */
	public function test_third_absence_in_a_row_notifies_guardian_and_admins(): void {
		$this->groupLessons->method( 'find' )->willReturn( $this->lesson( array( 'id' => 103 ) ) );
		$this->groupLessons->method( 'listByGroup' )->willReturn( array(
			$this->lesson( array( 'id' => 100, 'scheduled_at' => '2026-05-01 10:00:00' ) ),
			$this->lesson( array( 'id' => 101, 'scheduled_at' => '2026-05-08 10:00:00' ) ),
			$this->lesson( array( 'id' => 102, 'scheduled_at' => '2026-05-15 10:00:00' ) ),
			$this->lesson( array( 'id' => 103, 'scheduled_at' => '2026-05-22 10:00:00' ) ),
		) );
		$this->attendance->method( 'listByGroup' )->willReturn( array(
			$this->mark( 100, true ),
			$this->mark( 101, false ),
			$this->mark( 102, false ),
			$this->mark( 103, false ),
		) );
		$this->notifications->method( 'guardianUserIds' )->willReturn( array( 88 ) );
		$this->notifications->method( 'adminUserIds' )->willReturn( array( 2 ) );
		$this->notifications->method( 'studentSnapshotName' )->willReturn( 'Иванов Иван' );

		$pushed = array();
		$this->notifications->method( 'push' )->willReturnCallback(
			function ( array $users, NotificationType $type, string $key, array $payload ) use ( &$pushed ): void {
				$pushed[] = array( $users, $type, $key, $payload['count'], $payload['student_name'] );
			}
		);

		$this->service->mark( 103, 10, false, 3 );

		self::assertSame(
			array(
				array( array( 2 ), NotificationType::AbsenceStreak, 'absent_streak_admin:10:101', 3, 'Иванов Иван' ),
				array( array( 88 ), NotificationType::AbsenceStreak, 'absent_streak:10:101', 3, 'Иванов Иван' ),
			),
			$pushed
		);
	}

	/** Две Н подряд — уже сигнал администратору, но родителю ещё нет. */
	public function test_two_absences_in_a_row_notify_only_admins(): void {
		$this->groupLessons->method( 'find' )->willReturn( $this->lesson( array( 'id' => 102 ) ) );
		$this->groupLessons->method( 'listByGroup' )->willReturn( array(
			$this->lesson( array( 'id' => 101, 'scheduled_at' => '2026-05-08 10:00:00' ) ),
			$this->lesson( array( 'id' => 102, 'scheduled_at' => '2026-05-15 10:00:00' ) ),
		) );
		$this->attendance->method( 'listByGroup' )->willReturn( array( $this->mark( 101, false ), $this->mark( 102, false ) ) );
		$this->notifications->method( 'adminUserIds' )->willReturn( array( 2 ) );

		$this->notifications->expects( self::once() )
			->method( 'push' )
			->with( array( 2 ), NotificationType::AbsenceStreak, 'absent_streak_admin:10:101' );

		$this->service->mark( 102, 10, false, 3 );
	}

	/** Общая (дополнительная) группа: отметка сохраняется, но серия пропусков не тревожит ни администратора, ни родителя. */
	public function test_absence_streak_in_shared_group_does_not_notify(): void {
		$this->groupLessons->method( 'find' )->willReturn( $this->lesson( array( 'id' => 103 ) ) );
		$this->groupLessons->method( 'listByGroup' )->willReturn( array(
			$this->lesson( array( 'id' => 101, 'scheduled_at' => '2026-05-08 10:00:00' ) ),
			$this->lesson( array( 'id' => 102, 'scheduled_at' => '2026-05-15 10:00:00' ) ),
			$this->lesson( array( 'id' => 103, 'scheduled_at' => '2026-05-22 10:00:00' ) ),
		) );
		$this->attendance->method( 'listByGroup' )->willReturn( array(
			$this->mark( 101, false ),
			$this->mark( 102, false ),
			$this->mark( 103, false ),
		) );
		$this->notifications->method( 'isSharedGroup' )->with( 5 )->willReturn( true );
		$this->notifications->method( 'adminUserIds' )->willReturn( array( 2 ) );
		$this->notifications->method( 'guardianUserIds' )->willReturn( array( 88 ) );

		$this->attendance->expects( self::once() )->method( 'upsert' )->with( 103, 10, false, 3 );
		$this->notifications->expects( self::never() )->method( 'push' );

		$this->service->mark( 103, 10, false, 3 );
	}

	/** Серии считаются по всей группе: у каждого ученика — от свежего занятия до первого «был». */
	public function test_absence_streaks_per_student(): void {
		$this->groupLessons->method( 'listByGroup' )->willReturn( array(
			$this->lesson( array( 'id' => 101, 'scheduled_at' => '2026-05-08 10:00:00' ) ),
			$this->lesson( array( 'id' => 102, 'scheduled_at' => '2026-05-15 10:00:00' ) ),
			$this->lesson( array( 'id' => 103, 'kind' => 'individual', 'scheduled_at' => '2026-05-16 10:00:00' ) ),
		) );
		$this->attendance->method( 'listByGroup' )->willReturn( array(
			$this->mark( 101, false ),
			$this->mark( 102, false ),
			$this->mark( 103, true ), // индивидуальное — не в счёт
			\Inc\DTO\Course\AttendanceDTO::fromArray( array( 'group_lesson_id' => 101, 'student_person_id' => 11, 'is_present' => 0 ) ),
			\Inc\DTO\Course\AttendanceDTO::fromArray( array( 'group_lesson_id' => 102, 'student_person_id' => 11, 'is_present' => 1 ) ),
		) );

		self::assertSame( array( 10 => array( 102, 101 ) ), $this->service->absenceStreaks( 5 ) );
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

	public function test_auto_mark_sets_present_for_each_student_of_ended_individual_lessons(): void {
		$first  = $this->lesson( array( 'id' => 100 ) );
		$second = $this->lesson( array( 'id' => 101 ) );
		$this->groupLessons->method( 'listEndedInIndividualGroupsWithoutAttendance' )->with( '2026-05-02 12:00:00' )->willReturn( array( $first, $second ) );
		$this->notifications->method( 'lessonStudentPersonIds' )->willReturn( array( 9005 ) );

		$this->attendance->expects( self::exactly( 2 ) )->method( 'insertPresentIfMissing' )
			->willReturnCallback( function ( int $lessonId, int $studentId, int $markedBy ): bool {
				self::assertContains( $lessonId, array( 100, 101 ) );
				self::assertSame( 9005, $studentId );
				self::assertSame( AttendanceService::SYSTEM_USER, $markedBy );
				return true;
			} );
		$this->attendance->expects( self::never() )->method( 'upsert' );

		self::assertSame( 2, $this->service->autoMarkIndividualGroups( '2026-05-02 12:00:00' ) );
	}

	public function test_auto_mark_does_not_count_or_overwrite_existing_marks(): void {
		$this->groupLessons->method( 'listEndedInIndividualGroupsWithoutAttendance' )->willReturn( array( $this->lesson() ) );
		$this->notifications->method( 'lessonStudentPersonIds' )->willReturn( array( 9005 ) );
		$this->attendance->method( 'insertPresentIfMissing' )->willReturn( false );

		self::assertSame( 0, $this->service->autoMarkIndividualGroups( '2026-05-02 12:00:00' ) );
	}

	// ── Дополнительное занятие для части группы: отметка ложится под строку самого ученика ──────────

	/** @return GroupLessonDTO[] строки доп. занятия: 59 — ученик 9008, 60 — ученик 9003 (ключ занятия — 60) */
	private function extraUnit(): array {
		$base = array( 'group_id' => 5, 'kind' => 'individual', 'extra_key' => 'k1', 'scheduled_at' => '2026-05-01 10:00:00' );
		return array(
			$this->lesson( $base + array( 'id' => 59, 'student_person_id' => 9008 ) ),
			$this->lesson( $base + array( 'id' => 60, 'student_person_id' => 9003 ) ),
		);
	}

	public function test_mark_on_extra_lesson_is_saved_under_the_students_own_row(): void {
		$unit = $this->extraUnit();
		$this->groupLessons->method( 'find' )->with( 60 )->willReturn( $unit[1] );
		$this->groupLessons->method( 'listExtraSiblings' )->willReturn( $unit );
		$this->groupLessons->method( 'listByGroup' )->willReturn( $unit );
		$this->attendance->method( 'listByGroup' )->willReturn( array() );

		$this->attendance->expects( self::once() )->method( 'upsert' )->with( 59, 9008, true, 3 );

		$this->service->mark( 60, 9008, true, 3 );
	}

	public function test_mark_of_student_outside_extra_lesson_is_rejected(): void {
		$unit = $this->extraUnit();
		$this->groupLessons->method( 'find' )->willReturn( $unit[1] );
		$this->groupLessons->method( 'listExtraSiblings' )->willReturn( $unit );
		$this->attendance->expects( self::never() )->method( 'upsert' );

		$this->expectException( \InvalidArgumentException::class );
		$this->service->mark( 60, 7777, true, 3 );
	}

	public function test_clear_on_extra_lesson_removes_the_mark_from_own_row(): void {
		$unit = $this->extraUnit();
		$this->groupLessons->method( 'find' )->willReturn( $unit[1] );
		$this->groupLessons->method( 'listExtraSiblings' )->willReturn( $unit );
		$this->attendance->expects( self::once() )->method( 'delete' )->with( 59, 9008 );

		$this->service->clear( 60, 9008 );
	}

	public function test_mark_all_on_extra_lesson_marks_only_its_students_on_their_rows(): void {
		$unit = $this->extraUnit();
		$this->groupLessons->method( 'find' )->willReturn( $unit[1] );
		$this->groupLessons->method( 'listExtraSiblings' )->willReturn( $unit );
		$this->groupLessons->method( 'listByGroup' )->willReturn( $unit );
		$this->attendance->method( 'listByGroup' )->willReturn( array() );
		$this->records->expects( self::never() )->method( 'findActiveByGroupId' );

		$calls = array();
		$this->attendance->method( 'upsert' )->willReturnCallback(
			function ( int $glid, int $pid, bool $present ) use ( &$calls ): void {
				$calls[] = array( $glid, $pid, $present );
			}
		);

		$this->service->markAll( 60, true, 3 );

		self::assertEqualsCanonicalizing( array( array( 59, 9008, true ), array( 60, 9003, true ) ), $calls );
	}

	public function test_matrix_moves_extra_marks_to_the_unit_key(): void {
		$unit = $this->extraUnit();
		$this->groupLessons->method( 'listByGroup' )->willReturn( array_merge( array( $this->lesson( array( 'id' => 100 ) ) ), $unit ) );
		$this->attendance->method( 'listByGroup' )->willReturn( array(
			$this->mark( 100, true ),
			AttendanceDTO::fromArray( array( 'id' => 8, 'group_lesson_id' => 59, 'student_person_id' => 9008, 'is_present' => 1, 'marked_by' => 3, 'marked_at' => '2026-05-01 11:00:00' ) ),
			AttendanceDTO::fromArray( array( 'id' => 9, 'group_lesson_id' => 60, 'student_person_id' => 9003, 'is_present' => 0, 'marked_by' => 3, 'marked_at' => '2026-05-01 11:00:00' ) ),
		) );

		self::assertSame(
			array( 100 => array( 10 => true ), 60 => array( 9008 => true, 9003 => false ) ),
			$this->service->matrixForGroup( 5 )
		);
	}
}
