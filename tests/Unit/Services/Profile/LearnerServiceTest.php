<?php

declare( strict_types=1 );

namespace Unit\Services\Profile;

use Inc\Contracts\ClockInterface;
use Inc\DTO\Course\AttendanceDTO;
use Inc\DTO\Course\GradebookEntryDTO;
use Inc\DTO\Course\GroupLessonDTO;
use Inc\Managers\Course\CourseManager;
use Inc\Managers\Course\LessonManager;
use Inc\Repositories\WPDBRepositories\AttendanceRepository;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Repositories\WPDBRepositories\RoomRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Repositories\WPDBRepositories\SubmissionRepository;
use Inc\Services\Course\EffectiveWorksResolver;
use Inc\Services\Course\GradebookService;
use Inc\Services\Course\LessonGateResolver;
use Inc\Services\Course\LessonProgressService;
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
	private $submissions;
	private $worksResolver;
	private $gate;
	private $progress;
	private $subjects;
	private $rooms;
	private $courses;
	private LearnerService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->records      = $this->createMock( StudentRecordRepository::class );
		$this->groups       = $this->createMock( GroupsRepository::class );
		$this->groupLessons = $this->createMock( GroupLessonRepository::class );
		$this->lessons      = $this->createMock( LessonManager::class );
		$this->courses      = $this->createMock( CourseManager::class );
		$this->gradebook    = $this->createMock( GradebookService::class );
		$this->attendance   = $this->createMock( AttendanceRepository::class );
		$this->clock        = $this->createMock( ClockInterface::class );
		$this->clock->method( 'now' )->willReturn( '2026-05-20 10:00:00' );
		// Не стабим дефолт на listByStudentAndGroupLesson/resolve — PHPUnit сам возвращает []
		// для нестабленных вызовов (тип возврата array); так тестовые overrides не глушатся.
		$this->submissions   = $this->createMock( SubmissionRepository::class );
		$this->worksResolver = $this->createMock( EffectiveWorksResolver::class );
		$this->gate          = $this->createMock( LessonGateResolver::class );
		$this->progress      = $this->createMock( LessonProgressService::class );
		$this->subjects      = $this->createMock( SubjectRepository::class );
		$this->rooms         = $this->createMock( RoomRepository::class );
		$this->rooms->method( 'findAll' )->willReturn( array() );
		$this->gate->method( 'resolveLesson' )->willReturn( \Inc\Enums\Course\GateState::Available );
		$this->service = new LearnerService(
			$this->records, $this->groups, $this->groupLessons, $this->lessons, $this->courses,
			$this->gradebook, $this->attendance, $this->clock,
			$this->submissions, $this->worksResolver, $this->gate, $this->progress,
			$this->subjects, $this->rooms,
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

	/* ── T12.2 (D13): per-work дедлайны ──────────────────────────────────── */

	public function test_build_marks_past_due_work_as_overdue_but_keeps_it(): void {
		$this->records->method( 'findActiveByStudent' )->with( 9001 )->willReturn( array(
			(object) array( 'groupId' => 1 ),
		) );
		$this->groups->method( 'findById' )->with( 1 )
			->willReturn( (object) array( 'id' => 1, 'name' => 'Г1', 'subject_key' => 'inf' ) );
		// now = '2026-05-20 10:00:00' (setUp) — дедлайн в прошлом.
		$this->groupLessons->method( 'listByGroup' )->with( 1 )->willReturn( array(
			$this->row( 10, '2026-05-10 09:00:00', '2026-05-15 12:00:00' ),
		) );
		$this->lessons->method( 'get' )->willReturn( null );
		$this->gradebook->method( 'forStudent' )->willReturn( array() );
		$this->attendance->method( 'listByStudent' )->willReturn( array() );

		$work = new \Inc\DTO\Course\WorkDTO(
			id: 501, subjectKey: 'inf', title: 'Практика №1', workType: \Inc\Enums\Course\WorkType::Practice,
			itemIds: array(), instructions: '', authorId: 1, status: 'publish',
		);
		$this->worksResolver->method( 'resolve' )->willReturn( array( $work ) );

		$d = $this->service->build( 9001 );

		self::assertCount( 1, $d['deadlines'] );
		self::assertSame( 'Практика №1', $d['deadlines'][0]['topic'] );
		self::assertTrue( $d['deadlines'][0]['overdue'] );
	}

	public function test_build_hides_deadline_for_already_submitted_work(): void {
		$this->records->method( 'findActiveByStudent' )->willReturn( array(
			(object) array( 'groupId' => 1 ),
		) );
		$this->groups->method( 'findById' )
			->willReturn( (object) array( 'id' => 1, 'name' => 'Г1', 'subject_key' => 'inf' ) );
		$this->groupLessons->method( 'listByGroup' )->willReturn( array(
			$this->row( 10, '2026-05-10 09:00:00', '2026-05-25 12:00:00' ),
		) );
		$this->lessons->method( 'get' )->willReturn( null );
		$this->gradebook->method( 'forStudent' )->willReturn( array() );
		$this->attendance->method( 'listByStudent' )->willReturn( array() );

		$work = new \Inc\DTO\Course\WorkDTO(
			id: 501, subjectKey: 'inf', title: 'Практика №1', workType: \Inc\Enums\Course\WorkType::Practice,
			itemIds: array(), instructions: '', authorId: 1, status: 'publish',
		);
		$this->worksResolver->method( 'resolve' )->willReturn( array( $work ) );
		$this->submissions->method( 'listByStudentAndGroupLesson' )->with( 9001, 10 )->willReturn( array(
			$this->submission( 501 ),
		) );

		$d = $this->service->build( 9001 );

		self::assertSame( array(), $d['deadlines'] );
	}

	private function submission( int $workId ): \Inc\DTO\Course\SubmissionDTO {
		return new \Inc\DTO\Course\SubmissionDTO(
			id: 1, studentPersonId: 9001, groupLessonId: 10, workId: $workId,
			workType: \Inc\Enums\Course\WorkType::Practice, taskId: null, answerText: 'x',
			attachmentId: null, dueAt: null, status: \Inc\Enums\Course\SubmissionStatus::Submitted,
			score: null, maxScore: null, feedback: null, gradedByUserId: null,
			submittedAt: '2026-05-12 10:00:00', gradedAt: null, createdAt: '', updatedAt: '',
		);
	}

	private function row( int $id, string $scheduledAt, ?string $homeworkDueAt = null, ?int $lessonId = null ): GroupLessonDTO {
		return new GroupLessonDTO(
			id: $id, groupId: 1, lessonId: $lessonId, position: 0, workIdsSnapshot: null, extraWorkIds: array(),
			scheduledAt: $scheduledAt, endsAt: null, isPinned: false, teacherUserId: null, visibility: 'open',
			openedAt: null, homeworkDueAt: $homeworkDueAt, allowLate: true, recordingUrl: null,
			createdByUserId: null, updatedByUserId: null, label: 'Тема ' . $id,
		);
	}

	/* ── T14.13: вход в плеер из «Мои курсы» ─────────────────────────────── */

	public function test_build_lessons_carry_player_url_and_status(): void {
		$this->records->method( 'findActiveByStudent' )->with( 9001 )->willReturn( array(
			(object) array( 'groupId' => 1 ),
		) );
		$this->groups->method( 'findById' )->with( 1 )
			->willReturn( (object) array( 'id' => 1, 'name' => 'Г1', 'subject_key' => 'inf' ) );
		$this->groupLessons->method( 'listByGroup' )->with( 1 )->willReturn( array(
			$this->row( 10, '2026-05-10 09:00:00', null, 500 ), // с контентом → плеер
			$this->row( 11, '2026-05-25 09:00:00' ),            // без lessonId → без плеера
		) );
		$this->lessons->method( 'get' )->willReturn( null );
		$this->gradebook->method( 'forStudent' )->willReturn( array() );
		$this->attendance->method( 'listByStudent' )->willReturn( array() );
		$this->progress->method( 'isLessonCompleted' )->with( 9001, 10 )->willReturn( true );

		$lessons = $this->service->build( 9001 )['lessons'];
		$byId    = array_column( $lessons, null, 'group_lesson_id' );

		self::assertSame( 'done', $byId[10]['status'] );
		self::assertStringContainsString( 'gid=1', $byId[10]['player_url'] );
		self::assertStringContainsString( 'gl=10', $byId[10]['player_url'] );
		self::assertSame( '', $byId[11]['player_url'] );
		self::assertSame( '', $byId[11]['status'] );
	}

	private function att( int $glid, bool $present ): AttendanceDTO {
		return new AttendanceDTO( 1, $glid, 9001, $present, 99, '2026-05-10 12:00:00' );
	}
}
