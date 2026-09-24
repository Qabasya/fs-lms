<?php

declare( strict_types=1 );

namespace Unit\Services\Group;

use Inc\Contracts\ClockInterface;
use Inc\Contracts\LogEventDispatcherInterface;
use Inc\DTO\Course\ScheduleReflowResultDTO;
use Inc\Enums\Log\LogEvent;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Services\Course\RoomAvailabilityService;
use Inc\Services\Group\ScheduleEventPublisher;
use Inc\Services\Group\ScheduleReflowService;
use Inc\Services\Group\SessionCalendarService;
use PHPUnit\Framework\TestCase;
use Tests\Support\GroupLessonFixtures;

/**
 * Даты КТП: постановка на дату, закрепление и раскладка.
 */
class ScheduleReflowServiceTest extends TestCase {

	use GroupLessonFixtures;

	private GroupLessonRepository&\PHPUnit\Framework\MockObject\MockObject $groupLessons;
	private GroupsRepository&\PHPUnit\Framework\MockObject\MockObject $groups;
	private SessionCalendarService&\PHPUnit\Framework\MockObject\MockObject $calendar;
	private RoomAvailabilityService&\PHPUnit\Framework\MockObject\MockObject $roomAvailability;
	private LogEventDispatcherInterface&\PHPUnit\Framework\MockObject\MockObject $dispatcher;
	private ScheduleReflowService $service;
	private ClockInterface $clock;

	protected function setUp(): void {
		parent::setUp();
		$this->groupLessons     = $this->createMock( GroupLessonRepository::class );
		$this->groups           = $this->createMock( GroupsRepository::class );
		$this->calendar         = $this->createMock( SessionCalendarService::class );
		$this->roomAvailability = $this->createMock( RoomAvailabilityService::class );
		$this->dispatcher       = $this->createMock( LogEventDispatcherInterface::class );
		$this->clock            = $this->createStub( ClockInterface::class );
		$this->clock->method( 'now' )->willReturn( '2026-09-01 00:00:00' );
		// Широкий период по умолчанию — большинство тестов проверяют не границы периода,
		// а логику вытеснения/ends_at; тест assertWithinPeriod() переопределяет явно.
		$this->calendar->method( 'periodMeta' )->willReturn( array(
			'period'      => array( 'start_date' => '2026-01-01', 'end_date' => '2026-12-31' ),
			'holidays'    => array(),
			'lessonDays'  => array(),
			'lessonTimes' => array(),
		) );

		$this->service = new ScheduleReflowService(
			$this->groupLessons,
			$this->groups,
			$this->calendar,
			$this->roomAvailability,
			new ScheduleEventPublisher( $this->dispatcher ),
			$this->clock,
		);
	}

	public function test_schedule_updates_row_and_dispatches_event(): void {
		$this->groupLessons->method( 'find' )->willReturn( $this->makeRow() );

		$this->groupLessons->expects( self::once() )
			->method( 'updateSchedule' )
			->with( 42, '2024-06-01 10:00:00', 7 );
		$this->dispatcher->expects( self::once() )
			->method( 'dispatch' )
			->with( LogEvent::ScheduleChanged, self::anything() );

		$this->service->schedule( 42, '2024-06-01 10:00:00', 7, 99 );
	}

	public function test_schedule_throws_when_row_not_found(): void {
		$this->groupLessons->method( 'find' )->willReturn( null );

		$this->expectException( \InvalidArgumentException::class );
		$this->service->schedule( 99, null, null, 1 );
	}

	public function test_pin_marks_row_and_dispatches_event(): void {
		$this->groupLessons->method( 'find' )->willReturn( $this->makeRow() );

		$this->groupLessons->expects( self::once() )->method( 'setPinned' )->with( 42, true );
		$this->dispatcher->expects( self::once() )
			->method( 'dispatch' )
			->with( LogEvent::ScheduleChanged, self::anything() );

		$this->service->pin( 42, true, 99 );
	}

	public function test_unschedule_returns_affected_count_and_dispatches_event(): void {
		$this->groupLessons->method( 'unscheduleAll' )->with( 5 )->willReturn( 4 );
		$this->dispatcher->expects( self::once() )
			->method( 'dispatch' )
			->with( LogEvent::ScheduleChanged, self::anything() );

		self::assertSame( 4, $this->service->unschedule( 5, 99 ) );
	}

	public function test_reflow_returns_result_from_calendar(): void {
		$expected = new ScheduleReflowResultDTO( conflicts: 3, slots: 10, consuming: 12, unplaced: 2 );
		$this->calendar->method( 'reflow' )->with( 5 )->willReturn( $expected );

		self::assertSame( $expected, $this->service->reflow( 5, 99 ) );
	}

