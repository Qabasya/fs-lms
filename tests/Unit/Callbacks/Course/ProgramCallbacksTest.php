<?php

declare( strict_types=1 );

namespace Unit\Callbacks\Course;

use Inc\Callbacks\Course\ProgramCallbacks;
use Inc\Managers\Wp\PostManager;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\Log\LearningEventRepository;
use Inc\Services\Course\CourseAssignmentService;
use Inc\Services\Course\EffectiveWorksResolver;
use Inc\Services\Course\GroupAccessGuard;
use Inc\Services\Course\LessonVisibilityService;
use Inc\Services\Group\ScheduleService;
use PHPUnit\Framework\TestCase;

class ProgramCallbacksTest extends TestCase {

	private ScheduleService         $schedule;
	private LessonVisibilityService $visibility;
	private CourseAssignmentService $assignment;
	private EffectiveWorksResolver  $works;
	private GroupAccessGuard        $guard;
	private LearningEventRepository $events;
	private GroupLessonRepository   $groupLessons;
	private PostManager             $posts;
	private ProgramCallbacks        $cb;

	protected function setUp(): void {
		parent::setUp();
		fs_test_reset_ajax();
		$this->schedule     = $this->createMock( ScheduleService::class );
		$this->visibility   = $this->createMock( LessonVisibilityService::class );
		$this->assignment   = $this->createMock( CourseAssignmentService::class );
		$this->works        = $this->createMock( EffectiveWorksResolver::class );
		$this->guard        = $this->createMock( GroupAccessGuard::class );
		$this->events       = $this->createMock( LearningEventRepository::class );
		$this->groupLessons = $this->createMock( GroupLessonRepository::class );
		$this->posts        = $this->createMock( PostManager::class );
		$this->cb           = new ProgramCallbacks(
			$this->schedule, $this->visibility, $this->assignment, $this->works,
			$this->guard, $this->events, $this->groupLessons, $this->posts,
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

	public function test_set_lesson_visibility_valid(): void {
		$this->visibility->expects( $this->once() )->method( 'setVisibility' )->with( 5, 'open', $this->anything() );
		$_POST = array( 'group_lesson_id' => '5', 'visibility' => 'open' );

		self::assertTrue( fs_test_capture_json( fn() => $this->cb->ajaxSetLessonVisibility() )->success );
	}

	public function test_set_lesson_visibility_invalid_value_errors(): void {
		$this->visibility->expects( $this->never() )->method( 'setVisibility' );
		$_POST = array( 'group_lesson_id' => '5', 'visibility' => 'bogus' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxSetLessonVisibility() )->success );
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
}
