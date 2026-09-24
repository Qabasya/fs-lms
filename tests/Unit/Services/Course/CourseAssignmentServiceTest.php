<?php

declare( strict_types=1 );

namespace Unit\Services\Course;

use Inc\Contracts\ClockInterface;
use Inc\Contracts\LogEventDispatcherInterface;
use Inc\DTO\Course\CourseDTO;
use Inc\DTO\Course\GroupLessonDTO;
use Inc\DTO\Course\GroupLessonInputDTO;
use Inc\DTO\Course\ModuleDTO;
use Inc\Enums\Course\AssignmentPolicy;
use Inc\Enums\Course\LessonKind;
use Inc\Enums\Log\LogEvent;
use Inc\Managers\Course\CourseManager;
use Inc\Managers\Course\LessonManager;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Services\Course\CourseAssignmentService;
use Inc\Services\Course\GroupLessonUsageGuard;
use Inc\Services\Course\OpenCourseValidator;
use Inc\Services\Group\ScheduleEventPublisher;
use Inc\Services\Group\ScheduleReflowService;
use PHPUnit\Framework\TestCase;

class CourseAssignmentServiceTest extends TestCase {

	private const NOW = '2024-06-01 00:00:00';

	private CourseManager&\PHPUnit\Framework\MockObject\MockObject $courseManager;
	private GroupsRepository&\PHPUnit\Framework\MockObject\MockObject $groups;
	private GroupLessonRepository&\PHPUnit\Framework\MockObject\MockObject $groupLessons;
	private LogEventDispatcherInterface&\PHPUnit\Framework\MockObject\MockObject $dispatcher;
	private LessonManager&\PHPUnit\Framework\MockObject\MockObject $lessonManager;
	private OpenCourseValidator&\PHPUnit\Framework\MockObject\MockObject $openCourseValidator;
	private GroupLessonUsageGuard&\PHPUnit\Framework\MockObject\MockObject $usageGuard;
	private ScheduleReflowService&\PHPUnit\Framework\MockObject\MockObject $schedule;
	private ScheduleEventPublisher&\PHPUnit\Framework\MockObject\MockObject $events;
	private CourseAssignmentService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->courseManager       = $this->createMock( CourseManager::class );
		$this->groups              = $this->createMock( GroupsRepository::class );
		$this->groupLessons        = $this->createMock( GroupLessonRepository::class );
		$this->dispatcher          = $this->createMock( LogEventDispatcherInterface::class );
		$this->lessonManager       = $this->createMock( LessonManager::class );
		$this->openCourseValidator = $this->createMock( OpenCourseValidator::class );
		$this->usageGuard          = $this->createMock( GroupLessonUsageGuard::class );
		$this->schedule            = $this->createMock( ScheduleReflowService::class );
		$this->events              = $this->createMock( ScheduleEventPublisher::class );

		$clock = $this->createMock( ClockInterface::class );
		$clock->method( 'now' )->willReturn( self::NOW );

