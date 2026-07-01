<?php

declare( strict_types=1 );

namespace Unit\Services\Course;

use Inc\DTO\Course\GroupLessonDTO;
use Inc\DTO\Course\RoomDTO;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\RoomRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Services\Course\RoomAssignmentService;
use PHPUnit\Framework\TestCase;

class RoomAssignmentServiceTest extends TestCase {

	private $rooms;
	private $groups;
	private $groupLessons;
	private $records;
	private RoomAssignmentService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->rooms        = $this->createMock( RoomRepository::class );
		$this->groups       = $this->createMock( GroupsRepository::class );
		$this->groupLessons = $this->createMock( GroupLessonRepository::class );
		$this->records      = $this->createMock( StudentRecordRepository::class );
		$this->service      = new RoomAssignmentService( $this->rooms, $this->groups, $this->groupLessons, $this->records );
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

	private function lessonAt( int $id, string $start, string $kind ): GroupLessonDTO {
		return new GroupLessonDTO(
			id: $id, groupId: 7, lessonId: 1, position: 0, workIdsSnapshot: null, extraWorkIds: array(),
			scheduledAt: $start, endsAt: null, isPinned: false, teacherUserId: null, visibility: 'open',
			openedAt: null, homeworkDueAt: null, allowLate: true, recordingUrl: null,
			createdByUserId: null, updatedByUserId: null, kind: $kind,
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