	public function test_pin_to_date_blocks_on_room_conflict(): void {
		$this->groupLessons->method( 'find' )->willReturn( $this->makeRow( 42, 'group', 7 ) );
		$this->groups->method( 'findById' )->willReturn( new \stdClass() );
		$this->roomAvailability->method( 'isFree' )->willReturn( false ); // кабинет занят
		$this->groupLessons->expects( self::never() )->method( 'updateSchedule' );

		$this->expectException( \InvalidArgumentException::class );
		$this->service->pinToDate( 42, '2026-05-20 15:00:00', 1 );
	}

	public function test_pin_to_date_proceeds_when_room_free(): void {
		$this->groupLessons->method( 'find' )->willReturn( $this->makeRow( 42, 'group', 7 ) );
		$this->groups->method( 'findById' )->willReturn( new \stdClass() );
		$this->roomAvailability->method( 'isFree' )->willReturn( true );
		$this->groupLessons->expects( self::once() )->method( 'updateSchedule' );

		$this->service->pinToDate( 42, '2026-05-20 15:00:00', 1 );
	}

	/** T12.5: room-check исключает занятия СВОЕЙ группы — две темы в один день/кабинет не конфликт. */
	public function test_pin_to_date_excludes_own_group_from_room_conflict_check(): void {
		$row = $this->makeRow( 42, 'group', 7 ); // groupId: 5 (см. makeRow())
		$this->groupLessons->method( 'find' )->willReturn( $row );
		$this->groups->method( 'findById' )->willReturn( new \stdClass() );
		$this->roomAvailability->expects( self::once() )->method( 'isFree' )
			->with( 7, '2026-05-20 15:00:00', self::anything(), 42, $row->groupId )
			->willReturn( true );

		$this->service->pinToDate( 42, '2026-05-20 15:00:00', 1 );
	}

	/* ── Этап 3: строгая замена, вытесняющая занятую дату ────────────────── */

	/** Строка с датой, статусом и (опц.) continuedFromId — для сценариев вытеснения. */
	private function rowWithDate( int $id, string $scheduledAt, string $status = 'scheduled', ?int $continuedFromId = null ): \Inc\DTO\Course\GroupLessonDTO {
		return new \Inc\DTO\Course\GroupLessonDTO(
			id: $id, groupId: 5, lessonId: 10, position: 0, workIdsSnapshot: null, extraWorkIds: array(),
			scheduledAt: $scheduledAt, endsAt: null, isPinned: false, teacherUserId: null,
			visibility: 'hidden', openedAt: null, homeworkDueAt: null, allowLate: true, recordingUrl: null,
			createdByUserId: null, updatedByUserId: null, status: $status, continuedFromId: $continuedFromId,
		);
	}

	/**
	 * Регресс-тест на зонд из разбора (Tasks.md): перетаскивание урока на дату
	 * 2-го слота больше не даёт двух уроков на одну дату — и не сдвигает
	 * остальные строки (никакого calendar->reflow() внутри pinToDate()).
	 */
	public function test_pin_to_date_evicts_row_on_same_day_without_cascading_others(): void {
		$this->groupLessons->method( 'find' )->willReturn( $this->makeRow( 5, 'group' ) ); // groupId: 5
		$this->groups->method( 'findById' )->willReturn( new \stdClass() );
		$this->roomAvailability->method( 'isFree' )->willReturn( true );

		$occupant = $this->rowWithDate( 2, '2026-09-03 10:00:00' );
		$this->groupLessons->method( 'listByGroupAndDay' )->with( 5, '2026-09-03' )->willReturn( array( $occupant ) );

		$this->groupLessons->expects( self::once() )->method( 'updateSchedule' )->with( 5, '2026-09-03 10:00:00', null, self::anything() );
		$this->groupLessons->expects( self::once() )->method( 'clearSchedule' )->with( 2 );
		$this->calendar->expects( self::never() )->method( 'reflow' );

		$this->service->pinToDate( 5, '2026-09-03 10:00:00', 1 );
	}

	/** Дата свободна — никого вытеснять не нужно, clearSchedule() не зовётся. */
	public function test_pin_to_date_does_not_evict_when_day_is_free(): void {
		$this->groupLessons->method( 'find' )->willReturn( $this->makeRow( 42, 'group' ) );
		$this->groups->method( 'findById' )->willReturn( new \stdClass() );
		$this->roomAvailability->method( 'isFree' )->willReturn( true );
		$this->groupLessons->method( 'listByGroupAndDay' )->willReturn( array() );

		$this->groupLessons->expects( self::never() )->method( 'clearSchedule' );

		$this->service->pinToDate( 42, '2026-05-20 15:00:00', 1 );
	}

