<?php

declare( strict_types=1 );

namespace Unit\Services\Profile;

use Inc\Contracts\ClockInterface;
use Inc\Enums\Access\Capability;
use Inc\Repositories\WPDBRepositories\ExamSessionRepository;
use Inc\Services\Exam\ExamAccessGuard;
use Inc\Services\Exam\ExamTime;
use Inc\DTO\Course\GroupLessonDTO;
use Inc\DTO\Course\LessonDTO;
use Inc\Managers\Course\LessonManager;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
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
	private $persons;
	private $rooms;
	private $clock;
	private $examSessions;
	private $examGuard;
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
		$this->persons       = $this->createMock( PersonRepository::class );
		$this->rooms         = $this->createMock( RoomRepository::class );
		$this->rooms->method( 'findAll' )->willReturn( array() );
		$this->clock         = $this->createMock( ClockInterface::class );
		$this->examSessions  = $this->createMock( ExamSessionRepository::class );
		$this->examGuard     = $this->createMock( ExamAccessGuard::class );
		$GLOBALS['_fs_test_timezone'] = 'Europe/Moscow';
		$GLOBALS['_test_user_can']     = array();
		$this->service       = new DashboardService(
			$this->groups, $this->groupLessons, $this->lessons, $this->attendance,
			$this->records, $this->submissions, $this->substitutions, $this->rooms, $this->clock,
			$this->createMock( SubjectRepository::class ),
			$this->persons,
			$this->createMock( \Inc\Services\Profile\AdminAlertService::class ),
			$this->examSessions,
			$this->examGuard,
			new ExamTime( $this->clock ),
		);
		$this->clock->method( 'now' )->willReturn( '2026-05-20 10:00:00' );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['_fs_test_timezone'], $GLOBALS['_test_user_can'] );
		parent::tearDown();
	}

	public function test_aggregates_schedule_worklist_and_stats(): void {
		$this->groups->method( 'findByTeacherId' )->with( 99 )
			->willReturn( array( (object) array( 'id' => 1, 'name' => 'Г1', 'subject_key' => 'inf', 'teacher_id' => 99 ) ) );
		$this->substitutions->method( 'findUpcomingOrActiveBySubstitute' )->willReturn( array() );
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

	/** Преподаватель занятия в расписании — «Фамилия И.О.»; в дни замены — заместитель. */
	public function test_schedule_items_carry_effective_teacher_short_name(): void {
		$this->groups->method( 'findByTeacherId' )
			->willReturn( array( (object) array( 'id' => 1, 'name' => 'Г1', 'subject_key' => 'inf', 'teacher_id' => 99 ) ) );
		$this->substitutions->method( 'findUpcomingOrActiveBySubstitute' )->willReturn( array() );
		$this->substitutions->method( 'listByGroup' )->willReturn( array(
			new \Inc\DTO\Course\SubstitutionDTO( 1, 1, 99, 55, '2026-05-25', '2026-05-31', null, 3, '2026-05-01 00:00:00' ),
		) );
		$this->attendance->method( 'matrixForGroup' )->willReturn( array() );
		$this->submissions->method( 'listQueueByGroup' )->willReturn( array() );
		$this->groupLessons->method( 'listByGroup' )->willReturn( array(
			$this->row( 10, '2026-05-20 09:00:00', '2026-05-20 09:45:00' ),
			$this->row( 12, '2026-05-26 09:00:00', '2026-05-26 09:45:00' ),
		) );
		$this->persons->method( 'findByWpUserId' )->willReturnCallback( fn( int $id ) => \Inc\DTO\Person\PersonDTO::fromArray( array(
			'id' => $id, 'wp_user_id' => $id, 'last_name' => 99 === $id ? 'Иванова' : 'Петров',
			'first_name' => 99 === $id ? 'Анна' : 'Олег', 'middle_name' => 99 === $id ? 'Сергеевна' : null,
			'created_at' => '', 'updated_at' => '',
		) ) );

		$d = $this->service->build( 99, false );

		self::assertSame( 'Иванова А.С.', $d['week'][0]['teacher'] );
		self::assertSame( 'Петров О.', $d['week'][1]['teacher'] );
	}

	public function test_marks_group_covered_by_substitute(): void {
		$this->groups->method( 'findByTeacherId' )
			->willReturn( array( (object) array( 'id' => 1, 'name' => 'Г1', 'subject_key' => 'inf', 'teacher_id' => 99 ) ) );
		$this->substitutions->method( 'findUpcomingOrActiveBySubstitute' )->willReturn( array() );
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

	public function test_includes_group_with_future_substitution_and_valid_from_marker(): void {
		$this->groups->method( 'findByTeacherId' )->with( 55 )->willReturn( array() );
		// Замена утверждена «с понедельника», valid_from ещё не наступил (T1.A).
		$this->substitutions->method( 'findUpcomingOrActiveBySubstitute' )->with( 55, '2026-05-20' )->willReturn(
			array( new \Inc\DTO\Course\SubstitutionDTO( 1, 1, 99, 55, '2026-05-25', '2026-05-31', null, 3, '2026-05-01 00:00:00' ) )
		);
		$this->groups->method( 'findById' )->with( 1 )
			->willReturn( (object) array( 'id' => 1, 'name' => 'Г1', 'subject_key' => 'inf', 'teacher_id' => 99 ) );
		$this->substitutions->method( 'findActiveForGroup' )->willReturn( null );
		$this->attendance->method( 'matrixForGroup' )->willReturn( array() );
		$this->records->method( 'countActiveByGroup' )->willReturn( 6 );
		$this->submissions->method( 'listQueueByGroup' )->willReturn( array() );
		$this->groupLessons->method( 'listByGroup' )->willReturn( array() );

		$d = $this->service->build( 55, false );

		self::assertCount( 1, $d['groups'] );
		self::assertSame( '2026-05-25', $d['groups'][0]['covering_from'] );
		self::assertSame( '2026-05-31', $d['groups'][0]['covering_until'] );
		self::assertSame( '2026-05-25', $d['covering'][0]['valid_from'] );
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
	// ---- сеансы экзаменов (4.7) -----------------------------------------------------------------------------------------------

	/** Строка сеанса из `ExamSessionRepository::listForTeacherBetween()`: время — UTC (МСК = UTC+3). */
	private function examRow( array $override = array() ): array {
		return array_merge( array(
			'id' => '71', 'event_id' => '3', 'event_title' => 'Пробный ЕГЭ', 'scheduled_at' => '2026-05-20 07:00:00', 'planned_end_at' => '2026-05-20 10:55:00',
			'room_id' => '2', 'capacity' => '12', 'occupied_count' => '5',
		), $override );
	}

	private function arrangeNoGroups(): void {
		$this->groups->method( 'findByTeacherId' )->willReturn( array() );
		$this->substitutions->method( 'findUpcomingOrActiveBySubstitute' )->willReturn( array() );
	}

	public function test_exam_sessions_listed_for_responsible_teacher(): void {
		$this->arrangeNoGroups();
		$GLOBALS['_test_user_can'][99][ Capability::ManageExams->value ] = true;
		$this->examGuard->method( 'isGlobal' )->willReturn( false );
		$this->examSessions->expects( $this->once() )->method( 'listForTeacherBetween' )->with( 99, false, $this->anything(), $this->anything() )->willReturn( array( $this->examRow() ) );

		$d = $this->service->build( 99, false );

		self::assertCount( 1, $d['exams'] );
		self::assertSame( 'exam', $d['exams'][0]['kind'] );
		self::assertSame( 71, $d['exams'][0]['session_id'] );
		self::assertSame( 3, $d['exams'][0]['event_id'] );
		self::assertSame( 'Пробный ЕГЭ', $d['exams'][0]['title'] );
		self::assertSame( 5, $d['exams'][0]['occupied'] );
		self::assertSame( 12, $d['exams'][0]['capacity'] );
	}

	public function test_global_access_asks_for_all_sessions(): void {
		$this->arrangeNoGroups();
		$GLOBALS['_test_user_can'][99][ Capability::ManageExams->value ] = true;
		$this->examGuard->method( 'isGlobal' )->willReturn( true );
		$this->examSessions->expects( $this->once() )->method( 'listForTeacherBetween' )->with( 99, true, $this->anything(), $this->anything() )->willReturn( array() );

		$this->service->build( 99, true );
	}

	public function test_exam_sessions_hidden_without_manage_exams(): void {
		$this->arrangeNoGroups();
		$this->examSessions->expects( $this->never() )->method( 'listForTeacherBetween' );

		self::assertSame( array(), $this->service->build( 99, true )['exams'], 'Офис без права экзамены на «Главной» не видит.' );
	}

	public function test_exam_times_are_local(): void {
		$this->arrangeNoGroups();
		$GLOBALS['_test_user_can'][99][ Capability::ManageExams->value ] = true;
		$this->examSessions->method( 'listForTeacherBetween' )->willReturn( array( $this->examRow() ) );

		$exam = $this->service->build( 99, false )['exams'][0];

		self::assertSame( '2026-05-20', $exam['date'] );
		self::assertSame( '10:00', $exam['time_start'], '07:00 UTC = 10:00 МСК.' );
		self::assertSame( '13:55', $exam['time_end'] );
		self::assertSame( 'now', $exam['state'], 'Сейчас 10:00 местного — сеанс идёт.' );
	}

	public function test_exam_state_follows_the_same_rule_as_lessons(): void {
		$this->arrangeNoGroups();
		$GLOBALS['_test_user_can'][99][ Capability::ManageExams->value ] = true;
		$this->examSessions->method( 'listForTeacherBetween' )->willReturn( array(
			$this->examRow( array( 'scheduled_at' => '2026-05-20 11:00:00', 'planned_end_at' => '2026-05-20 14:55:00' ) ),
			$this->examRow( array( 'scheduled_at' => '2026-05-19 07:00:00', 'planned_end_at' => '2026-05-19 10:55:00' ) ),
		) );

		$exams = $this->service->build( 99, false )['exams'];

		self::assertSame( 'soon', $exams[0]['state'] );
		self::assertSame( 'done', $exams[1]['state'] );
	}

	public function test_lesson_counters_unchanged_by_exams(): void {
		$GLOBALS['_test_user_can'][99][ Capability::ManageExams->value ] = true;
		$this->groups->method( 'findByTeacherId' )->willReturn( array( (object) array( 'id' => 1, 'name' => 'Г1', 'subject_key' => 'inf', 'teacher_id' => 99 ) ) );
		$this->substitutions->method( 'findUpcomingOrActiveBySubstitute' )->willReturn( array() );
		$this->substitutions->method( 'findActiveForGroup' )->willReturn( null );
		$this->attendance->method( 'matrixForGroup' )->willReturn( array() );
		$this->lessons->method( 'get' )->willReturn( $this->lesson() );
		$this->submissions->method( 'listQueueByGroup' )->willReturn( array() );
		$this->groupLessons->method( 'listByGroup' )->willReturn( array( $this->row( 10, '2026-05-20 09:00:00', '2026-05-20 09:45:00' ) ) );
		$this->examSessions->method( 'listForTeacherBetween' )->willReturn( array( $this->examRow(), $this->examRow( array( 'id' => '72' ) ) ) );

		$d = $this->service->build( 99, false );

		self::assertSame( 1, $d['stats']['lessons_today'] );
		self::assertCount( 1, $d['today'] );
		self::assertCount( 1, $d['week'] );
		self::assertCount( 2, $d['exams'] );
		self::assertNotContains( 'exam', array_column( $d['today'], 'kind' ) );
	}
}
