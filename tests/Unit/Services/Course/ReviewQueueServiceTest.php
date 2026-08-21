<?php

declare( strict_types=1 );

namespace Unit\Services\Course;

use Inc\DTO\Assessment\AssessmentDTO;
use Inc\DTO\Assessment\AttemptDTO;
use Inc\DTO\Course\WorkDTO;
use Inc\DTO\Enrollment\StudentRecordDTO;
use Inc\Enums\Assessment\AssessmentKind;
use Inc\Enums\Assessment\ScoringPolicy;
use Inc\Enums\Course\WorkType;
use Inc\Enums\Enrollment\EnrollmentStatus;
use Inc\Managers\Assessment\AssessmentManager;
use Inc\Managers\Course\WorkManager;
use Inc\Repositories\WPDBRepositories\AssessmentAnswerRepository;
use Inc\Repositories\WPDBRepositories\AssessmentAttemptRepository;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Repositories\WPDBRepositories\SubmissionRepository;
use Inc\Repositories\WPDBRepositories\SubstitutionRepository;
use Inc\Services\Course\ReviewQueueService;
use PHPUnit\Framework\TestCase;

/**
 * D3 (.docs/Tasks.md): агрегация вкладки «Работы» — submissions (по work_id) +
 * assessment_attempts (по assessment_id), по группам пользователя, в три корзины.
 */
class ReviewQueueServiceTest extends TestCase {

	private GroupsRepository&\PHPUnit\Framework\MockObject\MockObject            $groups;
	private SubstitutionRepository&\PHPUnit\Framework\MockObject\MockObject      $substitutions;
	private SubmissionRepository&\PHPUnit\Framework\MockObject\MockObject        $submissions;
	private AssessmentAttemptRepository&\PHPUnit\Framework\MockObject\MockObject $attempts;
	private AssessmentAnswerRepository&\PHPUnit\Framework\MockObject\MockObject  $answers;
	private WorkManager&\PHPUnit\Framework\MockObject\MockObject                 $works;
	private AssessmentManager&\PHPUnit\Framework\MockObject\MockObject           $assessments;
	private GroupLessonRepository&\PHPUnit\Framework\MockObject\MockObject       $groupLessons;
	private StudentRecordRepository&\PHPUnit\Framework\MockObject\MockObject     $studentRecords;
	private ReviewQueueService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->groups         = $this->createMock( GroupsRepository::class );
		$this->substitutions  = $this->createMock( SubstitutionRepository::class );
		$this->submissions    = $this->createMock( SubmissionRepository::class );
		$this->attempts       = $this->createMock( AssessmentAttemptRepository::class );
		$this->answers        = $this->createMock( AssessmentAnswerRepository::class );
		$this->works          = $this->createMock( WorkManager::class );
		$this->assessments    = $this->createMock( AssessmentManager::class );
		$this->groupLessons   = $this->createMock( GroupLessonRepository::class );
		$this->studentRecords = $this->createMock( StudentRecordRepository::class );

		$this->substitutions->method( 'findUpcomingOrActiveBySubstitute' )->willReturn( array() );