	/** Проведённое занятие (held) на дате — drop отклоняется, ничего не меняется. */
	public function test_pin_to_date_rejects_when_held_lesson_occupies_the_day(): void {
		$this->groupLessons->method( 'find' )->willReturn( $this->makeRow( 5, 'group' ) );
		$this->groups->method( 'findById' )->willReturn( new \stdClass() );
		$this->roomAvailability->method( 'isFree' )->willReturn( true );

		$held = $this->rowWithDate( 2, '2026-09-03 10:00:00', 'held' );
		$this->groupLessons->method( 'listByGroupAndDay' )->willReturn( array( $held ) );

		$this->groupLessons->expects( self::never() )->method( 'updateSchedule' );
		$this->groupLessons->expects( self::never() )->method( 'clearSchedule' );

		$this->expectException( \InvalidArgumentException::class );
		$this->service->pinToDate( 5, '2026-09-03 10:00:00', 1 );
	}

	/** Индивидуальные занятия на этой дате — не вытесняются, отдельный трек. */
	public function test_pin_to_date_ignores_individual_lessons_on_the_same_day(): void {
		$this->groupLessons->method( 'find' )->willReturn( $this->makeRow( 5, 'group' ) );
		$this->groups->method( 'findById' )->willReturn( new \stdClass() );
		$this->roomAvailability->method( 'isFree' )->willReturn( true );

		$individual = $this->makeRow( 2, 'individual' );
		$this->groupLessons->method( 'listByGroupAndDay' )->willReturn( array( $individual ) );

		$this->groupLessons->expects( self::never() )->method( 'clearSchedule' );

		$this->service->pinToDate( 5, '2026-09-03 10:00:00', 1 );
	}

	/** T12.6: вытеснение исходной части темы уводит в пул и её продолжение — обе части вместе. */
	public function test_pin_to_date_evicts_continuation_together_with_origin(): void {
		$this->groupLessons->method( 'find' )->willReturn( $this->makeRow( 5, 'group' ) );
		$this->groups->method( 'findById' )->willReturn( new \stdClass() );
		$this->roomAvailability->method( 'isFree' )->willReturn( true );

		$origin       = $this->rowWithDate( 2, '2026-09-03 10:00:00' );
		$continuation = $this->rowWithDate( 3, '2026-09-10 10:00:00', 'scheduled', 2 ); // continuedFromId: 2
		$this->groupLessons->method( 'listByGroupAndDay' )->with( 5, '2026-09-03' )->willReturn( array( $origin ) );
		$this->groupLessons->method( 'listByGroup' )->with( 5 )->willReturn( array( $origin, $continuation ) );

		$cleared = array();
		$this->groupLessons->method( 'clearSchedule' )->willReturnCallback( function ( int $id ) use ( &$cleared ) {
			$cleared[] = $id;
			return true;
		} );

		$this->service->pinToDate( 5, '2026-09-03 10:00:00', 1 );

		self::assertSame( array( 2, 3 ), $cleared );
	}

	/** ends_at после pinToDate() — из слота периода, а не NULL (побочный баг разбора). */
	public function test_pin_to_date_resolves_ends_at_from_generated_slot(): void {
		$this->groupLessons->method( 'find' )->willReturn( $this->makeRow( 42, 'group' ) );
		$this->groups->method( 'findById' )->willReturn( new \stdClass() );
		$this->roomAvailability->method( 'isFree' )->willReturn( true );
		$this->groupLessons->method( 'listByGroupAndDay' )->willReturn( array() );
		$this->calendar->method( 'generate' )->with( 5 )->willReturn( array(
			array( 'scheduled_at' => '2026-05-20 15:00:00', 'ends_at' => '2026-05-20 16:30:00', 'room' => 0 ),
		) );

		$this->groupLessons->expects( self::once() )->method( 'updateSchedule' )
			->with( 42, '2026-05-20 15:00:00', null, '2026-05-20 16:30:00' );

		$this->service->pinToDate( 42, '2026-05-20 15:00:00', 1 );
	}

	/* ── Этап 4: урок вне расписания ──────────────────────────────────────── */

	/** Явный ends_at (модалка урока вне расписания) — приоритет над авто-расчётом. */
	public function test_pin_to_date_uses_explicit_ends_at_when_given(): void {
		$this->groupLessons->method( 'find' )->willReturn( $this->makeRow( 42, 'group' ) );
		$this->groups->method( 'findById' )->willReturn( new \stdClass() );
		$this->roomAvailability->method( 'isFree' )->willReturn( true );
		$this->groupLessons->method( 'listByGroupAndDay' )->willReturn( array() );
		$this->calendar->expects( self::never() )->method( 'generate' ); // авто-расчёт не нужен

		$this->groupLessons->expects( self::once() )->method( 'updateSchedule' )
			->with( 42, '2026-05-20 11:00:00', null, '2026-05-20 13:00:00' );

		$this->service->pinToDate( 42, '2026-05-20 11:00:00', 1, '2026-05-20 13:00:00' );
	}

