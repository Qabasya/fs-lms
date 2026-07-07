<?php

declare( strict_types=1 );

namespace Unit\Callbacks\Course;

use Inc\Callbacks\Course\JournalCallbacks;
use Inc\DTO\Course\GroupLessonDTO;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Services\Course\AttendanceService;
use Inc\Services\Course\GroupAccessGuard;
use Inc\Services\Course\JournalService;
use PHPUnit\Framework\TestCase;

class JournalCallbacksTest extends TestCase {

	private AttendanceService&\PHPUnit\Framework\MockObject\MockObject $attendance;
	private JournalService&\PHPUnit\Framework\MockObject\MockObject $journal;
	private GroupLessonRepository&\PHPUnit\Framework\MockObject\MockObject $groupLessons;
	private GroupAccessGuard&\PHPUnit\Framework\MockObject\MockObject $guard;
	private GroupsRepository&\PHPUnit\Framework\MockObject\MockObject $groups;
	private JournalCallbacks $cb;

	protected function setUp(): void {
		parent::setUp();
		fs_test_reset_ajax();
		$this->attendance   = $this->createMock( AttendanceService::class );
		$this->journal      = $this->createMock( JournalService::class );
		$this->groupLessons = $this->createMock( GroupLessonRepository::class );
		$this->guard        = $this->createMock( GroupAccessGuard::class );
		$this->groups       = $this->createMock( GroupsRepository::class );
		$this->cb           = new JournalCallbacks( $this->attendance, $this->journal, $this->groupLessons, $this->guard, $this->groups );
	}

	public function test_get_journal_returns_payload_when_allowed(): void {
		$this->guard->method( 'canManage' )->willReturn( true );
		$this->journal->method( 'forGroup' )->with( 5 )->willReturn( array( 'students' => array(), 'lessons' => array() ) );
		$_POST = array( 'group_id' => '5' );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxGetGroupJournal() );

		self::assertTrue( $r->success );
		self::assertArrayHasKey( 'students', $r->payload );
	}

	public function test_get_journal_denied_for_foreign_group(): void {
		$this->guard->method( 'canManage' )->willReturn( false );
		$this->journal->expects( $this->never() )->method( 'forGroup' );
		$_POST = array( 'group_id' => '5' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxGetGroupJournal() )->success );
	}

	public function test_save_attendance_marks_when_allowed(): void {
		$this->groupLessons->method( 'find' )->with( 10 )->willReturn( $this->row( 10, 5 ) );
		$this->guard->method( 'canWriteJournal' )->with( 5, $this->anything() )->willReturn( true );
		$this->attendance->expects( $this->once() )->method( 'mark' )->with( 10, 900, true, $this->anything() );
		$_POST = array( 'group_lesson_id' => '10', 'student_person_id' => '900', 'is_present' => '1' );

		self::assertTrue( fs_test_capture_json( fn() => $this->cb->ajaxSaveAttendance() )->success );
	}

	public function test_save_attendance_denied_when_lesson_missing(): void {
		$this->groupLessons->method( 'find' )->willReturn( null );
		$this->attendance->expects( $this->never() )->method( 'mark' );
		$_POST = array( 'group_lesson_id' => '10', 'student_person_id' => '900', 'is_present' => '1' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxSaveAttendance() )->success );
	}

	public function test_save_attendance_blocked_for_future_lesson(): void {
		$future = new GroupLessonDTO(
			id: 10, groupId: 5, lessonId: 1, position: 0, workIdsSnapshot: null, extraWorkIds: array(),
			scheduledAt: '2999-01-01 09:00:00', endsAt: null, isPinned: false, teacherUserId: null, visibility: 'open',
			openedAt: null, homeworkDueAt: null, allowLate: true, recordingUrl: null,
			createdByUserId: null, updatedByUserId: null,
		);
		$this->groupLessons->method( 'find' )->willReturn( $future );
		$this->guard->method( 'canWriteJournal' )->willReturn( true );
		$this->attendance->expects( $this->never() )->method( 'mark' );
		$_POST = array( 'group_lesson_id' => '10', 'student_person_id' => '900', 'is_present' => '1' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxSaveAttendance() )->success );
	}

	public function test_save_attendance_blocked_for_open_group(): void {
		$openGroup              = new \stdClass();
		$openGroup->access_mode = 'open';
		$this->groups->method( 'findById' )->with( 5 )->willReturn( $openGroup );
		$this->groupLessons->method( 'find' )->willReturn( $this->row( 10, 5 ) );
		$this->guard->method( 'canWriteJournal' )->willReturn( true );
		$this->attendance->expects( $this->never() )->method( 'mark' );
		$_POST = array( 'group_lesson_id' => '10', 'student_person_id' => '900', 'is_present' => '1' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxSaveAttendance() )->success );
	}

	public function test_bulk_attendance_marks_all_when_allowed(): void {
		$this->groupLessons->method( 'find' )->with( 10 )->willReturn( $this->row( 10, 5 ) );
		$this->guard->method( 'canWriteJournal' )->willReturn( true );
		$this->attendance->expects( $this->once() )->method( 'markAll' )->with( 10, false, $this->anything() );
		$_POST = array( 'group_lesson_id' => '10', 'is_present' => '0' );

		self::assertTrue( fs_test_capture_json( fn() => $this->cb->ajaxBulkAttendance() )->success );
	}

	private function row( int $id, int $groupId ): GroupLessonDTO {
		return new GroupLessonDTO(
			id: $id, groupId: $groupId, lessonId: 1, position: 0, workIdsSnapshot: null, extraWorkIds: array(),
			scheduledAt: null, endsAt: null, isPinned: false, teacherUserId: null, visibility: 'open',
			openedAt: null, homeworkDueAt: null, allowLate: true, recordingUrl: null,
			createdByUserId: null, updatedByUserId: null,
		);
	}
}
