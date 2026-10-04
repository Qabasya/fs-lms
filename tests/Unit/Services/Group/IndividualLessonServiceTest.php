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
	private \Inc\Repositories\WPDBRepositories\ExamSessionRepository&\PHPUnit\Framework\MockObject\MockObject $examSessions;
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
		$this->examSessions  = $this->createMock( \Inc\Repositories\WPDBRepositories\ExamSessionRepository::class );

		$this->service = new IndividualLessonService(
			$this->groupLessons,
			$this->groups,
			$this->records,
			$this->rooms,
			$this->lessonManager,
			$this->courses,
			new ScheduleEventPublisher( $this->dispatcher ),
			new \Inc\Services\Course\RoomAvailabilityService(
				$this->rooms,
				$this->examSessions,
				new \Inc\Services\Exam\ExamTime( $this->createMock( \Inc\Contracts\ClockInterface::class ) )
			),
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
	// ---- кабинет и экзамены (4.4) -------------------------------------------------------------------------------------------------

	private function arrangeMember(): void {
		$this->groups->method( 'findById' )->willReturn( new \stdClass() );
		$this->records->method( 'findActiveByGroupId' )->willReturn( array( (object) array( 'studentPersonId' => 9001 ) ) );
	}

	public function test_create_in_room_locks_it_before_checking_exams_and_inserts_in_one_transaction(): void {
		$log = array();
		$original = $GLOBALS['wpdb'];
		$db = new class() extends \wpdb {
			/** @var string[] */
			public array $log = array();

			public function query( string $sql ): bool|int {
				$this->log[] = $sql;
				return 1;
			}
		};
		$GLOBALS['wpdb'] = $db;
		$this->arrangeMember();
		$this->rooms->method( 'lockForUpdate' )->willReturnCallback( static function () use ( $db ): void {
			$db->log[] = 'room-lock';
		} );
		$this->examSessions->method( 'isRoomBusy' )->willReturnCallback( static function () use ( $db ): bool {
			$db->log[] = 'exam-check';
			return false;
		} );
		$this->groupLessons->method( 'add' )->willReturnCallback( static function () use ( $db ): int {
			$db->log[] = 'insert';
			return 15;
		} );

		try {
			$this->service->createIndividualLesson( 1, 9001, '2026-05-20 15:00:00', '2026-05-20 16:00:00', null, null, null, 99, 4 );
		} finally {
			$GLOBALS['wpdb'] = $original;
		}

		self::assertSame( array( 'START TRANSACTION', 'room-lock', 'exam-check', 'insert', 'COMMIT' ), $db->log );
	}

	public function test_create_in_room_taken_by_exam_is_refused_and_nothing_is_inserted(): void {
		$this->arrangeMember();
		$this->examSessions->method( 'isRoomBusy' )->willReturn( true );
		$this->groupLessons->expects( self::never() )->method( 'add' );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Кабинет занят экзаменом в это время.' );

		$this->service->createIndividualLesson( 1, 9001, '2026-05-20 15:00:00', null, null, null, null, 99, 4 );
	}

	public function test_exam_window_for_a_lesson_without_end_is_one_hour(): void {
		$this->arrangeMember();
		$this->examSessions->expects( self::once() )->method( 'isRoomBusy' )->with( 4, '2026-05-20 15:00:00', '2026-05-20 16:00:00' )->willReturn( false );
		$this->groupLessons->method( 'add' )->willReturn( 15 );

		$this->service->createIndividualLesson( 1, 9001, '2026-05-20 15:00:00', null, null, null, null, 99, 4 );
	}

	public function test_create_without_room_takes_no_lock_and_checks_no_exams(): void {
		$this->arrangeMember();
		$this->rooms->expects( self::never() )->method( 'lockForUpdate' );
		$this->examSessions->expects( self::never() )->method( 'isRoomBusy' );
		$this->groupLessons->method( 'add' )->willReturn( 15 );

		$this->service->createIndividualLesson( 1, 9001, '2026-05-20 15:00:00', null, null, null, null, 99 );
	}

	public function test_update_to_room_taken_by_exam_is_refused(): void {
		$this->groupLessons->method( 'find' )->willReturn( $this->makeRow( 42, 'individual' ) );
		$this->examSessions->method( 'isRoomBusy' )->willReturn( true );
		$this->groupLessons->expects( self::never() )->method( 'setRoom' );
		$this->groupLessons->expects( self::never() )->method( 'updateSchedule' );

		$this->expectException( \InvalidArgumentException::class );

		$this->service->updateIndividualLesson( 42, '2026-05-21 10:00:00', '2026-05-21 11:00:00', 7, null, null, 99 );
	}

	public function test_removing_the_room_needs_no_lock(): void {
		$this->groupLessons->method( 'find' )->willReturn( $this->makeRow( 42, 'individual' ) );
		$this->rooms->expects( self::never() )->method( 'lockForUpdate' );
		$this->groupLessons->expects( self::once() )->method( 'setRoom' )->with( 42, null );

		$this->service->updateIndividualLesson( 42, null, null, 0, null, null, 99 );
	}
}