	/** Дата за пределами периода — отклоняется, ничего не пишется. */
	public function test_pin_to_date_rejects_date_outside_period(): void {
		$this->calendar = $this->createMock( SessionCalendarService::class );
		$this->calendar->method( 'periodMeta' )->willReturn( array(
			'period' => array( 'start_date' => '2026-09-01', 'end_date' => '2026-12-31' ),
			'holidays' => array(), 'lessonDays' => array(), 'lessonTimes' => array(),
		) );
		$service = new ScheduleReflowService(
			$this->groupLessons, $this->groups, $this->calendar, $this->roomAvailability,
			new ScheduleEventPublisher( $this->dispatcher ),
			$this->clock,
		);
		$this->groupLessons->method( 'find' )->willReturn( $this->makeRow( 42, 'group' ) );
		$this->groupLessons->expects( self::never() )->method( 'updateSchedule' );

		$this->expectException( \InvalidArgumentException::class );
		$service->pinToDate( 42, '2026-08-15 11:00:00', 1 );
	}

	/** День — выходной периода — отклоняется. */
	public function test_pin_to_date_rejects_holiday(): void {
		$this->calendar = $this->createMock( SessionCalendarService::class );
		$this->calendar->method( 'periodMeta' )->willReturn( array(
			'period' => array( 'start_date' => '2026-01-01', 'end_date' => '2026-12-31' ),
			'holidays' => array( '2026-11-04' ), 'lessonDays' => array(), 'lessonTimes' => array(),
		) );
		$service = new ScheduleReflowService(
			$this->groupLessons, $this->groups, $this->calendar, $this->roomAvailability,
			new ScheduleEventPublisher( $this->dispatcher ),
			$this->clock,
		);
		$this->groupLessons->method( 'find' )->willReturn( $this->makeRow( 42, 'group' ) );
		$this->groupLessons->expects( self::never() )->method( 'updateSchedule' );

		$this->expectException( \InvalidArgumentException::class );
		$service->pinToDate( 42, '2026-11-04 11:00:00', 1, '2026-11-04 12:00:00' );
	}

	/** Без периода у группы — отклоняется. */
	public function test_pin_to_date_rejects_when_group_has_no_period(): void {
		$this->calendar = $this->createMock( SessionCalendarService::class );
		$this->calendar->method( 'periodMeta' )->willReturn( array(
			'period' => null, 'holidays' => array(), 'lessonDays' => array(), 'lessonTimes' => array(),
		) );
		$service = new ScheduleReflowService(
			$this->groupLessons, $this->groups, $this->calendar, $this->roomAvailability,
			new ScheduleEventPublisher( $this->dispatcher ),
			$this->clock,
		);
		$this->groupLessons->method( 'find' )->willReturn( $this->makeRow( 42, 'group' ) );

		$this->expectException( \InvalidArgumentException::class );
		$service->pinToDate( 42, '2026-05-20 11:00:00', 1 );
	}

	/** Время закрепления пересекается с индивидуальным занятием того же дня — отклоняется. */
	public function test_pin_to_date_rejects_overlap_with_individual_lesson_same_day(): void {
		$this->groupLessons->method( 'find' )->willReturn( $this->makeRow( 5, 'group' ) );
		$this->groups->method( 'findById' )->willReturn( new \stdClass() );
		$this->roomAvailability->method( 'isFree' )->willReturn( true );

		$individual = new \Inc\DTO\Course\GroupLessonDTO(
			id: 9, groupId: 5, lessonId: 10, position: 0, workIdsSnapshot: null, extraWorkIds: array(),
			scheduledAt: '2026-09-03 11:30:00', endsAt: '2026-09-03 12:30:00', isPinned: true, teacherUserId: null,
			visibility: 'hidden', openedAt: null, homeworkDueAt: null, allowLate: true, recordingUrl: null,
			createdByUserId: null, updatedByUserId: null, kind: \Inc\Enums\Course\LessonKind::Individual,
		);
		$this->groupLessons->method( 'listByGroupAndDay' )->willReturn( array( $individual ) );
		$this->groupLessons->expects( self::never() )->method( 'updateSchedule' );

		$this->expectException( \InvalidArgumentException::class );
		// 11:00–12:00 пересекается с 11:30–12:30.
		$this->service->pinToDate( 5, '2026-09-03 11:00:00', 1, '2026-09-03 12:00:00' );
	}

