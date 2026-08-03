<?php

declare( strict_types=1 );

namespace Unit\Callbacks\Course;

use Inc\Callbacks\Course\ProgramCallbacks;
use Inc\Managers\Wp\PostManager;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\Log\LearningEventRepository;
use Inc\Services\Course\CourseAssignmentService;
use Inc\Services\Course\GroupAccessGuard;
use Inc\Services\Course\LessonVisibilityService;
use Inc\Services\Group\ProgramCompositionService;
use PHPUnit\Framework\TestCase;
use Tests\Support\ProgramRowFixtures;

/**
 * Состав КТП: назначение курса, темы, видимость, публикация, лента событий.
 */
class ProgramCallbacksTest extends TestCase {

	use ProgramRowFixtures;

	private ProgramCompositionService $program;
	private CourseAssignmentService   $assignment;
	private GroupAccessGuard          $guard;
	private LearningEventRepository   $events;
	private ProgramCallbacks          $cb;

	protected function setUp(): void {
		parent::setUp();
		fs_test_reset_ajax();
		$this->program      = $this->createMock( ProgramCompositionService::class );
		$this->assignment   = $this->createMock( CourseAssignmentService::class );
		$this->guard        = $this->createMock( GroupAccessGuard::class );
		$this->events       = $this->createMock( LearningEventRepository::class );

		$this->cb = new ProgramCallbacks(
			$this->program, $this->assignment, $this->guard, $this->events,
		);
	}

	public function test_assign_course_delegates_with_parsed_ids(): void {
		$this->guard->method( 'canManage' )->willReturn( true );
		$this->assignment->expects( $this->once() )
			->method( 'assign' )
			->with( 5, 7, $this->anything(), $this->anything() )
			->willReturn( 3 );

		$_POST = array( 'group_id' => '5', 'course_id' => '7', 'policy' => 'replace' );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxAssignCourse() );

		self::assertTrue( $r->success );
		self::assertSame( 3, $r->payload['added'] );
	}

	public function test_assign_course_denied_when_not_manager(): void {
		$this->guard->method( 'canManage' )->willReturn( false );
		$this->assignment->expects( $this->never() )->method( 'assign' );
		$_POST = array( 'group_id' => '5', 'course_id' => '7' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxAssignCourse() )->success );
	}

	public function test_assign_course_missing_param_errors(): void {
		$this->assignment->expects( $this->never() )->method( 'assign' );
		$_POST = array();

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxAssignCourse() )->success );
	}

	public function test_get_subject_courses_returns_courses(): void {
		$this->guard->method( 'canManage' )->willReturn( true );
		$this->assignment->expects( $this->once() )
			->method( 'coursesForGroup' )
			->with( 1 )
			->willReturn( array( array( 'id' => 7, 'title' => 'Python' ) ) );
		$_POST = array( 'group_id' => '1' );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxGetSubjectCourses() );

		self::assertTrue( $r->success );
		self::assertSame( 'Python', $r->payload['courses'][0]['title'] );
	}

	public function test_get_subject_courses_denied_when_not_manager(): void {
		$this->guard->method( 'canManage' )->willReturn( false );
		$this->assignment->expects( $this->never() )->method( 'coursesForGroup' );
		$_POST = array( 'group_id' => '1' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxGetSubjectCourses() )->success );
	}

	public function test_get_group_activity_returns_events_payload(): void {
		$this->guard->method( 'canManage' )->willReturn( true );
		$this->events->method( 'listByGroup' )->willReturn( array() );
		$this->events->method( 'countByGroup' )->willReturn( 0 );
		$_POST = array( 'group_id' => '5' );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxGetGroupActivity() );

		self::assertTrue( $r->success );
		self::assertSame( array(), $r->payload['events'] );
		self::assertSame( 0, $r->payload['total'] );
		self::assertSame( 1, $r->payload['page'] );
	}

	/* ── Lock КТП (T1.8) ─────────────────────────────────────────────────── */

	public function test_publish_program_delegates_and_returns_locked(): void {
		$this->guard->method( 'canManage' )->willReturn( true );
		$this->program->expects( $this->once() )->method( 'publishProgram' )->with( 5, $this->anything() );
		$this->program->method( 'programLockedAt' )->willReturn( '2026-07-02 10:00:00' );
		$_POST = array( 'group_id' => '5' );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxPublishProgram() );

		self::assertTrue( $r->success );
		self::assertTrue( $r->payload['locked'] );
	}

	public function test_unpublish_program_delegates(): void {
		$this->guard->method( 'canManage' )->willReturn( true );
		$this->program->expects( $this->once() )->method( 'unpublishProgram' )->with( 5, $this->anything() );
		$_POST = array( 'group_id' => '5' );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxUnpublishProgram() );

		self::assertTrue( $r->success );
		self::assertFalse( $r->payload['locked'] );
	}

	/* ── Продолжение темы (T12.6, D14) ───────────────────────────────────── */

	public function test_continue_program_lesson_delegates(): void {
		$this->program->method( 'getProgramRow' )->with( 42 )->willReturn( $this->programRow() );
		$this->guard->method( 'canManage' )->with( 5, $this->anything() )->willReturn( true );
		$this->program->expects( $this->once() )->method( 'continueLesson' )->with( 42, $this->anything() )->willReturn( 43 );
		$_POST = array( 'group_lesson_id' => '42' );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxContinueProgramLesson() );

		self::assertTrue( $r->success );
		self::assertSame( 43, $r->payload['group_lesson_id'] );
	}

	public function test_continue_program_lesson_denied_when_not_manager(): void {
		$this->program->method( 'getProgramRow' )->willReturn( $this->programRow() );
		$this->guard->method( 'canManage' )->willReturn( false );
		$this->program->expects( $this->never() )->method( 'continueLesson' );
		$_POST = array( 'group_lesson_id' => '42' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxContinueProgramLesson() )->success );
	}

	public function test_continue_program_lesson_blocked_when_program_locked(): void {
		$this->program->method( 'getProgramRow' )->willReturn( $this->programRow() );
		$this->guard->method( 'canManage' )->willReturn( true );
		$this->program->method( 'isProgramLocked' )->willReturn( true );
		$this->program->expects( $this->never() )->method( 'continueLesson' );
		$_POST = array( 'group_lesson_id' => '42' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxContinueProgramLesson() )->success );
	}

	public function test_continue_program_lesson_errors_when_service_returns_zero(): void {
		$this->program->method( 'getProgramRow' )->willReturn( $this->programRow() );
		$this->guard->method( 'canManage' )->willReturn( true );
		$this->program->method( 'continueLesson' )->willReturn( 0 );
		$_POST = array( 'group_lesson_id' => '42' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxContinueProgramLesson() )->success );
	}
}
