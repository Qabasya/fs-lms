<?php

declare( strict_types=1 );

namespace Unit\Services\Course;

use Inc\Contracts\LogEventDispatcherInterface;
use Inc\DTO\Course\CourseDTO;
use Inc\Enums\LogEvent;
use Inc\Managers\CourseManager;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Services\Course\CourseAssignmentService;
use PHPUnit\Framework\TestCase;

class CourseAssignmentServiceTest extends TestCase {

	private CourseManager&\PHPUnit\Framework\MockObject\MockObject $courseManager;
	private GroupsRepository&\PHPUnit\Framework\MockObject\MockObject $groups;
	private GroupLessonRepository&\PHPUnit\Framework\MockObject\MockObject $groupLessons;
	private LogEventDispatcherInterface&\PHPUnit\Framework\MockObject\MockObject $dispatcher;
	private CourseAssignmentService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->courseManager = $this->createMock( CourseManager::class );
		$this->groups        = $this->createMock( GroupsRepository::class );
		$this->groupLessons  = $this->createMock( GroupLessonRepository::class );
		$this->dispatcher    = $this->createMock( LogEventDispatcherInterface::class );
		$this->service       = new CourseAssignmentService(
			$this->courseManager,
			$this->groups,
			$this->groupLessons,
			$this->dispatcher,
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

		$this->groupLessons->expects( self::never() )->method( 'deleteAllByGroup' );

		$this->service->assign( 1, 5, 99, 'append' );
	}

	public function test_assign_replace_deletes_existing_first(): void {
		$this->setupGroupAndCourse( lessonIds: [ 10 ] );
		$this->groupLessons->method( 'nextPosition' )->willReturn( 0 );

		$this->groupLessons->expects( self::once() )
			->method( 'deleteAllByGroup' )
			->with( 1 );

		$this->service->assign( 1, 5, 99, 'replace' );
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

	// --- helpers ---

	private function setupGroupAndCourse( array $lessonIds = [ 10 ] ): void {
		$group              = new \stdClass();
		$group->subject_key = 'inf';
		$this->groups->method( 'findById' )->willReturn( $group );
		$this->courseManager->method( 'get' )->willReturn( $this->makeCourse( lessonIds: $lessonIds ) );
	}

	private function makeCourse( string $subjectKey = 'inf', array $lessonIds = [ 10 ] ): CourseDTO {
		return new CourseDTO(
			id             : 5,
			subjectKey     : $subjectKey,
			title          : 'Test Course',
			descriptionHtml: '',
			lessonIds      : $lessonIds,
			authorId       : 1,
			status         : 'publish',
		);
	}
}