	/** Не пересекается — окна впритык (12:00 == конец индивидуального) проходят без ошибки. */
	public function test_pin_to_date_allows_adjacent_individual_lesson_same_day(): void {
		$this->groupLessons->method( 'find' )->willReturn( $this->makeRow( 5, 'group' ) );
		$this->groups->method( 'findById' )->willReturn( new \stdClass() );
		$this->roomAvailability->method( 'isFree' )->willReturn( true );

		$individual = new \Inc\DTO\Course\GroupLessonDTO(
			id: 9, groupId: 5, lessonId: 10, position: 0, workIdsSnapshot: null, extraWorkIds: array(),
			scheduledAt: '2026-09-03 12:00:00', endsAt: '2026-09-03 13:00:00', isPinned: true, teacherUserId: null,
			visibility: 'hidden', openedAt: null, homeworkDueAt: null, allowLate: true, recordingUrl: null,
			createdByUserId: null, updatedByUserId: null, kind: \Inc\Enums\Course\LessonKind::Individual,
		);
		$this->groupLessons->method( 'listByGroupAndDay' )->willReturn( array( $individual ) );
		$this->groupLessons->expects( self::once() )->method( 'updateSchedule' );

		$this->service->pinToDate( 5, '2026-09-03 11:00:00', 1, '2026-09-03 12:00:00' );
	}

	/** День без слота (Этап 4): ends_at считается по длительности встречи того же дня недели. */
	public function test_pin_to_date_resolves_ends_at_from_meeting_duration_when_no_slot(): void {
		$this->groupLessons->method( 'find' )->willReturn( $this->makeRow( 42, 'group' ) );
		$this->groups->method( 'findById' )->willReturn( new \stdClass() );
		$this->groups->method( 'getMeetings' )->willReturn( array(
			array( 'weekday' => 3, 'time' => '16:00', 'duration_min' => 90 ), // среда
		) );
		$this->roomAvailability->method( 'isFree' )->willReturn( true );
		$this->groupLessons->method( 'listByGroupAndDay' )->willReturn( array() );
		$this->calendar->method( 'generate' )->willReturn( array() );

		// 2026-05-20 — среда (N=3), совпадает с meeting выше → 90 минут.
		$this->groupLessons->expects( self::once() )->method( 'updateSchedule' )
			->with( 42, '2026-05-20 11:00:00', null, '2026-05-20 12:30:00' );

		$this->service->pinToDate( 42, '2026-05-20 11:00:00', 1 );
	}

	/* ── Возврат темы в пул (drag из календаря в банк) + сдвиг хвоста ────── */

	/** Размещённая строка с полным окном занятия (дата/конец/кабинет). */
	private function placedRow(
		int $id,
		string $scheduledAt,
		string $status = 'scheduled',
		bool $isPinned = false,
		?int $roomId = null,
		?int $continuedFromId = null,
		string $kind = 'group',
		int $position = 0
	): \Inc\DTO\Course\GroupLessonDTO {
		return new \Inc\DTO\Course\GroupLessonDTO(
			id: $id, groupId: 5, lessonId: 10, position: $position, workIdsSnapshot: null, extraWorkIds: array(),
			scheduledAt: $scheduledAt, endsAt: substr( $scheduledAt, 0, 11 ) . '11:30:00', isPinned: $isPinned,
			teacherUserId: null, visibility: 'hidden', openedAt: null, homeworkDueAt: null, allowLate: true,
			recordingUrl: null, createdByUserId: null, updatedByUserId: null,
			kind: \Inc\Enums\Course\LessonKind::fromValueOrDefault( $kind ), status: $status,
			roomId: $roomId, continuedFromId: $continuedFromId,
		);
	}

	/** Снятая тема уходит в пул, следующие занимают освободившиеся окна по цепочке. */
	public function test_return_to_pool_shifts_tail_into_freed_windows(): void {
		$dragged = $this->placedRow( 1, '2026-09-03 10:00:00', roomId: 7 );
		$second  = $this->placedRow( 2, '2026-09-10 10:00:00', roomId: 8 );
		$third   = $this->placedRow( 3, '2026-09-17 10:00:00', roomId: 9 );

		$this->groupLessons->method( 'find' )->willReturn( $dragged );
		$this->groupLessons->method( 'listByGroup' )->with( 5 )->willReturn( array( $dragged, $second, $third ) );

		$this->groupLessons->expects( self::once() )->method( 'clearSchedule' )->with( 1 );

		$moves = array();
		$this->groupLessons->method( 'moveToSlot' )->willReturnCallback(
			function ( int $id, array $slot ) use ( &$moves ) {
				$moves[ $id ] = $slot;
				return true;
			}
		);

		self::assertSame( 2, $this->service->returnToPool( 1, 99 ) );
		self::assertSame( '2026-09-03 10:00:00', $moves[2]['scheduled_at'] );
		self::assertSame( '2026-09-03 11:30:00', $moves[2]['ends_at'] );
		// Кабинет принадлежит дню расписания и едет вместе с окном.
		self::assertSame( 7, $moves[2]['room_id'] );
		self::assertSame( '2026-09-10 10:00:00', $moves[3]['scheduled_at'] );
		self::assertSame( 8, $moves[3]['room_id'] );
	}

