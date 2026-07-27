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

	public function test_mark_absent_pushes_attendance_missed_to_guardians(): void {
		$this->groupLessons->method( 'find' )->with( 100 )->willReturn( $this->lesson() );
		$this->notifications->method( 'guardianUserIds' )->with( 10 )->willReturn( array( 88 ) );
		$this->notifications->method( 'studentSnapshotName' )->with( 10, 5 )->willReturn( 'Иванов Иван' );
		$this->notifications->method( 'lessonTopic' )->willReturn( 'Тема' );

		$this->attendance->expects( self::once() )->method( 'upsert' )->with( 100, 10, false, 3 );
		$this->notifications->expects( self::once() )
			->method( 'push' )
			->with(
				array( 88 ),
				NotificationType::AttendanceMissed,
				'att:100:10',
				self::callback( static fn( $p ) => 'Иванов Иван' === $p['student_name'] && 'Тема' === $p['topic'] && '2026-05-01' === $p['date'] ),
				self::anything(),
				5,
				'group_lesson',
				100
			);
		$this->notifications->expects( self::never() )->method( 'retract' );

		$this->service->mark( 100, 10, false, 3 );
	}

	public function test_mark_present_retracts_previous_notification(): void {
		$this->groupLessons->method( 'find' )->willReturn( $this->lesson() );
		$this->notifications->method( 'guardianUserIds' )->willReturn( array( 88 ) );

		$this->notifications->expects( self::once() )->method( 'retract' )->with( array( 88 ), 'att:100:10' );
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

	public function test_mark_all_notifies_each_active_student_of_group(): void {
		$this->groupLessons->method( 'find' )->with( 100 )->willReturn( $this->lesson() );
		$this->records->method( 'findActiveByGroupId' )->with( 5 )->willReturn( array(
			$this->record( 10, 'Иванов', 'Иван' ),
			$this->record( 11, 'Петров', 'Пётр' ),
		) );
		$this->notifications->method( 'guardianUserIds' )->willReturnMap( array(
			array( 10, array( 88 ) ),
			array( 11, array() ), // у второго ученика нет привязанного родителя
		) );

		$this->attendance->expects( self::exactly( 2 ) )->method( 'upsert' );
		$this->notifications->expects( self::once() )
			->method( 'push' )
			->with(
				array( 88 ), NotificationType::AttendanceMissed, 'att:100:10',
				self::callback( static fn( $p ) => 'Иванов Иван' === $p['student_name'] ),
				self::anything(), 5, 'group_lesson', 100
			);

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
}
