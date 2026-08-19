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

		self::assertSame( 'open', $this->service->effectiveVisibility( $row ) );
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
