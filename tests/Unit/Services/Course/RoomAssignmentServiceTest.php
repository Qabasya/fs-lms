<?php

declare( strict_types=1 );

namespace Unit\Services\Course;

use Inc\Contracts\ClockInterface;
use Inc\DTO\Course\GroupLessonDTO;
use Inc\DTO\Course\RoomDTO;
use Inc\Enums\Course\LessonKind;
use Inc\Enums\Profile\NotificationType;
use Inc\Repositories\WPDBRepositories\ExamSessionRepository;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\RoomRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Services\Course\RoomAssignmentService;
use Inc\Services\Course\RoomAvailabilityService;
use Inc\Services\Exam\ExamTime;
use Inc\Services\Profile\NotificationService;
use PHPUnit\Framework\TestCase;

class RoomAssignmentServiceTest extends TestCase {

	private $rooms;
	private $groups;
	private $groupLessons;
	private $records;
	private $notifications;
	private $examSessions;
	private RoomAssignmentService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->rooms        = $this->createMock( RoomRepository::class );
		$this->groups       = $this->createMock( GroupsRepository::class );
		$this->groupLessons = $this->createMock( GroupLessonRepository::class );
		$this->records      = $this->createMock( StudentRecordRepository::class );
		// Без явных стабов PHPUnit-мок сам вернёт «пусто» подходящего типа
		// (array()/null/'') — кейсам, не относящимся к уведомлениям, этого достаточно.
		$this->notifications = $this->createMock( NotificationService::class );
		$this->examSessions = $this->createMock( ExamSessionRepository::class );
		$this->service = new RoomAssignmentService(
			$this->rooms, $this->groups, $this->groupLessons, $this->records, $this->notifications,
			new RoomAvailabilityService( $this->rooms, $this->examSessions, new ExamTime( $this->createMock( ClockInterface::class ) ) )
		);
	}

	public function test_assign_to_group_sets_room_and_no_warning(): void {
		$this->groups->method( 'findById' )->with( 7 )->willReturn( (object) array( 'subject_key' => 'inf' ) );
		$this->rooms->method( 'find' )->with( 3 )->willReturn( new RoomDTO( 3, 'A', 30, array( 'inf' ), true ) );
		$this->records->method( 'countActiveByGroup' )->willReturn( 20 );
		$this->groups->expects( self::once() )->method( 'update' )->with( 7, array( 'room_id' => 3 ) );

		self::assertSame( array(), $this->service->assignToGroup( 7, 3 ) );
	}

	public function test_assign_to_group_warns_on_capacity(): void {
		$this->groups->method( 'findById' )->willReturn( (object) array( 'subject_key' => 'inf' ) );
		$this->rooms->method( 'find' )->willReturn( new RoomDTO( 3, 'A', 5, array(), true ) );
		$this->records->method( 'countActiveByGroup' )->willReturn( 20 );
		$this->groups->method( 'update' )->willReturn( true );

		$warnings = $this->service->assignToGroup( 7, 3 );
		self::assertNotEmpty( $warnings );
	}

	public function test_assign_to_group_rejects_wrong_subject(): void {
		$this->groups->method( 'findById' )->willReturn( (object) array( 'subject_key' => 'inf' ) );
		$this->rooms->method( 'find' )->willReturn( new RoomDTO( 3, 'A', 30, array( 'rus' ), true ) );
		$this->groups->expects( self::never() )->method( 'update' );

		$this->expectException( \InvalidArgumentException::class );
		$this->service->assignToGroup( 7, 3 );
	}

	public function test_assign_to_lesson_rejects_time_conflict(): void {
		$this->groupLessons->method( 'find' )->willReturn( $this->lesson( '2026-05-20 09:00:00', '2026-05-20 09:45:00' ) );
		$this->rooms->method( 'find' )->willReturn( new RoomDTO( 3, 'A', 30, array(), true ) );
		$this->rooms->method( 'isBusy' )->willReturn( true );
		$this->groupLessons->expects( self::never() )->method( 'setRoom' );

		$this->expectException( \InvalidArgumentException::class );
		$this->service->assignToLesson( 10, 3 );
	}

	public function test_assign_to_lesson_sets_room_when_free(): void {
		$this->groupLessons->method( 'find' )->willReturn( $this->lesson( '2026-05-20 09:00:00', '2026-05-20 09:45:00' ) );
		$this->rooms->method( 'find' )->willReturn( new RoomDTO( 3, 'A', 30, array(), true ) );
		$this->rooms->method( 'isBusy' )->willReturn( false );
		$this->groupLessons->expects( self::once() )->method( 'setRoom' )->with( 10, 3 );

		$this->service->assignToLesson( 10, 3 );
	}

	public function test_assign_to_lesson_notifies_students_and_teacher_on_room_change(): void {
		$lesson = $this->lesson( '2026-05-20 09:00:00', '2026-05-20 09:45:00' );
		$this->groupLessons->method( 'find' )->willReturn( $lesson );
		$this->rooms->method( 'find' )->willReturn( new RoomDTO( 3, 'Кабинет A', 30, array(), true ) );
		$this->rooms->method( 'isBusy' )->willReturn( false );
		$this->notifications->method( 'lessonStudentUserIds' )->with( $lesson )->willReturn( array( 101, 102 ) );
		$this->notifications->method( 'lessonTeacherUserId' )->with( $lesson )->willReturn( 55 );
		$this->notifications->method( 'groupName' )->with( 7 )->willReturn( 'ОГЭ-1' );

		$this->notifications->expects( self::once() )
			->method( 'pushFresh' )
			->with(
				array( 101, 102, 55 ),
				NotificationType::RoomChanged,
				'room-lesson:10',
				self::callback(
					static fn( $p ) => 'ОГЭ-1' === $p['group_name']
						&& '—' === $p['old_room']
						&& 'Кабинет A' === $p['new_room']
				),
				self::anything(),
				7,
				'group_lesson',
				10
			);

		$this->service->assignToLesson( 10, 3 );
	}

	public function test_assign_to_lesson_does_not_notify_when_room_unchanged(): void {
		$this->groupLessons->method( 'find' )->willReturn( $this->lesson( '2026-05-20 09:00:00', '2026-05-20 09:45:00' ) );
		$this->rooms->method( 'isBusy' )->willReturn( false );
		$this->notifications->expects( self::never() )->method( 'pushFresh' );

		// Урок без кабинета (roomId=null), назначаем тот же NULL — реального изменения нет.
		$this->service->assignToLesson( 10, null );
	}

	public function test_override_for_range_applies_and_skips_conflicts(): void {
		$this->rooms->method( 'find' )->willReturn( new RoomDTO( 3, 'A', 30, array(), true ) );
		$this->groups->method( 'findById' )->willReturn( (object) array( 'subject_key' => 'inf' ) );
		$this->groupLessons->method( 'listByGroup' )->willReturn( array(
			$this->lessonAt( 10, '2026-05-05 09:00:00', 'group' ), // в диапазоне, свободно → применить
			$this->lessonAt( 11, '2026-05-06 09:00:00', 'group' ), // в диапазоне, занято → пропуск
			$this->lessonAt( 12, '2026-06-01 09:00:00', 'group' ), // вне диапазона → игнор
			$this->lessonAt( 13, '2026-05-07 09:00:00', 'individual' ), // индивидуальное → игнор
		) );
		$this->rooms->method( 'isBusy' )->willReturnCallback(
			static fn( $roomId, $start ) => str_starts_with( (string) $start, '2026-05-06' )
		);
		$this->groupLessons->expects( self::once() )->method( 'setRoom' )->with( 10, 3 );

		$res = $this->service->overrideForRange( 7, 3, '2026-05-01', '2026-05-31' );

		self::assertSame( 1, $res['applied'] );
		self::assertSame( 1, $res['skipped'] );
		self::assertNotEmpty( $res['warnings'] );
	}

	/** Подмена соединения: журнал границ транзакции и обращений в одном порядке. */
	private function recordingDb(): object {
		$original = $GLOBALS['wpdb'];
		$db       = new class() extends \wpdb {
			/** @var string[] */
			public array $log = array();
			public ?\wpdb $originalForTearDown = null;

			public function query( string $sql ): bool|int {
				$this->log[] = $sql;
				return 1;
			}
		};
		$GLOBALS['wpdb']        = $db;
		$db->originalForTearDown = $original;

		return $db;
	}

	protected function tearDown(): void {
		if ( isset( $GLOBALS['wpdb']->originalForTearDown ) ) {
			$GLOBALS['wpdb'] = $GLOBALS['wpdb']->originalForTearDown;
		}
		parent::tearDown();
	}

	public function test_assign_to_lesson_locks_room_before_checking_busy_and_writes_in_same_transaction(): void {
		$db = $this->recordingDb();
		$this->groupLessons->method( 'find' )->willReturn( $this->lesson( '2026-05-20 09:00:00', '2026-05-20 09:45:00' ) );
		$this->rooms->method( 'find' )->willReturn( new RoomDTO( 3, 'A', 30, array(), true ) );
		$this->rooms->method( 'lockForUpdate' )->willReturnCallback( static function () use ( $db ): void {
			$db->log[] = 'room-lock';
		} );
		$this->rooms->method( 'isBusy' )->willReturnCallback( static function () use ( $db ): bool {
			$db->log[] = 'busy-check';
			return false;
		} );
		$this->groupLessons->method( 'setRoom' )->willReturnCallback( static function () use ( $db ): bool {
			$db->log[] = 'write';
			return true;
		} );

		$this->service->assignToLesson( 10, 3 );

		self::assertSame( array( 'START TRANSACTION', 'room-lock', 'busy-check', 'write', 'COMMIT' ), $db->log );
	}

	public function test_assign_to_lesson_rejects_room_taken_by_exam_session(): void {
		$this->groupLessons->method( 'find' )->willReturn( $this->lesson( '2026-05-20 09:00:00', '2026-05-20 09:45:00' ) );
		$this->rooms->method( 'find' )->willReturn( new RoomDTO( 3, 'A', 30, array(), true ) );
		$this->rooms->method( 'isBusy' )->willReturn( false );
		$this->examSessions->method( 'isRoomBusy' )->willReturn( true );
		$this->groupLessons->expects( self::never() )->method( 'setRoom' );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Кабинет занят другим занятием в это время.' );

		$this->service->assignToLesson( 10, 3 );
	}

	public function test_failed_assignment_rolls_the_transaction_back(): void {
		$db = $this->recordingDb();
		$this->groupLessons->method( 'find' )->willReturn( $this->lesson( '2026-05-20 09:00:00', '2026-05-20 09:45:00' ) );
		$this->rooms->method( 'find' )->willReturn( new RoomDTO( 3, 'A', 30, array(), true ) );
		$this->rooms->method( 'isBusy' )->willReturn( true );

		try {
			$this->service->assignToLesson( 10, 3 );
			self::fail( 'Ожидалось исключение.' );
		} catch ( \InvalidArgumentException ) {
			self::assertSame( array( 'START TRANSACTION', 'ROLLBACK' ), $db->log );
		}
	}

	public function test_unassigning_room_takes_no_lock(): void {
		$this->groupLessons->method( 'find' )->willReturn( $this->lesson( '2026-05-20 09:00:00', '2026-05-20 09:45:00' ) );
		$this->rooms->expects( self::never() )->method( 'lockForUpdate' );

		$this->service->assignToLesson( 10, null );
	}

	public function test_override_for_range_locks_room_once_and_skips_days_with_exam(): void {
		$db = $this->recordingDb();
		$this->rooms->method( 'find' )->willReturn( new RoomDTO( 3, 'A', 30, array(), true ) );
		$this->rooms->method( 'isBusy' )->willReturn( false );
		$this->rooms->expects( self::once() )->method( 'lockForUpdate' )->with( 3 );
		$this->groups->method( 'findById' )->willReturn( (object) array( 'subject_key' => 'inf' ) );
		$this->groupLessons->method( 'listByGroup' )->willReturn( array(
			$this->lessonAt( 10, '2026-05-05 09:00:00', 'group' ),
			$this->lessonAt( 11, '2026-05-06 09:00:00', 'group' ), // в это время в кабинете экзамен
		) );
		$this->examSessions->method( 'isRoomBusy' )->willReturnCallback(
			static fn ( int $room, string $start ): bool => str_starts_with( $start, '2026-05-06' )
		);
		$this->groupLessons->expects( self::once() )->method( 'setRoom' )->with( 10, 3 );

		$res = $this->service->overrideForRange( 7, 3, '2026-05-01', '2026-05-31' );

		self::assertSame( 1, $res['applied'] );
		self::assertSame( 1, $res['skipped'] );
		self::assertSame( array( 'START TRANSACTION', 'COMMIT' ), $db->log );
	}

	private function lessonAt( int $id, string $start, string $kind ): GroupLessonDTO {
		return new GroupLessonDTO(
			id: $id, groupId: 7, lessonId: 1, position: 0, workIdsSnapshot: null, extraWorkIds: array(),
			scheduledAt: $start, endsAt: null, isPinned: false, teacherUserId: null, visibility: 'open',
			openedAt: null, homeworkDueAt: null, allowLate: true, recordingUrl: null,
			createdByUserId: null, updatedByUserId: null, kind: LessonKind::fromValueOrDefault( $kind ),
		);
	}

	private function lesson( string $start, string $end ): GroupLessonDTO {
		return new GroupLessonDTO(
			id: 10, groupId: 7, lessonId: 1, position: 0, workIdsSnapshot: null, extraWorkIds: array(),
			scheduledAt: $start, endsAt: $end, isPinned: false, teacherUserId: null, visibility: 'open',
			openedAt: null, homeworkDueAt: null, allowLate: true, recordingUrl: null,
			createdByUserId: null, updatedByUserId: null,
		);
	}
}
