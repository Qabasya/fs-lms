<?php

declare( strict_types=1 );

namespace Unit\Services\Profile;

use Inc\Contracts\ClockInterface;
use Inc\DTO\Course\AttendanceDTO;
use Inc\DTO\Course\GradebookEntryDTO;
use Inc\DTO\Course\GroupLessonDTO;
use Inc\Managers\Course\LessonManager;
use Inc\Repositories\WPDBRepositories\AttendanceRepository;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Services\Course\GradebookService;
use Inc\Services\Profile\LearnerService;
use PHPUnit\Framework\TestCase;

class LearnerServiceTest extends TestCase {

	private $records;
	private $groups;
	private $groupLessons;
	private $lessons;
	private $gradebook;
	private $attendance;
	private $clock;
	private LearnerService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->records      = $this->createMock( StudentRecordRepository::class );
		$this->groups       = $this->createMock( GroupsRepository::class );
		$this->groupLessons = $this->createMock( GroupLessonRepository::class );
		$this->lessons      = $this->createMock( LessonManager::class );
		$this->gradebook    = $this->createMock( GradebookService::class );
		$this->attendance   = $this->createMock( AttendanceRepository::class );
		$this->clock        = $this->createMock( ClockInterface::class );
		$this->clock->method( 'now' )->willReturn( '2026-05-20 10:00:00' );
		$this->service = new LearnerService(
			$this->records, $this->groups, $this->groupLessons, $this->lessons,
			$this->gradebook, $this->attendance, $this->clock
		);
	}

	public function test_build_aggregates_groups_grades_attendance(): void {
		$this->records->method( 'findActiveByStudent' )->with( 9001 )->willReturn( array(
			(object) array( 'groupId' => 1 ),
		) );
		$this->groups->method( 'findById' )->with( 1 )
			->willReturn( (object) array( 'id' => 1, 'name' => 'Г1', 'subject_key' => 'inf' ) );
		$this->groupLessons->method( 'listByGroup' )->with( 1 )->willReturn( array(
			$this->row( 10, '2026-05-10 09:00:00' ), // прошлое
			$this->row( 11, '2026-05-25 09:00:00' ), // будущее → upcoming
		) );
		$this->lessons->method( 'get' )->willReturn( null );
		$this->gradebook->method( 'forStudent' )->with( 9001 )->willReturn( array(
			new GradebookEntryDTO( 9001, 1, 'work', 5, 'Практика №1', 'practice', 8.0, 10.0, '2026-05-12 12:00:00', 'fraction' ),
		) );
		$this->attendance->method( 'listByStudent' )->with( 9001 )->willReturn( array(
			$this->att( 10, true ),
			$this->att( 11, false ),
		) );

		$d = $this->service->build( 9001 );

		self::assertCount( 1, $d['groups'] );
		self::assertSame( 'Г1', $d['groups'][0]['name'] );
		self::assertCount( 1, $d['upcoming'] );
		self::assertCount( 1, $d['grades'] );
		self::assertSame( '8/10', $d['grades'][0]['value'] );
		self::assertSame( 2, $d['attendance']['total'] );
		self::assertSame( 1, $d['attendance']['present'] );
		self::assertSame( 50, $d['attendance']['percent'] );
	}

	public function test_build_empty_when_no_groups(): void {
		$this->records->method( 'findActiveByStudent' )->willReturn( array() );
		$this->gradebook->method( 'forStudent' )->willReturn( array() );
		$this->attendance->method( 'listByStudent' )->willReturn( array() );

		$d = $this->service->build( 9001 );

		self::assertSame( array(), $d['groups'] );
		self::assertNull( $d['attendance']['percent'] );
	}

	private function row( int $id, string $scheduledAt ): GroupLessonDTO {
		return new GroupLessonDTO(
			id: $id, groupId: 1, lessonId: null, position: 0, workIdsSnapshot: null, extraWorkIds: array(),
			scheduledAt: $scheduledAt, endsAt: null, isPinned: false, teacherUserId: null, visibility: 'open',
			openedAt: null, homeworkDueAt: null, allowLate: true, recordingUrl: null,
			createdByUserId: null, updatedByUserId: null, label: 'Тема ' . $id,
		);
	}

	private function att( int $glid, bool $present ): AttendanceDTO {
		return new AttendanceDTO( 1, $glid, 9001, $present, 99, '2026-05-10 12:00:00' );
	}
}
