<?php

declare( strict_types=1 );

namespace Unit\Callbacks\Course;

use Inc\Callbacks\Course\GroupRosterCallbacks;
use Inc\Services\Course\GroupAccessGuard;
use Inc\Services\Course\StudentSummaryService;
use Inc\Services\Group\GroupRosterService;
use Inc\Services\Group\StudentDirectoryService;
use PHPUnit\Framework\TestCase;

/**
 * Ростер группы и сводка по ученику.
 */
class GroupRosterCallbacksTest extends TestCase {

	private GroupRosterService      $roster;
	private StudentSummaryService   $summary;
	private GroupAccessGuard        $guard;
	private StudentDirectoryService $directory;
	private GroupRosterCallbacks    $cb;

	protected function setUp(): void {
		parent::setUp();
		fs_test_reset_ajax();
		$this->roster    = $this->createMock( GroupRosterService::class );
		$this->summary   = $this->createMock( StudentSummaryService::class );
		$this->guard     = $this->createMock( GroupAccessGuard::class );
		$this->directory = $this->createMock( StudentDirectoryService::class );

		$this->cb = new GroupRosterCallbacks( $this->roster, $this->summary, $this->guard, $this->directory );
	}

	public function test_get_group_roster_returns_students(): void {
		$this->guard->method( 'canManage' )->willReturn( true );
		$this->roster->expects( $this->once() )
			->method( 'forGroup' )
			->with( 1 )
			->willReturn( array( 'students' => array( array( 'person_id' => 9001, 'name' => 'Антонов Артём', 'individual' => array() ) ) ) );
		$_POST = array( 'group_id' => '1' );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxGetGroupRoster() );

		self::assertTrue( $r->success );
		self::assertCount( 1, $r->payload['students'] );
		self::assertSame( 9001, $r->payload['students'][0]['person_id'] );
	}

	public function test_get_group_roster_denied_when_not_manager(): void {
		$this->guard->method( 'canManage' )->willReturn( false );
		$this->roster->expects( $this->never() )->method( 'forGroup' );
		$_POST = array( 'group_id' => '1' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxGetGroupRoster() )->success );
	}

	public function test_get_student_summary_returns_lessons(): void {
		$this->guard->method( 'canManage' )->willReturn( true );
		$this->summary->expects( $this->once() )
			->method( 'forStudent' )
			->with( 1, 9001 )
			->willReturn( array( 'lessons' => array( array( 'group_lesson_id' => 5, 'date' => '2026-05-12', 'kind' => 'group' ) ) ) );
		$_POST = array( 'group_id' => '1', 'student_person_id' => '9001' );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxGetStudentSummary() );

		self::assertTrue( $r->success );
		self::assertCount( 1, $r->payload['lessons'] );
	}

	public function test_get_student_summary_denied_when_not_manager(): void {
		$this->guard->method( 'canManage' )->willReturn( false );
		$this->summary->expects( $this->never() )->method( 'forStudent' );
		$_POST = array( 'group_id' => '1', 'student_person_id' => '9001' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxGetStudentSummary() )->success );
	}

	public function test_get_teacher_students_returns_directory(): void {
		$this->directory->expects( $this->once() )
			->method( 'forTeacher' )
			->willReturn( array( array( 'person_id' => 9001, 'name' => 'Антонов Артём', 'groups' => array( 1 ) ) ) );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxGetTeacherStudents() );

		self::assertTrue( $r->success );
		self::assertCount( 1, $r->payload );
		self::assertSame( 9001, $r->payload[0]['person_id'] );
	}

	public function test_get_student_courses_returns_courses(): void {
		$this->directory->expects( $this->once() )
			->method( 'coursesForStudent' )
			->with( 9001, self::isType( 'int' ), self::isType( 'bool' ) )
			->willReturn( array( array( 'group_id' => 1, 'name' => 'Группа А', 'subject' => 'Математика' ) ) );
		$_POST = array( 'student_person_id' => '9001' );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxGetStudentCourses() );

		self::assertTrue( $r->success );
		self::assertCount( 1, $r->payload );
		self::assertSame( 1, $r->payload[0]['group_id'] );
	}
}
