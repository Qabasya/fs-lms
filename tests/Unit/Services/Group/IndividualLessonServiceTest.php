<?php

declare( strict_types=1 );

namespace Unit\Services\Group;

use Inc\Contracts\LogEventDispatcherInterface;
use Inc\Enums\Log\LogEvent;
use Inc\Managers\Course\CourseManager;
use Inc\Managers\Course\LessonManager;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\RoomRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Services\Group\IndividualLessonService;
use Inc\Services\Group\ScheduleEventPublisher;
use PHPUnit\Framework\TestCase;
use Tests\Support\GroupLessonFixtures;

/**
 * Индивидуальные занятия (D3): создание и правка.
 */
class IndividualLessonServiceTest extends TestCase {

	use GroupLessonFixtures;

	private GroupLessonRepository&\PHPUnit\Framework\MockObject\MockObject $groupLessons;
	private GroupsRepository&\PHPUnit\Framework\MockObject\MockObject $groups;
	private StudentRecordRepository&\PHPUnit\Framework\MockObject\MockObject $records;
	private RoomRepository&\PHPUnit\Framework\MockObject\MockObject $rooms;
	private LessonManager&\PHPUnit\Framework\MockObject\MockObject $lessonManager;
	private CourseManager&\PHPUnit\Framework\MockObject\MockObject $courses;
	private LogEventDispatcherInterface&\PHPUnit\Framework\MockObject\MockObject $dispatcher;
	private IndividualLessonService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->groupLessons  = $this->createMock( GroupLessonRepository::class );
		$this->groups        = $this->createMock( GroupsRepository::class );
		$this->records       = $this->createMock( StudentRecordRepository::class );
		$this->rooms         = $this->createMock( RoomRepository::class );
		$this->lessonManager = $this->createMock( LessonManager::class );
		$this->courses       = $this->createMock( CourseManager::class );
		$this->dispatcher    = $this->createMock( LogEventDispatcherInterface::class );

		$this->service = new IndividualLessonService(
			$this->groupLessons,
			$this->groups,
			$this->records,
			$this->rooms,
			$this->lessonManager,
			$this->courses,
			new ScheduleEventPublisher( $this->dispatcher ),
		);
	}

	public function test_create_individual_lesson_inserts_individual_pinned_row(): void {
		$this->groups->method( 'findById' )->with( 1 )->willReturn( new \stdClass() );
		$this->records->method( 'findActiveByGroupId' )->with( 1 )
			->willReturn( array( (object) array( 'studentPersonId' => 9001 ) ) );

		$this->groupLessons->expects( self::once() )
			->method( 'add' )
			->with( self::callback(
				static fn( $dto ) => $dto->kind->isIndividual()
					&& 9001 === $dto->studentPersonId
					&& true === $dto->isPinned
					&& '2026-05-20 15:00:00' === $dto->scheduledAt
			) )
			->willReturn( 15 );
		$this->dispatcher->expects( self::once() )
			->method( 'dispatch' )
			->with( LogEvent::ScheduleChanged, self::anything() );

		$id = $this->service->createIndividualLesson( 1, 9001, '2026-05-20 15:00:00', null, null, null, null, 99 );
		self::assertSame( 15, $id );
	}

	public function test_create_individual_lesson_rejects_non_member(): void {
		$this->groups->method( 'findById' )->willReturn( new \stdClass() );
		$this->records->method( 'findActiveByGroupId' )->willReturn( array() );
		$this->groupLessons->expects( self::never() )->method( 'add' );

		$this->expectException( \InvalidArgumentException::class );
		$this->service->createIndividualLesson( 1, 9001, '2026-05-20 15:00:00', null, null, null, null, 99 );
	}

	public function test_assign_lesson_rejects_non_individual_row(): void {
		$this->groupLessons->method( 'find' )->willReturn( $this->makeRow( 42, 'group' ) );
		$this->groupLessons->expects( self::never() )->method( 'setLessonId' );

		$this->expectException( \InvalidArgumentException::class );
		$this->service->assignLessonToIndividual( 42, 10, 99 );
	}

	public function test_update_individual_lesson_changes_only_provided_fields(): void {
		$this->groupLessons->method( 'find' )->willReturn( $this->makeRow( 42, 'individual' ) );

		$this->groupLessons->expects( self::once() )->method( 'setRoom' )->with( 42, 7 );
		$this->groupLessons->expects( self::never() )->method( 'updateSchedule' );
		$this->groupLessons->expects( self::never() )->method( 'setStudentPersonId' );
		$this->groupLessons->expects( self::never() )->method( 'setLessonId' );

		$this->service->updateIndividualLesson( 42, null, null, 7, null, null, 99 );
	}

	public function test_update_individual_lesson_rejects_student_from_another_group(): void {
		$this->groupLessons->method( 'find' )->willReturn( $this->makeRow( 42, 'individual' ) );
		$this->records->method( 'findActiveByGroupId' )->willReturn( array() );

		$this->expectException( \InvalidArgumentException::class );
		$this->service->updateIndividualLesson( 42, null, null, null, 9001, null, 99 );
	}
}
