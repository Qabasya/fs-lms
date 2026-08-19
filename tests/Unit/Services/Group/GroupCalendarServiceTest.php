<?php

declare( strict_types=1 );

namespace Unit\Services\Group;

use Inc\Contracts\LogEventDispatcherInterface;
use Inc\Managers\Course\LessonManager;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\RoomRepository;
use Inc\Services\Course\EffectiveTeacherResolver;
use Inc\Services\Course\RoomAvailabilityService;
use Inc\Services\Group\GroupCalendarService;
use Inc\Services\Group\ProgramCompositionService;
use Inc\Services\Group\ScheduleEventPublisher;
use Inc\Services\Group\SessionCalendarService;
use PHPUnit\Framework\TestCase;
use Tests\Support\GroupLessonFixtures;

/**
 * Представление КТП: нумерация тем и продолжений в календаре группы.
 */
class GroupCalendarServiceTest extends TestCase {

	use GroupLessonFixtures;

	private GroupLessonRepository&\PHPUnit\Framework\MockObject\MockObject $groupLessons;
	private GroupsRepository&\PHPUnit\Framework\MockObject\MockObject $groups;
	private RoomRepository&\PHPUnit\Framework\MockObject\MockObject $rooms;
	private SessionCalendarService&\PHPUnit\Framework\MockObject\MockObject $calendar;
	private LessonManager&\PHPUnit\Framework\MockObject\MockObject $lessonManager;
	private GroupCalendarService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->groupLessons  = $this->createMock( GroupLessonRepository::class );
		$this->groups        = $this->createMock( GroupsRepository::class );
		$this->rooms         = $this->createMock( RoomRepository::class );
		$this->calendar      = $this->createMock( SessionCalendarService::class );
		$this->lessonManager = $this->createMock( LessonManager::class );

		$program = new ProgramCompositionService(
			$this->groupLessons,
			$this->lessonManager,
			$this->groups,
			new ScheduleEventPublisher( $this->createMock( LogEventDispatcherInterface::class ) ),
		);

		$this->service = new GroupCalendarService(
			$this->groups,
			$this->rooms,
			$this->createMock( RoomAvailabilityService::class ),
			$this->calendar,
			$this->createMock( EffectiveTeacherResolver::class ),
			$program,
		);
	}

	public function test_get_calendar_gives_continuation_pair_shared_number_and_parts(): void {
		$this->stubCalendarDeps();
		$this->groupLessons->method( 'listByGroup' )->willReturn( array(
			$this->makeRow( 10, 'group', null, null ),  // origin
			$this->makeRow( 11, 'group', null, 10 ),    // continuation of 10
			$this->makeRow( 12, 'group', null, null ),  // standalone theme
		) );

		$themes = $this->service->getCalendar( 5 )['themes'];

		self::assertSame( 1, $themes[0]['n'] );
		self::assertSame( 1, $themes[0]['part'] );
		self::assertSame( 2, $themes[0]['total_parts'] );

		self::assertSame( 1, $themes[1]['n'] ); // тот же номер — общая тема
		self::assertSame( 2, $themes[1]['part'] );
		self::assertSame( 2, $themes[1]['total_parts'] );

		self::assertSame( 2, $themes[2]['n'] ); // следующая отдельная тема
		self::assertSame( 1, $themes[2]['part'] );
		self::assertSame( 1, $themes[2]['total_parts'] );
	}

	/** Продолжение с удалённым (отсутствующим) оригиналом деградирует до самостоятельной темы. */
	public function test_get_calendar_orphan_continuation_degrades_to_standalone(): void {
		$this->stubCalendarDeps();
		$this->groupLessons->method( 'listByGroup' )->willReturn( array(
			$this->makeRow( 20, 'group', null, 999 ), // continuedFromId=999 — такой строки нет
		) );

		$themes = $this->service->getCalendar( 5 )['themes'];

		self::assertSame( 1, $themes[0]['n'] );
		self::assertSame( 1, $themes[0]['part'] );
		self::assertSame( 1, $themes[0]['total_parts'] );
	}

	/** Этап 2: getCalendar() пробрасывает укомплектованность периода — баннер живёт без reflow(). */
	public function test_get_calendar_exposes_slots_total_and_unplaced(): void {
		$this->stubCalendarDeps( array( 'slots' => 72, 'consuming' => 80, 'unplaced' => 8 ) );
		$this->groupLessons->method( 'listByGroup' )->willReturn( array() );

		$calendar = $this->service->getCalendar( 5 );

		self::assertSame( 72, $calendar['slots_total'] );
		self::assertSame( 8, $calendar['unplaced'] );
	}

	/** Этап 4: тема с датой на дне вне lessonDays помечается off_schedule. */
	public function test_get_calendar_marks_off_schedule_theme(): void {
		$this->stubCalendarDeps( lessonDays: array( '2026-09-01' ) );
		$this->groupLessons->method( 'listByGroup' )->willReturn( array(
			$this->rowScheduledOn( 10, '2026-09-01 10:00:00' ), // плановый день
			$this->rowScheduledOn( 11, '2026-09-05 10:00:00' ), // вне расписания
		) );

		$themes = $this->service->getCalendar( 5 )['themes'];

		self::assertFalse( $themes[0]['off_schedule'] );
		self::assertTrue( $themes[1]['off_schedule'] );
	}

	/** Тема без даты — не «вне расписания», просто ещё не размещена. */
	public function test_get_calendar_unscheduled_theme_is_not_off_schedule(): void {
		$this->stubCalendarDeps();
		$this->groupLessons->method( 'listByGroup' )->willReturn( array( $this->makeRow( 10 ) ) );

		$themes = $this->service->getCalendar( 5 )['themes'];

		self::assertFalse( $themes[0]['off_schedule'] );
	}

	private function rowScheduledOn( int $id, string $scheduledAt ): \Inc\DTO\Course\GroupLessonDTO {
		return new \Inc\DTO\Course\GroupLessonDTO(
			id: $id, groupId: 5, lessonId: 10, position: $id, workIdsSnapshot: null, extraWorkIds: array(),
			scheduledAt: $scheduledAt, endsAt: null, isPinned: true, teacherUserId: null,
			visibility: 'hidden', openedAt: null, homeworkDueAt: null, allowLate: true, recordingUrl: null,
			createdByUserId: null, updatedByUserId: null,
		);
	}

	/**
	 * @param array{slots:int, consuming:int, unplaced:int} $completeness
	 * @param string[]                                      $lessonDays
	 */
	private function stubCalendarDeps(
		array $completeness = array( 'slots' => 0, 'consuming' => 0, 'unplaced' => 0 ),
		array $lessonDays = array()
	): void {
		$this->groups->method( 'findById' )->willReturn( new \stdClass() );
		$this->calendar->method( 'periodMeta' )->willReturn( array(
			'period' => null, 'holidays' => array(), 'lessonDays' => $lessonDays, 'lessonTimes' => array(),
		) );
		$this->calendar->method( 'completeness' )->willReturn( $completeness );
		$this->lessonManager->method( 'get' )->willReturn( null );
		$this->rooms->method( 'findAll' )->willReturn( array() );
	}
}
