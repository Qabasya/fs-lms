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

	private function stubCalendarDeps(): void {
		$this->groups->method( 'findById' )->willReturn( new \stdClass() );
		$this->calendar->method( 'periodMeta' )->willReturn( array(
			'period' => null, 'holidays' => array(), 'lessonDays' => array(), 'lessonTimes' => array(),
		) );
		$this->lessonManager->method( 'get' )->willReturn( null );
		$this->rooms->method( 'findAll' )->willReturn( array() );
	}
}