	/** Закреплённая вручную тема — якорь: хвост за ней не едет. */
	public function test_return_to_pool_stops_shift_at_pinned_anchor(): void {
		$dragged = $this->placedRow( 1, '2026-09-03 10:00:00' );
		$pinned  = $this->placedRow( 2, '2026-09-10 10:00:00', isPinned: true );
		$third   = $this->placedRow( 3, '2026-09-17 10:00:00' );

		$this->groupLessons->method( 'find' )->willReturn( $dragged );
		$this->groupLessons->method( 'listByGroup' )->willReturn( array( $dragged, $pinned, $third ) );

		$this->groupLessons->expects( self::once() )->method( 'clearSchedule' )->with( 1 );
		$this->groupLessons->expects( self::never() )->method( 'moveToSlot' );

		self::assertSame( 0, $this->service->returnToPool( 1, 99 ) );
	}

	/** Проведённое занятие — тоже якорь: сдвиг останавливается на нём. */
	public function test_return_to_pool_stops_shift_at_held_lesson(): void {
		$dragged = $this->placedRow( 1, '2026-09-03 10:00:00' );
		$held    = $this->placedRow( 2, '2026-09-10 10:00:00', 'held' );

		$this->groupLessons->method( 'find' )->willReturn( $dragged );
		$this->groupLessons->method( 'listByGroup' )->willReturn( array( $dragged, $held ) );

		$this->groupLessons->expects( self::never() )->method( 'moveToSlot' );

		self::assertSame( 0, $this->service->returnToPool( 1, 99 ) );
	}

	/** Индивидуальные занятия к последовательности курса не относятся — не двигаются. */
	public function test_return_to_pool_ignores_individual_lessons(): void {
		$dragged    = $this->placedRow( 1, '2026-09-03 10:00:00' );
		$individual = $this->placedRow( 2, '2026-09-04 10:00:00', kind: 'individual' );

		$this->groupLessons->method( 'find' )->willReturn( $dragged );
		$this->groupLessons->method( 'listByGroup' )->willReturn( array( $dragged, $individual ) );

		$this->groupLessons->expects( self::never() )->method( 'moveToSlot' );

		self::assertSame( 0, $this->service->returnToPool( 1, 99 ) );
	}

	/** T12.6: продолжение уходит в пул вместе с оригиналом, хвост едет на два окна. */
	public function test_return_to_pool_frees_continuation_window_too(): void {
		$origin       = $this->placedRow( 1, '2026-09-03 10:00:00' );
		$between      = $this->placedRow( 2, '2026-09-10 10:00:00' );
		$continuation = $this->placedRow( 3, '2026-09-17 10:00:00', continuedFromId: 1 );
		$tail         = $this->placedRow( 4, '2026-09-24 10:00:00' );

		$this->groupLessons->method( 'find' )->willReturn( $origin );
		$this->groupLessons->method( 'listByGroup' )->willReturn( array( $origin, $between, $continuation, $tail ) );

		$cleared = array();
		$this->groupLessons->method( 'clearSchedule' )->willReturnCallback(
			function ( int $id ) use ( &$cleared ) {
				$cleared[] = $id;
				return true;
			}
		);

		$moves = array();
		$this->groupLessons->method( 'moveToSlot' )->willReturnCallback(
			function ( int $id, array $slot ) use ( &$moves ) {
				$moves[ $id ] = $slot['scheduled_at'];
				return true;
			}
		);

		self::assertSame( 2, $this->service->returnToPool( 1, 99 ) );
		self::assertSame( array( 1, 3 ), $cleared );
		self::assertSame( '2026-09-03 10:00:00', $moves[2] );
		// Второе окно освободило продолжение — хвост поднимается на него, а не через него.
		self::assertSame( '2026-09-10 10:00:00', $moves[4] );
	}

