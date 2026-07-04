<?php

declare( strict_types=1 );

namespace Unit\Services\Profile;

use Inc\Contracts\ClockInterface;
use Inc\DTO\Course\GroupLessonDTO;
use Inc\DTO\Course\LessonDTO;
use Inc\Managers\Course\LessonManager;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\RoomRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Repositories\WPDBRepositories\SubmissionRepository;
use Inc\Repositories\WPDBRepositories\SubstitutionRepository;
use Inc\Services\Course\AttendanceService;
use Inc\Services\Profile\DashboardService;
use PHPUnit\Framework\TestCase;

class DashboardServiceTest extends TestCase {

	private $groups;
	private $groupLessons;
	private $lessons;
	private $attendance;
	private $records;
	private $submissions;
	private $substitutions;
	private $rooms;
	private $clock;
	private DashboardService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->groups        = $this->createMock( GroupsRepository::class );
		$this->groupLessons  = $this->createMock( GroupLessonRepository::class );
		$this->lessons       = $this->createMock( LessonManager::class );
		$this->attendance    = $this->createMock( AttendanceService::class );
		$this->records       = $this->createMock( StudentRecordRepository::class );
		$this->submissions   = $this->createMock( SubmissionRepository::class );
		$this->substitutions = $this->createMock( SubstitutionRepository::class );
		$this->rooms         = $this->createMock( RoomRepository::class );
		$this->rooms->method( 'findAll' )->willReturn( array() );
		$this->clock         = $this->createMock( ClockInterface::class );
		$this->service       = new DashboardService(
			$this->groups, $this->groupLessons, $this->lessons, $this->attendance,
			$this->records, $this->submissions, $this->substitutions, $this->rooms, $this->clock,
			$this->createMock( SubjectRepository::class )
		);
		$this->clock->method( 'now' )->willReturn( '2026-05-20 10:00:00' );
	}

	public function test_aggregates_schedule_worklist_and_stats(): void {
		$this->groups->method( 'findByTeacherId' )->with( 99 )
			->willReturn( array( (object) array( 'id' => 1, 'name' => 'Г1', 'subject_key' => 'inf', 'teacher_id' => 99 ) ) );
		$this->substitutions->method( 'findActiveBySubstitute' )->willReturn( array() );
		$this->substitutions->method( 'findActiveForGroup' )->willReturn( null );
		$this->attendance->method( 'matrixForGroup' )->willReturn( array() ); // нет отметок
		$this->records->method( 'countActiveByGroup' )->willReturn( 6 );
		$this->lessons->method( 'get' )->willReturn( $this->lesson() );
		$this->submissions->method( 'listQueueByGroup' )->willReturn( array( (object) array() ) ); // 1 на проверку
		$this->groupLessons->method( 'listByGroup' )->willReturn( array(
			$this->row( 10, '2026-05-20 09:00:00', '2026-05-20 09:45:00' ), // сегодня, прошло → done + to_fill
			$this->row( 11, '2026-05-10 09:00:00', '2026-05-10 09:45:00' ), // прошлое → to_fill
			$this->row( 12, '2026-05-25 09:00:00', '2026-05-25 09:45:00' ), // будущее (в неделе)
		) );

		$d = $this->service->build( 99, false );

		self::assertSame( 1, $d['stats']['lessons_today'] );
		self::assertSame( 1, $d['stats']['to_review'] );
		self::assertSame( 2, $d['stats']['to_fill'] );     // 2 прошедших без отметок
		self::assertSame( 1, $d['stats']['groups'] );
		self::assertCount( 1, $d['today'] );
		self::assertSame( 'done', $d['today'][0]['state'] );
		self::assertCount( 3, $d['week'] );                 // НБ-11: week = всё расписание (окно недели режет клиент)
		self::assertSame( 1, $d['worklist']['to_review'][0]['count'] );
	}

	public function test_marks_group_covered_by_substitute(): void {
		$this->groups->method( 'findByTeacherId' )
			->willReturn( array( (object) array( 'id' => 1, 'name' => 'Г1', 'subject_key' => 'inf', 'teacher_id' => 99 ) ) );
		$this->substitutions->method( 'findActiveBySubstitute' )->willReturn( array() );
		$this->substitutions->method( 'findActiveForGroup' )->with( 1, '2026-05-20' )->willReturn(
			new \Inc\DTO\Course\SubstitutionDTO( 1, 1, 99, 55, '2026-05-01', '2026-05-31', null, 3, '2026-05-01 00:00:00' )
		);
		$this->attendance->method( 'matrixForGroup' )->willReturn( array() );
		$this->records->method( 'countActiveByGroup' )->willReturn( 6 );
		$this->submissions->method( 'listQueueByGroup' )->willReturn( array() );
		$this->groupLessons->method( 'listByGroup' )->willReturn( array() );

		$d = $this->service->build( 99, false );

		self::assertSame( '2026-05-31', $d['groups'][0]['covered_until'] );
	}

	private function lesson(): LessonDTO {
		return new LessonDTO( id: 10, subjectKey: 'inf', topic: 'Тема', steps: array(), authorId: 1, status: 'publish' );
	}

	private function row( int $id, string $start, string $end ): GroupLessonDTO {
		return new GroupLessonDTO(
			id: $id, groupId: 1, lessonId: 10, position: 0, workIdsSnapshot: null, extraWorkIds: array(),
			scheduledAt: $start, endsAt: $end, isPinned: false, teacherUserId: null, visibility: 'open',
			openedAt: null, homeworkDueAt: null, allowLate: true, recordingUrl: null,
			createdByUserId: null, updatedByUserId: null,
		);
	}
}
