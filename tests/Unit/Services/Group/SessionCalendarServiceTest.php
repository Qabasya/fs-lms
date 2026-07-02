<?php

declare( strict_types=1 );

namespace Unit\Services\Group;

use Inc\DTO\Settings\AcademicPeriodDTO;
use Inc\Repositories\OptionsRepositories\AcademicPeriodRepository;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\RoomRepository;
use Inc\Services\Group\SessionCalendarService;
use PHPUnit\Framework\TestCase;

class SessionCalendarServiceTest extends TestCase {

	private GroupsRepository&\PHPUnit\Framework\MockObject\MockObject $groups;
	private GroupLessonRepository&\PHPUnit\Framework\MockObject\MockObject $groupLessons;
	private AcademicPeriodRepository&\PHPUnit\Framework\MockObject\MockObject $periods;
	private RoomRepository&\PHPUnit\Framework\MockObject\MockObject $rooms;
	private SessionCalendarService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->groups       = $this->createMock( GroupsRepository::class );
		$this->groupLessons = $this->createMock( GroupLessonRepository::class );
		$this->periods      = $this->createMock( AcademicPeriodRepository::class );
		$this->rooms        = $this->createMock( RoomRepository::class );
		$this->service       = new SessionCalendarService(
			$this->groups, $this->groupLessons, $this->periods, $this->rooms,
		);
	}

	/** T12.4: periodMeta() отдаёт время занятия по дате ('16:00–17:30') из слотов. */
	public function test_period_meta_returns_lesson_times_per_date(): void {
		$group              = new \stdClass();
		$group->academic_period_id = 'period1';
		$this->groups->method( 'findById' )->with( 5 )->willReturn( $group );
		$this->groups->method( 'getMeetings' )->with( 5 )->willReturn( array(
			array( 'weekday' => 4, 'time' => '16:00', 'duration_min' => 90 ),
		) );
		$this->periods->method( 'getById' )->with( 'period1' )->willReturn( new AcademicPeriodDTO(
			id: 'period1', name: 'Test', start_date: '2026-07-02', end_date: '2026-07-09',
		) );

		$meta = $this->service->periodMeta( 5 );

		self::assertSame( array( '2026-07-02', '2026-07-09' ), $meta['lessonDays'] );
		self::assertSame(
			array( '2026-07-02' => '16:00–17:30', '2026-07-09' => '16:00–17:30' ),
			$meta['lessonTimes']
		);
	}

	/** Два занятия в один день (два meeting-слота на один weekday) — время берётся у первого. */
	public function test_period_meta_takes_first_slot_time_when_two_meetings_share_a_date(): void {
		$group              = new \stdClass();
		$group->academic_period_id = 'period1';
		$this->groups->method( 'findById' )->willReturn( $group );
		$this->groups->method( 'getMeetings' )->willReturn( array(
			array( 'weekday' => 4, 'time' => '09:00', 'duration_min' => 60 ),
			array( 'weekday' => 4, 'time' => '16:00', 'duration_min' => 90 ),
		) );
		$this->periods->method( 'getById' )->willReturn( new AcademicPeriodDTO(
			id: 'period1', name: 'Test', start_date: '2026-07-02', end_date: '2026-07-02',
		) );

		$meta = $this->service->periodMeta( 5 );

		self::assertSame( array( '2026-07-02' => '09:00–10:00' ), $meta['lessonTimes'] );
	}

	public function test_period_meta_returns_empty_for_missing_group(): void {
		$this->groups->method( 'findById' )->willReturn( null );

		$meta = $this->service->periodMeta( 99 );

		self::assertSame( array(), $meta['lessonDays'] );
		self::assertSame( array(), $meta['lessonTimes'] );
		self::assertNull( $meta['period'] );
	}
}