	/** Проведённое занятие в пул не возвращается — это факт, а не план. */
	public function test_return_to_pool_rejects_held_row(): void {
		$this->groupLessons->method( 'find' )->willReturn( $this->placedRow( 1, '2026-09-03 10:00:00', 'held' ) );
		$this->groupLessons->expects( self::never() )->method( 'clearSchedule' );

		$this->expectException( \InvalidArgumentException::class );
		$this->service->returnToPool( 1, 99 );
	}

	/** Тема и так в пуле — no-op, событие не дёргаем. */
	public function test_return_to_pool_is_noop_for_row_without_date(): void {
		$this->groupLessons->method( 'find' )->willReturn( $this->makeRow( 1, 'group' ) );
		$this->groupLessons->expects( self::never() )->method( 'clearSchedule' );
		$this->dispatcher->expects( self::never() )->method( 'dispatch' );

		self::assertSame( 0, $this->service->returnToPool( 1, 99 ) );
	}

	// --- placeInserted(): вставка темы в распределённый план ---

	private function unplacedRow( int $id, int $position ): \Inc\DTO\Course\GroupLessonDTO {
		return new \Inc\DTO\Course\GroupLessonDTO(
			id: $id, groupId: 5, lessonId: 10, position: $position, workIdsSnapshot: null, extraWorkIds: array(),
			scheduledAt: null, endsAt: null, isPinned: false, teacherUserId: null, visibility: 'hidden',
			openedAt: null, homeworkDueAt: null, allowLate: true, recordingUrl: null,
			createdByUserId: null, updatedByUserId: null,
		);
	}

	/** @param string[] $dates */
	private function slots( array $dates ): array {
		return array_map(
			static fn( string $d ): array => array( 'scheduled_at' => $d . ' 10:00:00', 'ends_at' => $d . ' 11:30:00', 'room' => 0 ),
			$dates
		);
	}

	/** @var array<int, array{0:int, 1:string}> Вызовы moveToSlot(): [rowId, scheduled_at] */
	private array $moves = array();

	private function recordMoves(): void {
		$this->groupLessons->method( 'moveToSlot' )->willReturnCallback(
			function ( int $id, array $slot ): bool {
				$this->moves[] = array( $id, $slot['scheduled_at'] );
				return true;
			}
		);
	}

	/** Тема вставлена после 2-го урока: берёт окно 3-го, хвост едет на слот вперёд, прошедшее не трогается. */
	public function test_place_inserted_takes_next_window_and_pushes_tail(): void {
		$new = $this->unplacedRow( 99, 2 );
		$this->groupLessons->method( 'find' )->willReturn( $new );
		$this->groupLessons->method( 'listByGroup' )->willReturn( array(
			$this->placedRow( 1, '2026-08-27 10:00:00', position: 0 ),
			$this->placedRow( 2, '2026-09-03 10:00:00', position: 1 ),
			$new,
			$this->placedRow( 3, '2026-09-10 10:00:00', position: 3 ),
			$this->placedRow( 4, '2026-09-17 10:00:00', position: 4 ),
		) );
		$this->calendar->method( 'generate' )->willReturn(
			$this->slots( array( '2026-08-27', '2026-09-03', '2026-09-10', '2026-09-17', '2026-09-24', '2026-10-01' ) )
		);
		$this->recordMoves();
		$this->groupLessons->expects( self::never() )->method( 'clearSchedule' );

		self::assertSame( 2, $this->service->placeInserted( 99, 1 ) );
		self::assertSame(
			array(
				array( 99, '2026-09-10 10:00:00' ),
				array( 3, '2026-09-17 10:00:00' ),
				array( 4, '2026-09-24 10:00:00' ),
			),
			$this->moves
		);
	}

	/** Слоты периода закончились — последняя тема хвоста уходит в пул. */
	public function test_place_inserted_sends_last_to_pool_when_period_full(): void {
		$new = $this->unplacedRow( 99, 1 );
		$this->groupLessons->method( 'find' )->willReturn( $new );
		$this->groupLessons->method( 'listByGroup' )->willReturn( array(
			$this->placedRow( 1, '2026-09-03 10:00:00', position: 0 ),
			$new,
			$this->placedRow( 2, '2026-09-10 10:00:00', position: 2 ),
		) );
		$this->calendar->method( 'generate' )->willReturn( $this->slots( array( '2026-09-03', '2026-09-10' ) ) );
		$this->recordMoves();

		$this->groupLessons->expects( self::once() )->method( 'clearSchedule' )->with( 2 );

		$this->service->placeInserted( 99, 1 );
		self::assertSame( array( array( 99, '2026-09-10 10:00:00' ) ), $this->moves );
	}