		$this->service = new CourseAssignmentService(
			$this->courseManager,
			$this->groups,
			$this->groupLessons,
			$this->dispatcher,
			$this->lessonManager,
			$clock,
			$this->openCourseValidator,
			$this->usageGuard,
			$this->schedule,
			$this->events,
		);
	}

	public function test_assign_inserts_one_row_per_lesson_and_returns_count(): void {
		$this->setupGroupAndCourse( lessonIds: [ 10, 20, 30 ] );
		$this->groupLessons->method( 'nextPosition' )->willReturn( 0 );
		$this->groupLessons->expects( self::exactly( 3 ) )->method( 'add' );

		$count = $this->service->assign( 1, 5, 99 );

		self::assertSame( 3, $count );
	}

	public function test_assign_writes_course_id_to_group(): void {
		$this->setupGroupAndCourse( lessonIds: [ 10 ] );
		$this->groupLessons->method( 'nextPosition' )->willReturn( 0 );

		$this->groups->expects( self::once() )
			->method( 'update' )
			->with( 1, self::callback( fn( $d ) => (int) $d['course_id'] === 5 ) );

		$this->service->assign( 1, 5, 99 );
	}

	public function test_assign_dispatches_course_assigned_event(): void {
		$this->setupGroupAndCourse( lessonIds: [ 10 ] );
		$this->groupLessons->method( 'nextPosition' )->willReturn( 0 );

		$this->dispatcher->expects( self::once() )
			->method( 'dispatch' )
			->with( LogEvent::CourseAssigned, self::anything() );

		$this->service->assign( 1, 5, 99 );
	}

	public function test_assign_append_does_not_delete_existing(): void {
		$this->setupGroupAndCourse( lessonIds: [ 10 ] );
		$this->groupLessons->method( 'nextPosition' )->willReturn( 0 );

		$this->groupLessons->expects( self::never() )->method( 'remove' );

		$this->service->assign( 1, 5, 99, AssignmentPolicy::Append );
	}

	/** Замена курса убирает только строки без данных учеников — журнал не теряет занятий. */
	public function test_assign_replace_keeps_rows_with_student_data(): void {
		$this->setupGroupAndCourse( lessonIds: [ 10 ] );
		$this->groupLessons->method( 'nextPosition' )->willReturn( 2 );
		$this->groupLessons->method( 'listByGroup' )->willReturn( array(
			$this->glRow( id: 100, lessonId: 7 ),
			$this->glRow( id: 200, lessonId: 8 ),
			$this->glRow( id: 300, lessonId: 9, kind: 'individual' ),
		) );
		$this->usageGuard->method( 'isSafeToRemove' )->willReturnCallback( static fn( int $id ) => 200 === $id );

		$this->groupLessons->expects( self::never() )->method( 'deleteAllByGroup' );
		$this->groupLessons->expects( self::once() )->method( 'remove' )->with( 200 );

		$this->service->assign( 1, 5, 99, AssignmentPolicy::Replace );
	}

	public function test_assign_rejects_cross_subject_course(): void {
		$group              = new \stdClass();
		$group->subject_key = 'math';
		$this->groups->method( 'findById' )->willReturn( $group );

		$course = $this->makeCourse( subjectKey: 'inf', lessonIds: [ 10 ] );
		$this->courseManager->method( 'get' )->willReturn( $course );

		$this->expectException( \InvalidArgumentException::class );
		$this->service->assign( 1, 5, 99 );
	}

	public function test_assign_throws_when_group_not_found(): void {
		$this->groups->method( 'findById' )->willReturn( null );
		$this->courseManager->method( 'get' )->willReturn( $this->makeCourse() );

		$this->expectException( \InvalidArgumentException::class );
		$this->service->assign( 1, 5, 99 );
	}

	public function test_assign_throws_when_course_not_found(): void {
		$group              = new \stdClass();
		$group->subject_key = 'inf';
		$this->groups->method( 'findById' )->willReturn( $group );
		$this->courseManager->method( 'get' )->willReturn( null );

		$this->expectException( \InvalidArgumentException::class );
		$this->service->assign( 1, 5, 99 );
	}

	public function test_assign_empty_course_adds_zero_rows(): void {
		$this->setupGroupAndCourse( lessonIds: [] );

		$this->groupLessons->expects( self::never() )->method( 'add' );

		$count = $this->service->assign( 1, 5, 99 );

		self::assertSame( 0, $count );
	}

	// --- Эпик 15: открытая группа — программа публикуется сразу ---

	public function test_assign_to_open_group_creates_published_rows_without_dates(): void {
		$this->setupGroupAndCourse( lessonIds: [ 10 ], accessMode: 'open' );
		$this->groupLessons->method( 'nextPosition' )->willReturn( 0 );

		$this->groupLessons->expects( self::once() )
			->method( 'add' )
			->with( self::callback(
				static fn( GroupLessonInputDTO $dto ) => 'open' === $dto->visibility
					&& self::NOW === $dto->openedAt
					&& is_array( $dto->workIdsSnapshot )
					&& null === $dto->scheduledAt
			) );

		$this->service->assign( 1, 5, 99 );
	}

	public function test_assign_to_scheduled_group_creates_hidden_rows(): void {
		$this->setupGroupAndCourse( lessonIds: [ 10 ] );
		$this->groupLessons->method( 'nextPosition' )->willReturn( 0 );

		$this->groupLessons->expects( self::once() )
			->method( 'add' )
			->with( self::callback(
				static fn( GroupLessonInputDTO $dto ) => 'hidden' === $dto->visibility
					&& null === $dto->openedAt
					&& null === $dto->workIdsSnapshot
			) );

		$this->service->assign( 1, 5, 99 );
	}

	public function test_assign_to_open_group_does_not_reject_course_without_autocheck(): void {
		// Ручная проверка в открытой группе не критична (некому проверять) —
		// назначение больше не блокируется, только предупреждение (warningsFor()).
		$this->setupGroupAndCourse( lessonIds: [ 10 ], accessMode: 'open' );
		$this->groupLessons->method( 'nextPosition' )->willReturn( 0 );
		$this->openCourseValidator->method( 'problems' )->willReturn( array( 'нет автопроверки' ) );

		$this->groupLessons->expects( self::once() )->method( 'add' );

		$count = $this->service->assign( 1, 5, 99 );

		self::assertSame( 1, $count );
	}

	public function test_assign_never_calls_assert_self_checkable(): void {
		$this->setupGroupAndCourse( lessonIds: [ 10 ], accessMode: 'open' );
		$this->groupLessons->method( 'nextPosition' )->willReturn( 0 );

		$this->openCourseValidator->expects( self::never() )->method( 'assertSelfCheckable' );

		$this->service->assign( 1, 5, 99 );
	}

	public function test_warnings_for_returns_problems_for_open_group(): void {
		$this->setupGroupAndCourse( lessonIds: [ 10 ], accessMode: 'open' );
		$this->openCourseValidator->method( 'problems' )->willReturn( array( '«Урок»: задач без автопроверки — 2' ) );

		$warnings = $this->service->warningsFor( 1, 5 );

		self::assertSame( array( '«Урок»: задач без автопроверки — 2' ), $warnings );
	}

	public function test_warnings_for_returns_empty_for_scheduled_group(): void {
		$this->setupGroupAndCourse( lessonIds: [ 10 ], accessMode: 'scheduled' );

		$this->openCourseValidator->expects( self::never() )->method( 'problems' );

		self::assertSame( array(), $this->service->warningsFor( 1, 5 ) );
	}

	// --- D17.3: reconcile осиротевших строк доставки ---

	public function test_reconcile_removes_safe_orphan_delivery_row(): void {
		$this->courseManager->method( 'get' )->willReturn( $this->makeCourse( lessonIds: [ 10 ] ) );
		$group = (object) array( 'id' => 1, 'access_mode' => 'scheduled', 'program_locked_at' => null );
		$this->groups->method( 'findByCourse' )->with( 5 )->willReturn( array( $group ) );
		$this->groupLessons->method( 'nextPosition' )->willReturn( 5 );
		// Строка урока 10 — в курсе (оставить); урок 99 — сирота (ошибочная копия).
		$this->groupLessons->method( 'listByGroup' )->with( 1 )->willReturn( array(
			$this->glRow( id: 100, lessonId: 10 ),
			$this->glRow( id: 200, lessonId: 99 ),
		) );
		$this->usageGuard->method( 'isSafeToRemove' )->with( 200 )->willReturn( true );

		$this->groupLessons->expects( self::once() )->method( 'remove' )->with( 200 )->willReturn( true );

		$res = $this->service->reconcileCourseLessons( 5, 99 );

		self::assertSame( 1, $res['removed'] );
	}

	public function test_reconcile_keeps_orphan_with_engagement(): void {
		$this->courseManager->method( 'get' )->willReturn( $this->makeCourse( lessonIds: [ 10 ] ) );
		$group = (object) array( 'id' => 1, 'access_mode' => 'scheduled', 'program_locked_at' => null );
		$this->groups->method( 'findByCourse' )->with( 5 )->willReturn( array( $group ) );
		$this->groupLessons->method( 'nextPosition' )->willReturn( 5 );
		$this->groupLessons->method( 'listByGroup' )->with( 1 )->willReturn( array(
			$this->glRow( id: 200, lessonId: 99 ),
		) );
		// За строкой есть данные журнала → не удаляем.
		$this->usageGuard->method( 'isSafeToRemove' )->with( 200 )->willReturn( false );

		$this->groupLessons->expects( self::never() )->method( 'remove' );

		$res = $this->service->reconcileCourseLessons( 5, 99 );

		self::assertSame( 0, $res['removed'] );
	}

	public function test_reconcile_cleans_locked_program_too(): void {
		$this->courseManager->method( 'get' )->willReturn( $this->makeCourse( lessonIds: [ 10 ] ) );
		$group = (object) array( 'id' => 1, 'access_mode' => 'scheduled', 'program_locked_at' => '2026-01-01 00:00:00' );
		$this->groups->method( 'findByCourse' )->with( 5 )->willReturn( array( $group ) );
		$this->groupLessons->method( 'listByGroup' )->with( 1 )->willReturn( array(
			$this->glRow( id: 100, lessonId: 10 ),
			$this->glRow( id: 200, lessonId: 99 ),
		) );
		$this->usageGuard->method( 'isSafeToRemove' )->with( 200 )->willReturn( true );

		// Публикация КТП не замораживает доставку курса: сирота без данных журнала уходит.
		$this->groupLessons->expects( self::once() )->method( 'remove' )->with( 200 )->willReturn( true );

		$res = $this->service->reconcileCourseLessons( 5, 99 );

		self::assertSame( 1, $res['removed'] );
	}

	// --- Живой курс: новые уроки встают в КТП на своё место ---

	public function test_sync_inserts_new_lesson_right_after_previous_course_lesson(): void {
		// Курс: 10, 15 (новый), 20. В КТП: 10 (две части темы) и 20.
		$this->courseManager->method( 'get' )->willReturn( $this->makeCourse( lessonIds: [ 10, 15, 20 ] ) );
		$group = (object) array( 'id' => 1, 'access_mode' => 'scheduled', 'program_locked_at' => null );
		$this->groups->method( 'findByCourse' )->willReturn( array( $group ) );
		$this->groupLessons->method( 'listByGroup' )->willReturn( array(
			$this->glRow( id: 100, lessonId: 10, position: 0 ),
			$this->glRow( id: 101, lessonId: 10, position: 1 ),
			$this->glRow( id: 200, lessonId: 20, position: 2 ),
		) );

		$this->groupLessons->expects( self::once() )->method( 'shiftPositions' )->with( 1, 2 );
		$this->groupLessons->expects( self::once() )->method( 'add' )
			->with( self::callback( fn( GroupLessonInputDTO $d ) => 15 === $d->lessonId && 2 === $d->position ) )
			->willReturn( 300 );
		$this->schedule->expects( self::once() )->method( 'placeInserted' )->with( 300, 99 );
		$this->events->expects( self::once() )->method( 'lessonAdded' )->with( 1, 15, 'inf', 99 );

		self::assertSame( 1, $this->service->syncCourseLessons( 5, 99 ) );
	}

	public function test_sync_appends_lesson_added_to_course_end(): void {
		$this->courseManager->method( 'get' )->willReturn( $this->makeCourse( lessonIds: [ 10, 20 ] ) );
		$group = (object) array( 'id' => 1, 'access_mode' => 'scheduled', 'program_locked_at' => null );
		$this->groups->method( 'findByCourse' )->willReturn( array( $group ) );
		$this->groupLessons->method( 'listByGroup' )->willReturn( array(
			$this->glRow( id: 100, lessonId: 10, position: 0 ),
		) );

		$this->groupLessons->expects( self::once() )->method( 'add' )
			->with( self::callback( fn( GroupLessonInputDTO $d ) => 20 === $d->lessonId && 1 === $d->position ) )
			->willReturn( 300 );
		$this->schedule->expects( self::once() )->method( 'placeInserted' )->with( 300, 99 );

		self::assertSame( 1, $this->service->syncCourseLessons( 5, 99 ) );
	}

	public function test_sync_delivers_to_locked_program(): void {
		$this->courseManager->method( 'get' )->willReturn( $this->makeCourse( lessonIds: [ 10, 20 ] ) );
		$group = (object) array( 'id' => 1, 'access_mode' => 'scheduled', 'program_locked_at' => '2026-01-01 00:00:00' );
		$this->groups->method( 'findByCourse' )->willReturn( array( $group ) );
		$this->groupLessons->method( 'listByGroup' )->willReturn( array(
			$this->glRow( id: 100, lessonId: 10, position: 0 ),
		) );

		$this->groupLessons->expects( self::once() )->method( 'add' )->willReturn( 300 );

		self::assertSame( 1, $this->service->syncCourseLessons( 5, 99 ) );
	}

	public function test_sync_ignores_individual_row_with_course_lesson(): void {
		$this->courseManager->method( 'get' )->willReturn( $this->makeCourse( lessonIds: [ 10 ] ) );
		$group = (object) array( 'id' => 1, 'access_mode' => 'scheduled', 'program_locked_at' => null );
		$this->groups->method( 'findByCourse' )->willReturn( array( $group ) );
		$this->groupLessons->method( 'listByGroup' )->willReturn( array(
			$this->glRow( id: 100, lessonId: 10, kind: 'individual' ),
		) );

		$this->groupLessons->expects( self::once() )->method( 'add' )->willReturn( 300 );

		self::assertSame( 1, $this->service->syncCourseLessons( 5, 99 ) );
	}

	public function test_sync_skips_draft_lesson_for_open_group(): void {
		$this->courseManager->method( 'get' )->willReturn( $this->makeCourse( lessonIds: [ 10 ] ) );
		$group = (object) array( 'id' => 1, 'access_mode' => 'open', 'program_locked_at' => null );
		$this->groups->method( 'findByCourse' )->willReturn( array( $group ) );
		$this->groupLessons->method( 'listByGroup' )->willReturn( array() );
		$this->lessonManager->method( 'isPublished' )->with( 10 )->willReturn( false );

		$this->groupLessons->expects( self::never() )->method( 'add' );

		self::assertSame( 0, $this->service->syncCourseLessons( 5, 99 ) );
	}

	public function test_sync_open_group_gets_published_lesson_without_dates(): void {
		$this->courseManager->method( 'get' )->willReturn( $this->makeCourse( lessonIds: [ 10 ] ) );
		$group = (object) array( 'id' => 1, 'access_mode' => 'open', 'program_locked_at' => null );
		$this->groups->method( 'findByCourse' )->willReturn( array( $group ) );
		$this->groupLessons->method( 'listByGroup' )->willReturn( array() );
		$this->lessonManager->method( 'isPublished' )->willReturn( true );

		$this->groupLessons->expects( self::once() )->method( 'add' )->willReturn( 300 );
		$this->schedule->expects( self::never() )->method( 'placeInserted' );

		self::assertSame( 1, $this->service->syncCourseLessons( 5, 99 ) );
	}

	// --- helpers ---

	private function glRow( int $id, int $lessonId, string $kind = 'group', int $position = 0 ): GroupLessonDTO {
		return new GroupLessonDTO(
			id: $id, groupId: 1, lessonId: $lessonId, position: $position, workIdsSnapshot: null, extraWorkIds: array(),
			scheduledAt: null, endsAt: null, isPinned: false, teacherUserId: null, visibility: 'hidden',
			openedAt: null, homeworkDueAt: null, allowLate: true, recordingUrl: null,
			createdByUserId: null, updatedByUserId: null, label: null, kind: LessonKind::fromValueOrDefault( $kind ),
		);
	}

	private function setupGroupAndCourse( array $lessonIds = [ 10 ], string $accessMode = 'scheduled' ): void {
		$group              = new \stdClass();
		$group->subject_key = 'inf';
		$group->access_mode = $accessMode;
		$this->groups->method( 'findById' )->willReturn( $group );
		$this->courseManager->method( 'get' )->willReturn( $this->makeCourse( lessonIds: $lessonIds ) );
	}

	private function makeCourse( string $subjectKey = 'inf', array $lessonIds = [ 10 ] ): CourseDTO {
		return new CourseDTO(
			id             : 5,
			subjectKey     : $subjectKey,
			title          : 'Test Course',
			descriptionHtml: '',
			modules        : array( new ModuleDTO( 'm1', 'Модуль', $lessonIds ) ),
			authorId       : 1,
			status         : 'publish',
		);
	}
}
