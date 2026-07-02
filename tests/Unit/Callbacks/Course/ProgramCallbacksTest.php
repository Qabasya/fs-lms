<?php

declare( strict_types=1 );

namespace Unit\Callbacks\Course;

use Inc\Callbacks\Course\ProgramCallbacks;
use Inc\DTO\Course\GroupLessonDTO;
use Inc\DTO\Course\WorkDTO;
use Inc\Enums\Course\WorkType;
use Inc\Managers\Wp\PostManager;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\Log\LearningEventRepository;
use Inc\Services\Course\CourseAssignmentService;
use Inc\Services\Course\EffectiveWorksResolver;
use Inc\Services\Course\GroupAccessGuard;
use Inc\Services\Course\LessonVisibilityService;
use Inc\Services\Course\StudentSummaryService;
use Inc\Services\Group\GroupRosterService;
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
	private GroupRosterService      $roster;
	private StudentSummaryService   $summary;
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
		$this->roster       = $this->createMock( GroupRosterService::class );
		$this->summary      = $this->createMock( StudentSummaryService::class );
		$this->cb           = new ProgramCallbacks(
			$this->schedule, $this->visibility, $this->assignment, $this->works,
			$this->guard, $this->events, $this->groupLessons, $this->posts, $this->roster, $this->summary,
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

	public function test_create_individual_lesson_delegates_and_returns_id(): void {
		$this->guard->method( 'canManage' )->willReturn( true );
		$this->schedule->expects( $this->once() )
			->method( 'createIndividualLesson' )
			->with( 1, 9001, '2026-05-20 15:00:00', null, null, null, null, $this->anything(), null )
			->willReturn( 15 );
		$_POST = array( 'group_id' => '1', 'student_person_id' => '9001', 'scheduled_at' => '2026-05-20 15:00:00' );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxCreateIndividualLesson() );

		self::assertTrue( $r->success );
		self::assertSame( 15, $r->payload['group_lesson_id'] );
	}

	public function test_create_individual_lesson_denied_when_not_manager(): void {
		$this->guard->method( 'canManage' )->willReturn( false );
		$this->schedule->expects( $this->never() )->method( 'createIndividualLesson' );
		$_POST = array( 'group_id' => '1', 'student_person_id' => '9001', 'scheduled_at' => '2026-05-20 15:00:00' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxCreateIndividualLesson() )->success );
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

	public function test_get_free_rooms_returns_rooms(): void {
		$this->guard->method( 'canManage' )->willReturn( true );
		$this->schedule->expects( $this->once() )
			->method( 'freeRoomsForGroup' )
			->with( 1, '2026-05-20 15:00:00', null )
			->willReturn( array( array( 'id' => 3, 'name' => '315' ) ) );
		$_POST = array( 'group_id' => '1', 'scheduled_at' => '2026-05-20 15:00:00' );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxGetFreeRooms() );

		self::assertTrue( $r->success );
		self::assertSame( '315', $r->payload['rooms'][0]['name'] );
	}

	public function test_get_free_rooms_denied_when_not_manager(): void {
		$this->guard->method( 'canManage' )->willReturn( false );
		$this->schedule->expects( $this->never() )->method( 'freeRoomsForGroup' );
		$_POST = array( 'group_id' => '1', 'scheduled_at' => '2026-05-20 15:00:00' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxGetFreeRooms() )->success );
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

	public function test_create_individual_lesson_surfaces_service_error(): void {
		$this->guard->method( 'canManage' )->willReturn( true );
		$this->schedule->method( 'createIndividualLesson' )
			->willThrowException( new \InvalidArgumentException( 'Ученик не состоит в этой группе.' ) );
		$_POST = array( 'group_id' => '1', 'student_person_id' => '9001', 'scheduled_at' => '2026-05-20 15:00:00' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxCreateIndividualLesson() )->success );
	}

	public function test_reflow_schedule_delegates_when_allowed(): void {
		$this->guard->method( 'canManage' )->willReturn( true );
		$this->schedule->expects( $this->once() )->method( 'reflow' )->with( 5, $this->anything() );
		$_POST = array( 'group_id' => '5' );

		self::assertTrue( fs_test_capture_json( fn() => $this->cb->ajaxReflowSchedule() )->success );
	}

	public function test_reflow_schedule_denied_when_not_manager(): void {
		$this->guard->method( 'canManage' )->willReturn( false );
		$this->schedule->expects( $this->never() )->method( 'reflow' );
		$_POST = array( 'group_id' => '5' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxReflowSchedule() )->success );
	}

	public function test_pin_lesson_delegates_to_pin_to_date(): void {
		$row = new \Inc\DTO\Course\GroupLessonDTO(
			id: 42, groupId: 5, lessonId: 1, position: 0, workIdsSnapshot: null, extraWorkIds: array(),
			scheduledAt: null, endsAt: null, isPinned: false, teacherUserId: null, visibility: 'open',
			openedAt: null, homeworkDueAt: null, allowLate: true, recordingUrl: null,
			createdByUserId: null, updatedByUserId: null,
		);
		$this->schedule->method( 'getProgramRow' )->with( 42 )->willReturn( $row );
		$this->guard->method( 'canManage' )->willReturn( true );
		$this->schedule->expects( $this->once() )->method( 'pinToDate' )->with( 42, '2026-05-20', $this->anything() );
		$_POST = array( 'group_lesson_id' => '42', 'scheduled_at' => '2026-05-20' );

		self::assertTrue( fs_test_capture_json( fn() => $this->cb->ajaxPinLesson() )->success );
	}

	public function test_pin_lesson_denied_when_row_missing(): void {
		$this->schedule->method( 'getProgramRow' )->willReturn( null );
		$this->schedule->expects( $this->never() )->method( 'pinToDate' );
		$_POST = array( 'group_lesson_id' => '42', 'scheduled_at' => '2026-05-20' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxPinLesson() )->success );
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
		$this->schedule->expects( $this->once() )->method( 'publishProgram' )->with( 5, $this->anything() );
		$this->schedule->method( 'programLockedAt' )->willReturn( '2026-07-02 10:00:00' );
		$_POST = array( 'group_id' => '5' );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxPublishProgram() );

		self::assertTrue( $r->success );
		self::assertTrue( $r->payload['locked'] );
	}

	public function test_unpublish_program_delegates(): void {
		$this->guard->method( 'canManage' )->willReturn( true );
		$this->schedule->expects( $this->once() )->method( 'unpublishProgram' )->with( 5, $this->anything() );
		$_POST = array( 'group_id' => '5' );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxUnpublishProgram() );

		self::assertTrue( $r->success );
		self::assertFalse( $r->payload['locked'] );
	}

	public function test_reflow_blocked_when_program_locked(): void {
		$this->guard->method( 'canManage' )->willReturn( true );
		$this->schedule->method( 'isProgramLocked' )->with( 5 )->willReturn( true );
		$this->schedule->expects( $this->never() )->method( 'reflow' );
		$_POST = array( 'group_id' => '5' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxReflowSchedule() )->success );
	}

	public function test_add_lesson_blocked_when_program_locked(): void {
		$this->guard->method( 'canManage' )->willReturn( true );
		$this->schedule->method( 'isProgramLocked' )->with( 5 )->willReturn( true );
		$this->schedule->expects( $this->never() )->method( 'addLesson' );
		$_POST = array( 'group_id' => '5', 'lesson_id' => '10' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxAddLessonToProgram() )->success );
	}

	/* ── Дедлайны работ (T12.3, D13) ─────────────────────────────────────── */

	private function rowWithDeadlines( array $workDeadlines = array() ): GroupLessonDTO {
		return new GroupLessonDTO(
			id: 42, groupId: 5, lessonId: 1, position: 0, workIdsSnapshot: null, extraWorkIds: array(),
			scheduledAt: null, endsAt: null, isPinned: false, teacherUserId: null, visibility: 'open',
			openedAt: null, homeworkDueAt: null, allowLate: true, recordingUrl: null,
			createdByUserId: null, updatedByUserId: null, workDeadlines: $workDeadlines,
		);
	}

	private function work( int $id, string $title ): WorkDTO {
		return new WorkDTO(
			id: $id, subjectKey: 'inf', title: $title, workType: WorkType::Practice,
			itemIds: array(), instructions: '', authorId: 1, status: 'publish',
		);
	}

	public function test_get_work_deadlines_returns_effective_works_with_current_deadlines(): void {
		$row = $this->rowWithDeadlines( array( 501 => '2026-08-01 12:00:00' ) );
		$this->schedule->method( 'getProgramRow' )->with( 42 )->willReturn( $row );
		$this->guard->method( 'canManage' )->with( 5, $this->anything() )->willReturn( true );
		$this->works->method( 'resolve' )->with( $row )->willReturn( array(
			$this->work( 501, 'Практика №1' ),
			$this->work( 502, 'Практика №2' ),
		) );
		$_POST = array( 'group_lesson_id' => '42' );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxGetWorkDeadlines() );

		self::assertTrue( $r->success );
		self::assertCount( 2, $r->payload['works'] );
		self::assertSame( '2026-08-01 12:00:00', $r->payload['works'][0]['deadline'] );
		self::assertNull( $r->payload['works'][1]['deadline'] );
	}

	public function test_get_work_deadlines_denied_when_not_manager(): void {
		$this->schedule->method( 'getProgramRow' )->willReturn( $this->rowWithDeadlines() );
		$this->guard->method( 'canManage' )->willReturn( false );
		$this->works->expects( $this->never() )->method( 'resolve' );
		$_POST = array( 'group_lesson_id' => '42' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxGetWorkDeadlines() )->success );
	}

	public function test_get_work_deadlines_denied_when_row_missing(): void {
		$this->schedule->method( 'getProgramRow' )->willReturn( null );
		$_POST = array( 'group_lesson_id' => '999' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxGetWorkDeadlines() )->success );
	}

	public function test_save_work_deadlines_delegates_sanitized_map(): void {
		$row = $this->rowWithDeadlines();
		$this->schedule->method( 'getProgramRow' )->with( 42 )->willReturn( $row );
		$this->guard->method( 'canManage' )->with( 5, $this->anything() )->willReturn( true );
		$this->groupLessons->expects( $this->once() )->method( 'setWorkDeadlines' )
			->with( 42, array( 501 => '2026-08-01 12:00:00' ) );
		$_POST = array(
			'group_lesson_id' => '42',
			'deadlines'       => json_encode( array( '501' => '2026-08-01 12:00:00', '502' => '' ) ),
		);

		$r = fs_test_capture_json( fn() => $this->cb->ajaxSaveWorkDeadlines() );

		self::assertTrue( $r->success );
	}

	public function test_save_work_deadlines_denied_when_not_manager(): void {
		$this->schedule->method( 'getProgramRow' )->willReturn( $this->rowWithDeadlines() );
		$this->guard->method( 'canManage' )->willReturn( false );
		$this->groupLessons->expects( $this->never() )->method( 'setWorkDeadlines' );
		$_POST = array( 'group_lesson_id' => '42', 'deadlines' => '{}' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxSaveWorkDeadlines() )->success );
	}

	public function test_save_work_deadlines_rejects_malformed_json(): void {
		$this->schedule->method( 'getProgramRow' )->willReturn( $this->rowWithDeadlines() );
		$this->guard->method( 'canManage' )->willReturn( true );
		$this->groupLessons->expects( $this->never() )->method( 'setWorkDeadlines' );
		$_POST = array( 'group_lesson_id' => '42', 'deadlines' => 'not-json' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxSaveWorkDeadlines() )->success );
	}

	/** T1.8: дедлайны — delivery, не структура/расписание — работают даже при lock КТП. */
	public function test_save_work_deadlines_not_blocked_when_program_locked(): void {
		$row = $this->rowWithDeadlines();
		$this->schedule->method( 'getProgramRow' )->willReturn( $row );
		$this->guard->method( 'canManage' )->willReturn( true );
		$this->schedule->method( 'isProgramLocked' )->willReturn( true );
		$this->groupLessons->expects( $this->once() )->method( 'setWorkDeadlines' );
		$_POST = array( 'group_lesson_id' => '42', 'deadlines' => json_encode( array( '501' => '2026-08-01 12:00:00' ) ) );

		self::assertTrue( fs_test_capture_json( fn() => $this->cb->ajaxSaveWorkDeadlines() )->success );
	}
}