	/** Урок дописан в конец курса — встаёт на ближайший свободный слот после последнего занятия. */
	public function test_place_inserted_appends_after_last_lesson(): void {
		$new = $this->unplacedRow( 99, 2 );
		$this->groupLessons->method( 'find' )->willReturn( $new );
		$this->groupLessons->method( 'listByGroup' )->willReturn( array(
			$this->placedRow( 1, '2026-09-03 10:00:00', position: 0 ),
			$this->placedRow( 2, '2026-09-10 10:00:00', position: 1 ),
			$new,
		) );
		$this->calendar->method( 'generate' )->willReturn(
			$this->slots( array( '2026-09-03', '2026-09-10', '2026-09-17' ) )
		);
		$this->recordMoves();

		self::assertSame( 0, $this->service->placeInserted( 99, 1 ) );
		self::assertSame( array( array( 99, '2026-09-17 10:00:00' ) ), $this->moves );
	}

	/** Закреплённое занятие остаётся на своей дате, хвост течёт мимо него. */
	public function test_place_inserted_keeps_pinned_lesson_in_place(): void {
		$new = $this->unplacedRow( 99, 1 );
		$this->groupLessons->method( 'find' )->willReturn( $new );
		$this->groupLessons->method( 'listByGroup' )->willReturn( array(
			$this->placedRow( 1, '2026-09-03 10:00:00', position: 0 ),
			$new,
			$this->placedRow( 2, '2026-09-10 10:00:00', isPinned: true, position: 2 ),
			$this->placedRow( 3, '2026-09-17 10:00:00', position: 3 ),
		) );
		$this->calendar->method( 'generate' )->willReturn(
			$this->slots( array( '2026-09-03', '2026-09-10', '2026-09-17', '2026-09-24' ) )
		);
		$this->recordMoves();

		$this->service->placeInserted( 99, 1 );
		self::assertSame(
			array(
				array( 99, '2026-09-17 10:00:00' ),
				array( 3, '2026-09-24 10:00:00' ),
			),
			$this->moves
		);
	}

	/** План ещё не распределён — тема ждёт «Распределить» в пуле. */
	public function test_place_inserted_leaves_unscheduled_program_alone(): void {
		$new = $this->unplacedRow( 99, 1 );
		$this->groupLessons->method( 'find' )->willReturn( $new );
		$this->groupLessons->method( 'listByGroup' )->willReturn( array( $this->unplacedRow( 1, 0 ), $new ) );

		$this->groupLessons->expects( self::never() )->method( 'moveToSlot' );
		$this->dispatcher->expects( self::never() )->method( 'dispatch' );

		self::assertSame( 0, $this->service->placeInserted( 99, 1 ) );
	}

	private function attendedRow( int $id, string $scheduledAt, int $position ): \Inc\DTO\Course\GroupLessonDTO {
		return new \Inc\DTO\Course\GroupLessonDTO(
			id: $id, groupId: 5, lessonId: 10, position: $position, workIdsSnapshot: null, extraWorkIds: array(),
			scheduledAt: $scheduledAt, endsAt: null, isPinned: false, teacherUserId: null, visibility: 'hidden',
			openedAt: null, homeworkDueAt: null, allowLate: true, recordingUrl: null,
			createdByUserId: null, updatedByUserId: null, hasAttendance: true,
		);
	}

	/** Занятие с отмеченной посещаемостью — факт журнала: в пул не возвращается. */
	public function test_return_to_pool_rejects_lesson_with_attendance(): void {
		$this->groupLessons->method( 'find' )->willReturn( $this->attendedRow( 1, '2026-09-10 10:00:00', 0 ) );
		$this->groupLessons->expects( self::never() )->method( 'clearSchedule' );

		$this->expectException( \InvalidArgumentException::class );
		$this->service->returnToPool( 1, 99 );
	}

	/** Вставка темы не сдвигает занятие, по которому уже отмечена посещаемость. */
	public function test_place_inserted_keeps_lesson_with_attendance(): void {
		$new = $this->unplacedRow( 99, 1 );
		$this->groupLessons->method( 'find' )->willReturn( $new );
		$this->groupLessons->method( 'listByGroup' )->willReturn( array(
			$this->placedRow( 1, '2026-09-03 10:00:00', position: 0 ),
			$new,
			$this->attendedRow( 2, '2026-09-10 10:00:00', 2 ),
			$this->placedRow( 3, '2026-09-17 10:00:00', position: 3 ),
		) );
		$this->calendar->method( 'generate' )->willReturn(
			$this->slots( array( '2026-09-03', '2026-09-10', '2026-09-17', '2026-09-24' ) )
		);
		$this->recordMoves();

		$this->service->placeInserted( 99, 1 );
		self::assertSame(
			array(
				array( 99, '2026-09-17 10:00:00' ),
				array( 3, '2026-09-24 10:00:00' ),
			),
			$this->moves
		);
	}
}