		$this->service = new ReviewQueueService(
			$this->groups,
			$this->substitutions,
			$this->submissions,
			$this->attempts,
			$this->answers,
			$this->works,
			$this->assessments,
			$this->groupLessons,
			$this->studentRecords,
		);
	}

	private function group( int $id, string $name ): object {
		return (object) array( 'id' => $id, 'name' => $name );
	}

	private function work( int $id, string $title ): WorkDTO {
		return new WorkDTO(
			id: $id, subjectKey: 'inf', title: $title, workType: WorkType::Practice,
			itemIds: array(), instructions: '', authorId: 1, status: 'publish',
		);
	}

	private function assessment( int $id, string $title, AssessmentKind $kind ): AssessmentDTO {
		return new AssessmentDTO(
			id: $id, subjectKey: 'inf', title: $title, taskIds: array(),
			timeLimit: 0, attemptsAllowed: 0, passScore: 0.0,
			scoringPolicy: ScoringPolicy::Highest, status: 'publish',
			kind: $kind, taskPoints: array(), scoreMap: array(),
		);
	}

	private function attempt( array $overrides = array() ): AttemptDTO {
		return AttemptDTO::fromArray( array_merge( array(
			'id' => 1, 'assessment_id' => 1, 'student_person_id' => 10, 'group_id' => 7,
			'attempt_number' => 1, 'started_at' => '2026-06-01 10:00:00', 'deadline_at' => '2026-06-01 11:00:00',
			'status' => 'submitted',
		), $overrides ) );
	}

	// ── Пустой список групп ──────────────────────────────────────────────────

	public function test_pending_works_empty_when_user_has_no_groups(): void {
		$this->groups->method( 'findByTeacherId' )->willReturn( array() );

		self::assertSame( array(), $this->service->pendingWorks( 5, false, 'pending' ) );
	}

	// ── Набор групп: свои vs все (офис) + замены ─────────────────────────────

	public function test_uses_findByTeacherId_for_teacher_and_findAll_for_office(): void {
		$this->groups->expects( self::once() )->method( 'findByTeacherId' )->with( 5 )->willReturn( array() );
		$this->groups->expects( self::never() )->method( 'findAll' );
		$this->service->pendingWorks( 5, false, 'pending' );

		$groups2 = $this->createMock( GroupsRepository::class );
		$groups2->expects( self::once() )->method( 'findAll' )->willReturn( array() );
		$groups2->expects( self::never() )->method( 'findByTeacherId' );
		$service2 = new ReviewQueueService(
			$groups2, $this->substitutions, $this->submissions, $this->attempts,
			$this->answers, $this->works, $this->assessments, $this->groupLessons, $this->studentRecords,
		);
		$service2->pendingWorks( 5, true, 'pending' );
	}

	// ── Работы (submissions), pending/done ───────────────────────────────────

	public function test_pending_works_aggregates_submission_rows_across_groups(): void {
		$this->groups->method( 'findByTeacherId' )->willReturn( array( $this->group( 7, 'Группа А' ), $this->group( 8, 'Группа Б' ) ) );
		$this->submissions->method( 'summaryByGroups' )->with( array( 7, 8 ), array( 'submitted' ) )->willReturn( array(
			array( 'work_id' => 3, 'work_type' => 'practice', 'group_id' => 7, 'cnt' => 2, 'latest_at' => '2026-06-01 09:00:00' ),
			array( 'work_id' => 3, 'work_type' => 'practice', 'group_id' => 8, 'cnt' => 1, 'latest_at' => '2026-06-02 10:00:00' ),
		) );
		$this->works->method( 'get' )->with( 3 )->willReturn( $this->work( 3, 'ДЗ №1' ) );
		$this->attempts->method( 'listByGroupsForGradebook' )->willReturn( array() );

		$items = $this->service->pendingWorks( 5, false, 'pending' );

		self::assertCount( 1, $items );
		self::assertSame( 'work', $items[0]['source_type'] );
		self::assertSame( 3, $items[0]['source_id'] );
		self::assertSame( 'ДЗ №1', $items[0]['title'] );
		self::assertSame( 3, $items[0]['count'] );
		self::assertSame( array( 7, 8 ), $items[0]['group_ids'] );
		self::assertSame( '2026-06-02 10:00:00', $items[0]['latest_at'] );
	}

	public function test_confirm_tab_never_queries_submissions(): void {
		$this->groups->method( 'findByTeacherId' )->willReturn( array( $this->group( 7, 'Группа А' ) ) );
		$this->submissions->expects( self::never() )->method( 'summaryByGroups' );
		$this->attempts->method( 'listByGroupsForGradebook' )->willReturn( array() );

		$this->service->pendingWorks( 5, false, 'confirm' );
	}

	// ── Экзамены (assessment_attempts) — pending/confirm/done ────────────────

	public function test_attempt_with_pending_answers_goes_to_pending_regardless_of_kind(): void {
		$this->groups->method( 'findByTeacherId' )->willReturn( array( $this->group( 7, 'Группа А' ) ) );
		$this->submissions->method( 'summaryByGroups' )->willReturn( array() );
		$this->attempts->method( 'listByGroupsForGradebook' )->willReturn( array( $this->attempt() ) );
		$this->answers->method( 'hasPendingAnswers' )->with( 1 )->willReturn( true );
		$this->assessments->method( 'get' )->willReturn( $this->assessment( 1, 'ОГЭ', AssessmentKind::OgeComputer ) );

		$pending = $this->service->pendingWorks( 5, false, 'pending' );
		self::assertCount( 1, $pending );
		self::assertSame( 'assessment', $pending[0]['source_type'] );

		$confirm = $this->service->pendingWorks( 5, false, 'confirm' );
		self::assertSame( array(), $confirm );
	}

	/** Решено (D): ЕГЭ-компьютерный без ручной проверки — «Ждут подтверждения», не «На проверке». */
	public function test_ege_computer_fully_graded_unapproved_attempt_goes_to_confirm(): void {
		$this->groups->method( 'findByTeacherId' )->willReturn( array( $this->group( 7, 'Группа А' ) ) );
		$this->submissions->method( 'summaryByGroups' )->willReturn( array() );
		$this->attempts->method( 'listByGroupsForGradebook' )->willReturn( array(
			$this->attempt( array( 'status' => 'graded' ) ),
		) );
		$this->answers->method( 'hasPendingAnswers' )->willReturn( false );
		$this->assessments->method( 'get' )->willReturn( $this->assessment( 1, 'ЕГЭ комп.', AssessmentKind::EgeComputer ) );

		$confirm = $this->service->pendingWorks( 5, false, 'confirm' );
		self::assertCount( 1, $confirm );
		self::assertSame( 1, $confirm[0]['source_id'] );

		self::assertSame( array(), $this->service->pendingWorks( 5, false, 'pending' ) );
		self::assertSame( array(), $this->service->pendingWorks( 5, false, 'done' ) );
	}

	/** ОГЭ (без отдельной кнопки «Утвердить», D18) — сразу в «Проверенные», минуя confirm. */
	public function test_oge_computer_fully_graded_attempt_goes_to_done_not_confirm(): void {
		$this->groups->method( 'findByTeacherId' )->willReturn( array( $this->group( 7, 'Группа А' ) ) );
		$this->submissions->method( 'summaryByGroups' )->willReturn( array() );
		$this->attempts->method( 'listByGroupsForGradebook' )->willReturn( array(
			$this->attempt( array( 'status' => 'graded' ) ),
		) );
		$this->answers->method( 'hasPendingAnswers' )->willReturn( false );
		$this->assessments->method( 'get' )->willReturn( $this->assessment( 1, 'ОГЭ комп.', AssessmentKind::OgeComputer ) );

		self::assertSame( array(), $this->service->pendingWorks( 5, false, 'confirm' ) );

		$done = $this->service->pendingWorks( 5, false, 'done' );
		self::assertCount( 1, $done );
	}

	/** ЕГЭ с approved_at заполненным — «Проверенные», не «Ждут подтверждения». */
	public function test_ege_computer_approved_attempt_goes_to_done(): void {
		$this->groups->method( 'findByTeacherId' )->willReturn( array( $this->group( 7, 'Группа А' ) ) );
		$this->submissions->method( 'summaryByGroups' )->willReturn( array() );
		$this->attempts->method( 'listByGroupsForGradebook' )->willReturn( array(
			$this->attempt( array( 'status' => 'graded', 'approved_at' => '2026-06-02 09:00:00' ) ),
		) );
		$this->answers->method( 'hasPendingAnswers' )->willReturn( false );
		$this->assessments->method( 'get' )->willReturn( $this->assessment( 1, 'ЕГЭ комп.', AssessmentKind::EgeComputer ) );

		self::assertSame( array(), $this->service->pendingWorks( 5, false, 'confirm' ) );
		self::assertCount( 1, $this->service->pendingWorks( 5, false, 'done' ) );
	}

	// ── submissionsFor() ──────────────────────────────────────────────────────

	public function test_submissions_for_work_resolves_student_and_group_names(): void {
		$this->groups->method( 'findByTeacherId' )->willReturn( array( $this->group( 7, 'Группа А' ) ) );
		$sub = new \Inc\DTO\Course\SubmissionDTO(
			id: 55, studentPersonId: 10, groupLessonId: 20, workId: 3, workType: WorkType::Practice,
			taskId: null, answerText: null, attachmentId: null, dueAt: null,
			status: \Inc\Enums\Course\SubmissionStatus::Submitted, score: null, maxScore: null, feedback: null,
			gradedByUserId: null, submittedAt: '2026-06-01 10:00:00', gradedAt: null, createdAt: '', updatedAt: '',
		);
		$this->submissions->method( 'listByWorkAndGroups' )->with( 3, array( 7 ), array( 'submitted' ) )->willReturn( array( $sub ) );
		$this->groupLessons->method( 'find' )->with( 20 )->willReturn( \Inc\DTO\Course\GroupLessonDTO::fromArray( array(
			'id' => 20, 'group_id' => 7, 'position' => 1,
		) ) );
		$this->groups->method( 'findById' )->with( 7 )->willReturn( $this->group( 7, 'Группа А' ) );
		$this->studentRecords->method( 'findAllByStudentAndGroup' )->with( 10, 7 )->willReturn( array(
			new StudentRecordDTO(
				id: 1, studentPersonId: 10, parentPersonId: 0, groupId: 7,
				snapshotLastName: 'Иванов', snapshotFirstName: 'Пётр', snapshotMiddleName: null,
				snapshotSchool: null, snapshotGrade: null, contractNo: null, contractDate: null,
				orderNo: null, orderDate: null, status: EnrollmentStatus::Active,
				enrolledAt: '2026-01-01', enrolledByUserId: null, expelledAt: null,
				expelledByUserId: null, expelReason: null, createdAt: '', updatedAt: '',
			),
		) );

		$rows = $this->service->submissionsFor( 'work', 3, 5, false, 'pending' );

		self::assertCount( 1, $rows );
		self::assertSame( 'submission', $rows[0]['source_type'] );
		self::assertSame( 55, $rows[0]['source_id'] );
		self::assertSame( 'Иванов Пётр', $rows[0]['student_name'] );
		self::assertSame( 'Группа А', $rows[0]['group_name'] );
	}

	public function test_submissions_for_unknown_source_type_is_empty(): void {
		$this->groups->method( 'findByTeacherId' )->willReturn( array( $this->group( 7, 'Группа А' ) ) );

		self::assertSame( array(), $this->service->submissionsFor( 'bogus', 3, 5, false, 'pending' ) );
	}
}
