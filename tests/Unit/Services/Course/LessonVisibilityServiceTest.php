<?php

declare( strict_types=1 );

namespace Unit\Services\Course;

use Inc\Contracts\ClockInterface;
use Inc\Contracts\LogEventDispatcherInterface;
use Inc\DTO\Course\GroupLessonDTO;
use Inc\DTO\Course\LessonDTO;
use Inc\DTO\Course\StepDTO;
use Inc\Enums\Log\LogEvent;
use Inc\Enums\Course\StepType;
use Inc\Managers\Course\LessonManager;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Services\Course\LessonVisibilityService;
use PHPUnit\Framework\TestCase;

class LessonVisibilityServiceTest extends TestCase {

	private GroupLessonRepository&\PHPUnit\Framework\MockObject\MockObject $groupLessons;
	private LessonManager&\PHPUnit\Framework\MockObject\MockObject $lessonManager;
	private LogEventDispatcherInterface&\PHPUnit\Framework\MockObject\MockObject $dispatcher;
	private ClockInterface&\PHPUnit\Framework\MockObject\MockObject $clock;
	private LessonVisibilityService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->groupLessons  = $this->createMock( GroupLessonRepository::class );
		$this->lessonManager = $this->createMock( LessonManager::class );
		$this->dispatcher    = $this->createMock( LogEventDispatcherInterface::class );
		$this->clock         = $this->createMock( ClockInterface::class );
		$this->clock->method( 'now' )->willReturn( '2024-06-01 12:00:00' );
		$this->service       = new LessonVisibilityService(
			$this->groupLessons,
			$this->lessonManager,
			$this->dispatcher,
			$this->clock,
		);
	}

	public function test_refresh_from_lesson_overwrites_snapshot(): void {
		$row    = $this->makeRow( workIdsSnapshot: [ 10 ] );
		$lesson = $this->makeLesson( workIds: [ 10, 30 ] );
		$this->groupLessons->method( 'find' )->willReturn( $row );
		$this->lessonManager->method( 'get' )->willReturn( $lesson );

		$this->groupLessons->expects( self::once() )
			->method( 'setWorkIdsSnapshot' )
			->with( 42, [ 10, 30 ] );

		$this->service->refreshFromLesson( 42, 1 );
	}

	public function test_refresh_records_new_work_before_updating_snapshot(): void {
		$row = $this->makeRow( workIdsSnapshot: [ 10 ] );
		$lesson = $this->makeLesson( workIds: [ 10, 30 ] );
		$this->groupLessons->method( 'find' )->willReturn( $row );
		$this->lessonManager->method( 'get' )->willReturn( $lesson );
		$events = array();
		$this->groupLessons->method( 'setWorkIdsSnapshot' )->willReturnCallback( static function () use ( &$events ): bool {
			$events[] = 'save';
			return true;
		} );
		$snapshots = $this->createMock( \Inc\Services\Course\RankingAssignmentSnapshotService::class );
		$snapshots->method( 'synchronized' )->willReturnCallback( static function ( callable $operation ): void { $operation(); } );
		$snapshots->expects( self::once() )->method( 'markPending' )->with( 42, [ 30 ], \Inc\Enums\Course\RankingAssignmentOrigin::Lesson )->willReturnCallback( static function () use ( &$events ): void { $events[] = 'issue'; } );
		$service = new LessonVisibilityService( $this->groupLessons, $this->lessonManager, $this->dispatcher, $this->clock, $snapshots, true );
		$service->refreshFromLesson( 42, 1 );
		self::assertSame( [ 'issue', 'save' ], $events );
	}

	public function test_refresh_throws_when_snapshot_write_fails(): void {
		$row = $this->makeRow( workIdsSnapshot: [ 10 ] );
		$this->groupLessons->method( 'find' )->willReturn( $row );
		$this->lessonManager->method( 'get' )->willReturn( $this->makeLesson( workIds: [ 10, 30 ] ) );
		$this->groupLessons->method( 'setWorkIdsSnapshot' )->willReturn( false );
		$this->dispatcher->expects( self::never() )->method( 'dispatch' );
		$snapshots = $this->createStub( \Inc\Services\Course\RankingAssignmentSnapshotService::class );
		$snapshots->method( 'synchronized' )->willReturnCallback( static function ( callable $operation ): void { $operation(); } );
		$service = new LessonVisibilityService( $this->groupLessons, $this->lessonManager, $this->dispatcher, $this->clock, $snapshots, true );
		$this->expectException( \RuntimeException::class );
		$service->refreshFromLesson( 42, 1 );
	}

	public function test_refresh_of_open_lesson_does_not_invent_issue_time(): void {
		$row = GroupLessonDTO::fromArray( [
			'id' => 42, 'group_id' => 5, 'lesson_id' => 1, 'position' => 0,
			'visibility' => 'open', 'work_ids_snapshot' => '[10]', 'extra_work_ids' => '[]',
		] );
		$this->groupLessons->method( 'find' )->willReturn( $row );
		$this->lessonManager->method( 'get' )->willReturn( $this->makeLesson( workIds: [ 10, 30 ] ) );
		$this->groupLessons->method( 'setWorkIdsSnapshot' )->willReturn( true );
		$snapshots = $this->createMock( \Inc\Services\Course\RankingAssignmentSnapshotService::class );
		$snapshots->method( 'synchronized' )->willReturnCallback( static function ( callable $operation ): void { $operation(); } );
		$snapshots->expects( self::once() )->method( 'markPublicationUnconfirmed' )->with( 42, [ 30 ], \Inc\Enums\Course\RankingAssignmentOrigin::Lesson );
		$snapshots->expects( self::never() )->method( 'capture' );
		$service = new LessonVisibilityService( $this->groupLessons, $this->lessonManager, $this->dispatcher, $this->clock, $snapshots, true );
		$service->refreshFromLesson( 42, 1 );
	}

	public function test_live_work_is_marked_before_saving_and_confirmed_afterwards(): void {
		$row = GroupLessonDTO::fromArray( [ 'id' => 42, 'group_id' => 5, 'lesson_id' => 1, 'position' => 0, 'visibility' => 'open', 'work_ids_snapshot' => '[10]', 'extra_work_ids' => '[]' ] );
		$old = $this->makeLesson( [ 10 ] );
		$new = $this->makeLesson( [ 10, 30 ] );
		$this->lessonManager->method( 'get' )->willReturn( $old, $new, $new );
		$this->groupLessons->method( 'listByLessonId' )->willReturn( [ $row ] );
		$this->groupLessons->method( 'find' )->willReturn( $row );
		$this->groupLessons->method( 'setExtraWorkIds' )->willReturn( true );
		$events = array();
		$this->lessonManager->method( 'update' )->willReturnCallback( static function () use ( &$events ): bool { $events[] = 'save'; return true; } );
		$snapshots = $this->createMock( \Inc\Services\Course\RankingAssignmentSnapshotService::class );
		$snapshots->method( 'synchronizedWrite' )->willReturnCallback( static function ( callable $prepare, callable $write ): void { $prepare(); $write(); } );
		$snapshots->method( 'synchronized' )->willReturnCallback( static function ( callable $operation ): void { $operation(); } );
		$snapshots->expects( self::once() )->method( 'markPublicationUnconfirmed' )->with( 42, [ 30 ], \Inc\Enums\Course\RankingAssignmentOrigin::Lesson )->willReturnCallback( static function () use ( &$events ): void { $events[] = 'prepare'; } );
		$snapshots->expects( self::once() )->method( 'capture' )->with( 42, [ 30 ], null, \Inc\Enums\Course\RankingAssignmentOrigin::Lesson )->willReturnCallback( static function () use ( &$events ): void { $events[] = 'confirm'; } );
		$publication = $this->createStub( \Inc\Services\Course\RankingLessonPublicationService::class );
		$publication->method( 'samePeriod' )->willReturn( true );
		$service = new LessonVisibilityService( $this->groupLessons, $this->lessonManager, $this->dispatcher, $this->clock, $snapshots, true, $publication, $this->createStub( \Inc\Services\Course\RankingScheduleHistoryService::class ) );
		self::assertTrue( $service->saveStepsWithHistory( 1, $new ) );
		self::assertSame( [ 'prepare', 'save', 'confirm' ], $events );
	}

	public function test_failed_step_write_never_confirms_issue(): void {
		$row = GroupLessonDTO::fromArray( [ 'id' => 42, 'group_id' => 5, 'lesson_id' => 1, 'position' => 0, 'visibility' => 'open', 'work_ids_snapshot' => '[10]' ] );
		$this->lessonManager->method( 'get' )->willReturn( $this->makeLesson( [ 10 ] ) );
		$this->lessonManager->method( 'update' )->willReturn( false );
		$this->groupLessons->method( 'listByLessonId' )->willReturn( [ $row ] );
		$this->groupLessons->expects( self::never() )->method( 'setExtraWorkIds' );
		$snapshots = $this->createMock( \Inc\Services\Course\RankingAssignmentSnapshotService::class );
		$snapshots->method( 'synchronizedWrite' )->willReturnCallback( static function ( callable $prepare, callable $write ): void { $prepare(); $write(); } );
		$snapshots->expects( self::once() )->method( 'markPublicationUnconfirmed' );
		$snapshots->expects( self::never() )->method( 'capture' );
		$publication = $this->createStub( \Inc\Services\Course\RankingLessonPublicationService::class );
		$service = new LessonVisibilityService( $this->groupLessons, $this->lessonManager, $this->dispatcher, $this->clock, $snapshots, true, $publication, $this->createStub( \Inc\Services\Course\RankingScheduleHistoryService::class ) );
		self::assertFalse( $service->saveStepsWithHistory( 1, $this->makeLesson( [ 10, 30 ] ) ) );
	}

	public function test_live_steps_for_open_individual_lesson_are_recorded_without_group_snapshot(): void {
		$row = GroupLessonDTO::fromArray( [ 'id' => 42, 'group_id' => 5, 'lesson_id' => 1, 'position' => 0, 'kind' => 'individual', 'visibility' => 'open', 'work_ids_snapshot' => null ] );
		$old = $this->makeLesson( [ 10 ] );
		$new = $this->makeLesson( [ 10, 30 ] );
		$this->lessonManager->method( 'get' )->willReturn( $old, $new, $new );
		$this->lessonManager->method( 'update' )->willReturn( true );
		$this->groupLessons->method( 'listByLessonId' )->willReturn( [ $row ] );
		$this->groupLessons->method( 'find' )->willReturn( $row );
		$this->groupLessons->method( 'setExtraWorkIds' )->willReturn( true );
		$snapshots = $this->createMock( \Inc\Services\Course\RankingAssignmentSnapshotService::class );
		$snapshots->method( 'synchronizedWrite' )->willReturnCallback( static function ( callable $prepare, callable $write ): void { $prepare(); $write(); } );
		$snapshots->method( 'synchronized' )->willReturnCallback( static function ( callable $operation ): void { $operation(); } );
		$snapshots->expects( self::once() )->method( 'markPublicationUnconfirmed' )->with( 42, [ 30 ], \Inc\Enums\Course\RankingAssignmentOrigin::Lesson );
		$snapshots->expects( self::once() )->method( 'capture' )->with( 42, [ 10, 30 ], null, \Inc\Enums\Course\RankingAssignmentOrigin::Lesson );
		$publication = $this->createStub( \Inc\Services\Course\RankingLessonPublicationService::class );
		$publication->method( 'samePeriod' )->willReturn( true );
		$service = new LessonVisibilityService( $this->groupLessons, $this->lessonManager, $this->dispatcher, $this->clock, $snapshots, true, $publication, $this->createStub( \Inc\Services\Course\RankingScheduleHistoryService::class ) );
		self::assertTrue( $service->saveStepsWithHistory( 1, $new ) );
	}

	public function test_step_write_across_period_boundary_remains_unconfirmed(): void {
		$row = GroupLessonDTO::fromArray( [ 'id' => 42, 'group_id' => 5, 'lesson_id' => 1, 'position' => 0, 'visibility' => 'open', 'work_ids_snapshot' => '[10]' ] );
		$old = $this->makeLesson( [ 10 ] );
		$new = $this->makeLesson( [ 10, 30 ] );
		$this->lessonManager->method( 'get' )->willReturn( $old, $new, $new );
		$this->lessonManager->method( 'update' )->willReturn( true );
		$this->groupLessons->method( 'listByLessonId' )->willReturn( [ $row ] );
		$this->groupLessons->method( 'find' )->willReturn( $row );
		$this->groupLessons->method( 'setExtraWorkIds' )->willReturn( true );
		$snapshots = $this->createMock( \Inc\Services\Course\RankingAssignmentSnapshotService::class );
		$snapshots->method( 'synchronizedWrite' )->willReturnCallback( static function ( callable $prepare, callable $write ): void { $prepare(); $write(); } );
		$snapshots->method( 'synchronized' )->willReturnCallback( static function ( callable $operation ): void { $operation(); } );
		$snapshots->expects( self::exactly( 2 ) )->method( 'markPublicationUnconfirmed' );
		$snapshots->expects( self::never() )->method( 'capture' );
		$publication = $this->createStub( \Inc\Services\Course\RankingLessonPublicationService::class );
		$publication->method( 'samePeriod' )->willReturn( false );
		$service = new LessonVisibilityService( $this->groupLessons, $this->lessonManager, $this->dispatcher, $this->clock, $snapshots, true, $publication, $this->createStub( \Inc\Services\Course\RankingScheduleHistoryService::class ) );
		self::assertTrue( $service->saveStepsWithHistory( 1, $new ) );
	}

	public function test_refresh_dispatches_schedule_changed_event(): void {
		$row    = $this->makeRow( workIdsSnapshot: [ 10 ] );
		$lesson = $this->makeLesson( workIds: [] );
		$this->groupLessons->method( 'find' )->willReturn( $row );
		$this->lessonManager->method( 'get' )->willReturn( $lesson );

		$this->dispatcher->expects( self::once() )
			->method( 'dispatch' )
			->with( LogEvent::ScheduleChanged, self::anything() );

		$this->service->refreshFromLesson( 42, 1 );
	}

	public function test_refresh_throws_when_lesson_not_found(): void {
		$row = $this->makeRow( workIdsSnapshot: null );
		$this->groupLessons->method( 'find' )->willReturn( $row );
		$this->lessonManager->method( 'get' )->willReturn( null );

		$this->expectException( \InvalidArgumentException::class );
		$this->service->refreshFromLesson( 42, 1 );
	}

	public function test_sync_adds_new_lesson_works_to_open_occurrences(): void {
		$row    = $this->makeRow( workIdsSnapshot: [ 10 ], extraWorkIds: [] );
		$lesson = $this->makeLesson( workIds: [ 10, 20 ] ); // 20 added after publish
		$this->lessonManager->method( 'get' )->willReturn( $lesson );
		$this->groupLessons->method( 'listByLessonId' )->with( 1 )->willReturn( [ $row ] );

		$this->groupLessons->expects( self::once() )
			->method( 'setExtraWorkIds' )
			->with( 42, [ 20 ] );

		$this->service->syncExtraWorksForOpenOccurrences( 1 );
	}

	public function test_sync_records_new_extra_work_on_open_lesson(): void {
		$row = $this->makeRow( workIdsSnapshot: [ 10 ], extraWorkIds: [] );
		$lesson = $this->makeLesson( workIds: [ 10, 20 ] );
		$this->lessonManager->method( 'get' )->willReturn( $lesson );
		$this->groupLessons->method( 'listByLessonId' )->willReturn( [ $row ] );
		$this->groupLessons->method( 'find' )->willReturn( $row );
		$this->groupLessons->method( 'setExtraWorkIds' )->willReturn( true );
		$snapshots = $this->createMock( \Inc\Services\Course\RankingAssignmentSnapshotService::class );
		$snapshots->method( 'synchronized' )->willReturnCallback( static function ( callable $fn ): void { $fn(); } );
		$snapshots->expects( self::once() )->method( 'markPending' )->with( 42, [ 20 ], \Inc\Enums\Course\RankingAssignmentOrigin::Lesson );
		$service = new LessonVisibilityService( $this->groupLessons, $this->lessonManager, $this->dispatcher, $this->clock, $snapshots, true );
		$service->syncExtraWorksForOpenOccurrences( 1 );
	}

	public function test_sync_skips_unpublished_occurrences(): void {
		$row    = $this->makeRow( workIdsSnapshot: null, extraWorkIds: [] );
		$lesson = $this->makeLesson( workIds: [ 10, 20 ] );
		$this->lessonManager->method( 'get' )->willReturn( $lesson );
		$this->groupLessons->method( 'listByLessonId' )->with( 1 )->willReturn( [ $row ] );

		$this->groupLessons->expects( self::never() )->method( 'setExtraWorkIds' );

		$this->service->syncExtraWorksForOpenOccurrences( 1 );
	}

	public function test_sync_is_noop_when_nothing_missing(): void {
		$row    = $this->makeRow( workIdsSnapshot: [ 10, 20 ], extraWorkIds: [] );
		$lesson = $this->makeLesson( workIds: [ 10, 20 ] );
		$this->lessonManager->method( 'get' )->willReturn( $lesson );
		$this->groupLessons->method( 'listByLessonId' )->with( 1 )->willReturn( [ $row ] );

		$this->groupLessons->expects( self::never() )->method( 'setExtraWorkIds' );

		$this->service->syncExtraWorksForOpenOccurrences( 1 );
	}

	/**
	 * Этап 4 (Tasks.md): урок вне расписания открывается ученикам ровно в назначенное
	 * время БЕЗ отдельной ветки — effectiveVisibility() решает только по scheduledAt/
	 * visibility, дню недели/наличию слота в расписании группы вообще не известно.
	 * Тест, а не предположение.
	 */
	public function test_effective_visibility_opens_off_schedule_lesson_once_time_arrives(): void {
		$row = $this->makeRow( workIdsSnapshot: [ 10 ] );
		$row = new GroupLessonDTO(
			id: $row->id, groupId: $row->groupId, lessonId: $row->lessonId, position: $row->position,
			workIdsSnapshot: $row->workIdsSnapshot, extraWorkIds: $row->extraWorkIds,
			scheduledAt: '2024-06-01 09:00:00', // до clock->now() (12:00) — уже наступило
			endsAt: null, isPinned: true, teacherUserId: null, visibility: 'hidden',
			openedAt: null, homeworkDueAt: null, allowLate: true, recordingUrl: null,
			createdByUserId: null, updatedByUserId: null,
		);
		$this->lessonManager->method( 'isPublished' )->willReturn( true );

		self::assertSame( 'open', $this->service->effectiveVisibility( $row ) );
	}

	/** Урок курса — черновик: дата наступила, но ученикам он сам не открывается. */
	public function test_effective_visibility_keeps_draft_lesson_hidden_after_time(): void {
		$row = new GroupLessonDTO(
			id: 1, groupId: 5, lessonId: 10, position: 0, workIdsSnapshot: null, extraWorkIds: array(),
			scheduledAt: '2024-06-01 09:00:00', endsAt: null, isPinned: false, teacherUserId: null,
			visibility: 'hidden', openedAt: null, homeworkDueAt: null, allowLate: true, recordingUrl: null,
			createdByUserId: null, updatedByUserId: null,
		);
		$this->lessonManager->method( 'isPublished' )->with( 10 )->willReturn( false );

		self::assertSame( 'hidden', $this->service->effectiveVisibility( $row ) );
	}

	/** Та же строка, но время ещё не наступило — остаётся hidden. */
	public function test_effective_visibility_keeps_off_schedule_lesson_hidden_before_time(): void {
		$row = $this->makeRow( workIdsSnapshot: [ 10 ] );
		$row = new GroupLessonDTO(
			id: $row->id, groupId: $row->groupId, lessonId: $row->lessonId, position: $row->position,
			workIdsSnapshot: $row->workIdsSnapshot, extraWorkIds: $row->extraWorkIds,
			scheduledAt: '2024-06-01 15:00:00', // после clock->now() (12:00) — ещё не наступило
			endsAt: null, isPinned: true, teacherUserId: null, visibility: 'hidden',
			openedAt: null, homeworkDueAt: null, allowLate: true, recordingUrl: null,
			createdByUserId: null, updatedByUserId: null,
		);

		self::assertSame( 'hidden', $this->service->effectiveVisibility( $row ) );
	}

	// --- helpers ---

	private function makeRow( ?array $workIdsSnapshot, array $extraWorkIds = [] ): GroupLessonDTO {
		return new GroupLessonDTO(
			id              : 42,
			groupId         : 5,
			lessonId        : 1,
			position        : 0,
			workIdsSnapshot : $workIdsSnapshot,
			extraWorkIds    : $extraWorkIds,
			scheduledAt     : null,
			endsAt          : null,
			isPinned        : false,
			teacherUserId   : null,
			visibility      : 'hidden',
			openedAt        : null,
			homeworkDueAt   : null,
			allowLate       : true,
			recordingUrl    : null,
			createdByUserId : null,
			updatedByUserId : null,
		);
	}

	private function makeLesson( array $workIds ): LessonDTO {
		$steps = array_map(
			static fn( int $id ): StepDTO => new StepDTO( 'w' . $id, StepType::Work, array( 'ref' => $id ) ),
			$workIds
		);

		return new LessonDTO(
			id        : 1,
			subjectKey: 'inf',
			topic     : 'Test',
			steps     : $steps,
			authorId  : 1,
			status    : 'publish',
		);
	}
}
