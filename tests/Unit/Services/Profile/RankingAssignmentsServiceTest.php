<?php

declare( strict_types=1 );

namespace Tests\Unit\Services\Profile;

use Inc\DTO\Course\WorkDTO;
use Inc\Enums\Course\WorkType;
use Inc\Managers\Course\LessonManager;
use Inc\Managers\Course\WorkManager;
use Inc\Repositories\WPDBRepositories\LearnerRankingRepository;
use Inc\Services\Profile\RankingAssignmentsService;
use PHPUnit\Framework\TestCase;

class RankingAssignmentsServiceTest extends TestCase {
	public function test_extra_added_before_manual_open_is_counted_when_lesson_opens(): void {
		$repository = $this->createStub( LearnerRankingRepository::class );
		$repository->method( 'assignmentLessons' )->willReturn( array( array( 'student_person_id' => 1, 'group_lesson_id' => 3, 'lesson_id' => null, 'scheduled_at' => '2026-11-30 12:00:00', 'opened_at' => '2026-12-01 09:00:00', 'visibility' => 'open', 'work_ids_snapshot' => '[]', 'extra_work_ids' => '[7]' ) ) );
		$works = $this->createStub( WorkManager::class );
		$works->method( 'get' )->willReturn( new WorkDTO( 7, 'math', 'Работа', WorkType::Homework, array( 12 ), '', 1, 'publish' ) );
		$snapshots = $this->createStub( \Inc\Repositories\OptionsRepositories\RankingAssignmentSnapshotRepository::class );
		$snapshots->method( 'forLesson' )->willReturn( array( 7 => array( 'issued_at' => '2026-11-29 10:00:00', 'pending' => true ) ) );
		$service = new RankingAssignmentsService( $repository, $works, $this->createStub( LessonManager::class ), $snapshots );
		self::assertSame( array(), $service->tasks( 'math', '2026-09-01 00:00:00', '2026-12-01 00:00:00', '2026-12-05 00:00:00' ) );
		self::assertCount( 1, $service->tasks( 'math', '2026-12-01 00:00:00', '2027-03-01 00:00:00', '2026-12-05 00:00:00' ) );
	}
	public function test_legacy_extra_without_issue_date_uses_lesson_date(): void {
		$repository = $this->createStub( LearnerRankingRepository::class );
		$repository->method( 'assignmentLessons' )->willReturn( array( array( 'student_person_id' => 1, 'group_lesson_id' => 3, 'lesson_id' => null, 'scheduled_at' => '2026-11-30 12:00:00', 'opened_at' => '2026-12-01 09:00:00', 'visibility' => 'open', 'work_ids_snapshot' => '[]', 'extra_work_ids' => '[7]' ) ) );
		$works = $this->createStub( WorkManager::class );
		$works->method( 'get' )->willReturn( new WorkDTO( 7, 'math', 'Работа', WorkType::Homework, array( 12 ), '', 1, 'publish' ) );
		$service = new RankingAssignmentsService( $repository, $works, $this->createStub( LessonManager::class ) );
		self::assertCount( 1, $service->tasks( 'math', '2026-09-01 00:00:00', '2026-12-01 00:00:00', '2026-12-05 00:00:00' ) );
		self::assertSame( array(), $service->tasks( 'math', '2026-12-01 00:00:00', '2027-03-01 00:00:00', '2026-12-05 00:00:00' ) );
	}

	public function test_pending_extra_removed_before_open_is_not_assigned(): void {
		$repository = $this->createStub( LearnerRankingRepository::class );
		$repository->method( 'assignmentLessons' )->willReturn( array( array( 'student_person_id' => 1, 'group_lesson_id' => 3, 'lesson_id' => null, 'scheduled_at' => '2026-12-01 09:00:00', 'opened_at' => null, 'visibility' => 'open', 'work_ids_snapshot' => '[]', 'extra_work_ids' => '[]' ) ) );
		$snapshots = $this->createStub( \Inc\Repositories\OptionsRepositories\RankingAssignmentSnapshotRepository::class );
		$snapshots->method( 'forLesson' )->willReturn( array( 7 => array( 'issued_at' => '2026-11-30 10:00:00', 'pending' => true ) ) );
		$works = $this->createStub( WorkManager::class );
		$works->method( 'get' )->willReturn( new WorkDTO( 7, 'math', 'Работа', WorkType::Homework, array( 12 ), '', 1, 'publish' ) );
		$service = new RankingAssignmentsService( $repository, $works, $this->createStub( LessonManager::class ), $snapshots );
		self::assertSame( array(), $service->tasks( 'math', '2026-12-01 00:00:00', '2027-03-01 00:00:00', '2026-12-05 00:00:00' ) );
	}

	public function test_course_assessment_and_registered_exam_include_unsolved_tasks(): void {
		$repository = $this->createStub( LearnerRankingRepository::class );
		$repository->method( 'assignmentLessons' )->willReturn( array( array( 'student_person_id' => 1, 'group_lesson_id' => 3, 'lesson_id' => 9, 'scheduled_at' => '2026-11-30 12:00:00', 'opened_at' => null, 'visibility' => 'open', 'work_ids_snapshot' => '[]', 'extra_work_ids' => '[]' ) ) );
		$repository->method( 'assignedExams' )->willReturn( array( array( 'student_person_id' => 1, 'participation_id' => 5, 'assessment_id' => 8, 'issued_at_utc' => '2026-11-30 12:00:00' ) ) );
		$repository->method( 'assessmentEvents' )->willReturn( array( array( 'student_person_id' => 1, 'group_lesson_id' => 3, 'assessment_id' => 8, 'task_id' => 12 ), array( 'student_person_id' => 1, 'group_lesson_id' => null, 'exam_participation_id' => 5, 'assessment_id' => 8, 'task_id' => 12 ) ) );
		$lessons = $this->createStub( LessonManager::class );
		$lessons->method( 'get' )->willReturn( \Inc\DTO\Course\LessonDTO::fromArray( array( 'id' => 9, 'subject_key' => 'math', 'steps' => array( array( 'key' => 'a', 'type' => 'assessment', 'payload' => array( 'ref' => 8 ) ) ) ) ) );
		$assessments = $this->createStub( \Inc\Managers\Assessment\AssessmentManager::class );
		$assessments->method( 'get' )->willReturn( new \Inc\DTO\Assessment\AssessmentDTO( 8, 'math', 'Контрольная', array( 12, 13 ), 0, 1, 0, \Inc\Enums\Assessment\ScoringPolicy::Highest, 'publish', \Inc\Enums\Assessment\AssessmentKind::Control, array(), array() ) );
		$service = new RankingAssignmentsService( $repository, $this->createStub( WorkManager::class ), $lessons, null, $assessments );
		$tasks = $service->tasks( 'math', '2026-09-01 00:00:00', '2026-12-01 00:00:00', '2026-12-05 00:00:00' );
		self::assertCount( 4, $tasks );
		self::assertSame( array( true, false, true, false ), array_column( $tasks, 'credited' ) );
	}

	public function test_exam_uses_primary_score_and_published_maximum(): void {
		$repository = $this->createStub( LearnerRankingRepository::class );
		$repository->method( 'assignedExams' )->willReturn( array( array(
			'student_person_id' => 1, 'participation_id' => 5, 'assessment_id' => 8,
			'issued_at_utc' => '2026-11-30 12:00:00',
			'variant_snapshot' => '{"8":{"task_ids":[12,13],"primary_max":29}}',
			'total_score' => 15, 'approved_at' => '2026-12-02 12:00:00', 'attempt_status' => 'graded',
		) ) );
		$service = new RankingAssignmentsService( $repository, $this->createStub( WorkManager::class ), $this->createStub( LessonManager::class ), null, $this->createStub( \Inc\Managers\Assessment\AssessmentManager::class ) );
		$rows = $service->tasks( 'math', '2026-09-01 00:00:00', '2026-12-01 00:00:00', '2026-12-05 00:00:00' );
		$parts = ( new \Inc\Services\Profile\RankingAssignmentProjection() )->build( $rows );
		self::assertSame( 15.0, $parts[1]['assessment']['amount'] );
		self::assertSame( 29.0, $parts[1]['assessment']['max_amount'] );
	}

	public function test_unapproved_exam_keeps_maximum_without_disclosing_score(): void {
		$repository = $this->createStub( LearnerRankingRepository::class );
		$repository->method( 'assignedExams' )->willReturn( array( array(
			'student_person_id' => 1, 'participation_id' => 5, 'assessment_id' => 8,
			'issued_at_utc' => '2026-11-30 12:00:00', 'variant_snapshot' => '{"8":{"primary_max":29}}',
			'total_score' => 15, 'approved_at' => null, 'attempt_status' => 'graded',
		) ) );
		$service = new RankingAssignmentsService( $repository, $this->createStub( WorkManager::class ), $this->createStub( LessonManager::class ), null, $this->createStub( \Inc\Managers\Assessment\AssessmentManager::class ) );
		$parts = ( new \Inc\Services\Profile\RankingAssignmentProjection() )->build( $service->tasks( 'math', '2026-09-01 00:00:00', '2026-12-01 00:00:00', '2026-12-05 00:00:00' ) );
		self::assertSame( 0.0, $parts[1]['assessment']['amount'] );
		self::assertSame( 29.0, $parts[1]['assessment']['max_amount'] );
	}

	public function test_registered_exam_uses_published_variant_task_snapshot(): void {
		$repository = $this->createStub( LearnerRankingRepository::class );
		$repository->method( 'assignedExams' )->willReturn( array( array(
			'student_person_id' => 1, 'participation_id' => 5, 'assessment_id' => 8,
			'issued_at_utc' => '2026-11-30 12:00:00',
			'variant_snapshot' => '{"8":{"task_ids":[12,13]}}',
		) ) );
		$assessments = $this->createStub( \Inc\Managers\Assessment\AssessmentManager::class );
		$assessments->method( 'get' )->willReturn( new \Inc\DTO\Assessment\AssessmentDTO( 8, 'math', 'Контрольная', array( 14 ), 0, 1, 0, \Inc\Enums\Assessment\ScoringPolicy::Highest, 'publish', \Inc\Enums\Assessment\AssessmentKind::Control, array(), array() ) );
		$service = new RankingAssignmentsService( $repository, $this->createStub( WorkManager::class ), $this->createStub( LessonManager::class ), null, $assessments );
		$tasks = $service->tasks( 'math', '2026-09-01 00:00:00', '2026-12-01 00:00:00', '2026-12-05 00:00:00' );
		self::assertSame( array( 12, 13 ), array_column( $tasks, 'task_id' ) );
	}

	public function test_registered_exam_preserves_snapshot_if_assessment_deleted(): void {
		$repository = $this->createStub( LearnerRankingRepository::class );
		$repository->method( 'assignedExams' )->willReturn( array( array(
			'student_person_id' => 1, 'participation_id' => 5, 'assessment_id' => 8,
			'issued_at_utc' => '2026-11-30 12:00:00', 'variant_snapshot' => '{"8":{"task_ids":[12]}}',
		) ) );
		$service = new RankingAssignmentsService( $repository, $this->createStub( WorkManager::class ), $this->createStub( LessonManager::class ), null, $this->createStub( \Inc\Managers\Assessment\AssessmentManager::class ) );
		$tasks = $service->tasks( 'math', '2026-09-01 00:00:00', '2026-12-01 00:00:00', '2026-12-05 00:00:00' );
		self::assertSame( array( 12 ), array_column( $tasks, 'task_id' ) );
	}
	public function test_extra_work_uses_issue_date_and_frozen_tasks(): void {
		$repository = $this->createStub( LearnerRankingRepository::class );
		$repository->method( 'assignmentLessons' )->willReturn( array( array( 'student_person_id' => 1, 'group_lesson_id' => 3, 'lesson_id' => null, 'scheduled_at' => '2026-11-30 12:00:00', 'opened_at' => null, 'visibility' => 'open', 'work_ids_snapshot' => '[]', 'extra_work_ids' => '[7]' ) ) );
		$snapshots = $this->createStub( \Inc\Repositories\OptionsRepositories\RankingAssignmentSnapshotRepository::class );
		$snapshots->method( 'forLesson' )->willReturn( array( 7 => array( 'issued_at' => '2026-12-02 10:00:00', 'source' => 'homework', 'task_points' => array( 12 => 3 ) ) ) );
		$works = $this->createStub( WorkManager::class );
		$works->method( 'get' )->willReturn( new WorkDTO( 7, 'math', 'Работа', WorkType::Practice, array( 13 ), '', 1, 'publish' ) );
		$service = new RankingAssignmentsService( $repository, $works, $this->createStub( LessonManager::class ), $snapshots );
		self::assertSame( array(), $service->tasks( 'math', '2026-09-01 00:00:00', '2026-12-01 00:00:00', '2026-12-05 00:00:00' ) );
		$winter = $service->tasks( 'math', '2026-12-01 00:00:00', '2027-03-01 00:00:00', '2026-12-05 00:00:00' );
		self::assertCount( 1, $winter );
		self::assertSame( 12, $winter[0]['task_id'] );
		self::assertSame( 3.0, $winter[0]['task_points'] );
		self::assertSame( 'homework', $winter[0]['source'] );
	}

	public function test_unconfirmed_publication_marks_only_affected_maximum_unknown(): void {
		$repository = $this->createStub( LearnerRankingRepository::class );
		$repository->method( 'assignmentLessons' )->willReturn( array( array( 'student_person_id' => 1, 'group_lesson_id' => 3, 'lesson_id' => null, 'scheduled_at' => '2026-11-30 12:00:00', 'opened_at' => null, 'visibility' => 'open', 'work_ids_snapshot' => '[7]', 'extra_work_ids' => '[]' ) ) );
		$snapshots = $this->createStub( \Inc\Repositories\OptionsRepositories\RankingAssignmentSnapshotRepository::class );
		$snapshots->method( 'forLesson' )->willReturn( array( 7 => array( 'issued_at' => '2026-11-30 12:00:00', 'pending' => true, 'publication_unconfirmed' => true, 'source' => 'homework' ) ) );
		$repository->method( 'workEvents' )->willReturn( array( array( 'student_person_id' => 1, 'group_lesson_id' => 3, 'work_id' => 7, 'task_id' => 12, 'task_points' => 3, 'solved_at' => '2026-11-30 15:00:00' ), array( 'student_person_id' => 1, 'group_lesson_id' => 3, 'work_id' => 7, 'task_id' => 12, 'task_points' => 3, 'solved_at' => '2026-11-30 16:00:00' ) ) );
		$service = new RankingAssignmentsService( $repository, $this->createStub( WorkManager::class ), $this->createStub( LessonManager::class ), $snapshots );
		$tasks = $service->tasks( 'math', '2026-09-01 00:00:00', '2026-12-01 00:00:00', '2026-12-05 00:00:00' );
		self::assertCount( 2, $tasks );
		self::assertTrue( $tasks[0]['max_unknown'] );
		self::assertSame( 'homework', $tasks[0]['source'] );
		$projection = ( new \Inc\Services\Profile\RankingAssignmentProjection() )->build( $tasks );
		self::assertSame( 3.0, $projection[1]['homework']['amount'] );
		self::assertNull( $projection[1]['homework']['max_amount'] );
	}

	public function test_unconfirmed_cross_period_issue_does_not_duplicate_credit_in_future_periods(): void {
		$repository = $this->createStub( LearnerRankingRepository::class );
		$repository->method( 'assignmentLessons' )->willReturn( array( array( 'student_person_id' => 1, 'group_lesson_id' => 3, 'lesson_id' => null, 'scheduled_at' => '2026-11-30 12:00:00', 'opened_at' => null, 'visibility' => 'open', 'work_ids_snapshot' => '[7]', 'extra_work_ids' => '[]' ) ) );
		$snapshots = $this->createStub( \Inc\Repositories\OptionsRepositories\RankingAssignmentSnapshotRepository::class );
		$snapshots->method( 'forLesson' )->willReturn( array( 7 => array( 'issued_at' => '2026-11-30 23:59:59', 'pending' => true, 'publication_unconfirmed' => true, 'source' => 'homework' ) ) );
		$repository->method( 'workEvents' )->willReturn( array( array( 'student_person_id' => 1, 'group_lesson_id' => 3, 'work_id' => 7, 'task_id' => 12, 'task_points' => 3, 'solved_at' => '2026-12-02 10:00:00' ) ) );
		$service = new RankingAssignmentsService( $repository, $this->createStub( WorkManager::class ), $this->createStub( LessonManager::class ), $snapshots );
		foreach ( array( array( '2026-09-01 00:00:00', '2026-12-01 00:00:00' ), array( '2026-12-01 00:00:00', '2027-03-01 00:00:00' ) ) as $period ) {
			$rows = $service->tasks( 'math', $period[0], $period[1], '2027-06-01 00:00:00' );
			self::assertCount( 1, $rows );
			self::assertTrue( $rows[0]['score_unknown'] );
			self::assertFalse( $rows[0]['credited'] );
		}
		self::assertSame( array(), $service->tasks( 'math', '2027-03-01 00:00:00', '2027-06-01 00:00:00', '2027-06-01 00:00:00' ) );
	}

	public function test_frozen_issue_date_does_not_move_when_lesson_reopens_later(): void {
		$repository = $this->createStub( LearnerRankingRepository::class );
		$repository->method( 'assignmentLessons' )->willReturn( array( array( 'student_person_id' => 1, 'group_lesson_id' => 3, 'lesson_id' => null, 'scheduled_at' => '2026-11-30 12:00:00', 'opened_at' => '2026-12-02 09:00:00', 'visibility' => 'open', 'work_ids_snapshot' => '[7]', 'extra_work_ids' => '[]' ) ) );
		$snapshots = $this->createStub( \Inc\Repositories\OptionsRepositories\RankingAssignmentSnapshotRepository::class );
		$snapshots->method( 'forLesson' )->willReturn( array( 7 => array( 'issued_at' => '2026-11-30 12:00:00', 'source' => 'homework', 'task_points' => array( 12 => 3 ) ) ) );
		$repository->method( 'workEvents' )->willReturn( array( array( 'student_person_id' => 1, 'group_lesson_id' => 3, 'work_id' => 7, 'task_id' => 12 ) ) );
		$service = new RankingAssignmentsService( $repository, $this->createStub( WorkManager::class ), $this->createStub( LessonManager::class ), $snapshots );
		$autumn = $service->tasks( 'math', '2026-09-01 00:00:00', '2026-12-01 00:00:00', '2026-12-05 00:00:00' );
		self::assertCount( 1, $autumn );
		self::assertTrue( $autumn[0]['credited'] );
		self::assertSame( array(), $service->tasks( 'math', '2026-12-01 00:00:00', '2027-03-01 00:00:00', '2026-12-05 00:00:00' ) );
	}

	public function test_issued_history_in_hidden_pool_with_no_date_remains_ranked(): void {
		$repo = $this->createStub( LearnerRankingRepository::class );
		$repo->method( 'assignmentLessons' )->willReturn( array( array( 'student_person_id' => 1, 'group_lesson_id' => 3, 'lesson_id' => null, 'scheduled_at' => null, 'opened_at' => null, 'visibility' => 'hidden', 'work_ids_snapshot' => '[]', 'extra_work_ids' => '[]' ) ) );
		$repo->method( 'workEvents' )->willReturn( array( array( 'student_person_id' => 1, 'group_lesson_id' => 3, 'work_id' => 7, 'task_id' => 12 ) ) );
		$snapshots = $this->createStub( \Inc\Repositories\OptionsRepositories\RankingAssignmentSnapshotRepository::class );
		$snapshots->method( 'forLesson' )->willReturn( array( 7 => array( 'issued_at' => '2026-11-30 12:00:00', 'source' => 'homework', 'task_points' => array( 12 => 3 ) ) ) );
		$service = new RankingAssignmentsService( $repo, $this->createStub( WorkManager::class ), $this->createStub( LessonManager::class ), $snapshots );
		$rows = $service->tasks( 'math', '2026-09-01 00:00:00', '2026-12-01 00:00:00', '2026-12-05 00:00:00' );
		self::assertCount( 1, $rows );
		self::assertTrue( $rows[0]['credited'] );
		self::assertSame( 3.0, $rows[0]['task_points'] );
	}

	public function test_frozen_work_remains_assigned_after_its_post_is_deleted(): void {
		$repository = $this->createStub( LearnerRankingRepository::class );
		$repository->method( 'assignmentLessons' )->willReturn( array( array( 'student_person_id' => 1, 'group_lesson_id' => 3, 'lesson_id' => null, 'scheduled_at' => '2026-11-30 12:00:00', 'opened_at' => null, 'visibility' => 'open', 'work_ids_snapshot' => '[]', 'extra_work_ids' => '[]' ) ) );
		$snapshots = $this->createStub( \Inc\Repositories\OptionsRepositories\RankingAssignmentSnapshotRepository::class );
		$snapshots->method( 'forLesson' )->willReturn( array( 7 => array( 'issued_at' => '2026-12-02 10:00:00', 'source' => 'homework', 'task_points' => array( 12 => 3 ) ) ) );
		$service = new RankingAssignmentsService( $repository, $this->createStub( WorkManager::class ), $this->createStub( LessonManager::class ), $snapshots );
		$tasks = $service->tasks( 'math', '2026-12-01 00:00:00', '2027-03-01 00:00:00', '2026-12-05 00:00:00' );
		self::assertCount( 1, $tasks );
		self::assertSame( 3.0, $tasks[0]['task_points'] );
	}
	public function test_late_retries_are_credited_once_per_work_in_issue_period(): void {
		$repository = $this->createStub( LearnerRankingRepository::class );
		$repository->method( 'assignmentLessons' )->willReturn( array( array( 'student_person_id' => 1, 'group_lesson_id' => 3, 'lesson_id' => null, 'scheduled_at' => '2026-11-30 12:00:00', 'opened_at' => null, 'visibility' => 'open', 'work_ids_snapshot' => '[7,8]', 'extra_work_ids' => '[]' ) ) );
		$repository->method( 'workEvents' )->willReturnCallback( static function ( string $subject, string $from, string $until ): array {
			$events = array(
				array( 'student_person_id' => 1, 'group_lesson_id' => 3, 'work_id' => 7, 'task_id' => 12, 'solved_at' => '2026-12-02 10:00:00' ),
				array( 'student_person_id' => 1, 'group_lesson_id' => 3, 'work_id' => 7, 'task_id' => 12, 'solved_at' => '2026-12-03 10:00:00' ),
				array( 'student_person_id' => 1, 'group_lesson_id' => 3, 'work_id' => 8, 'task_id' => 12, 'solved_at' => '2026-12-04 10:00:00' ),
				array( 'student_person_id' => 2, 'group_lesson_id' => 3, 'work_id' => 8, 'task_id' => 12, 'solved_at' => '2026-12-02 10:00:00' ),
			);
			return array_values( array_filter( $events, static fn( array $event ): bool => $event['solved_at'] >= $from && $event['solved_at'] < $until ) );
		} );
		$works = $this->createStub( WorkManager::class );
		$works->method( 'get' )->willReturnCallback( static fn( int $id ): WorkDTO => new WorkDTO( $id, 'math', 'Работа', WorkType::Practice, array( 12 ), '', 1, 'publish', 0, array( 12 => 2 ) ) );
		$service = new RankingAssignmentsService( $repository, $works, $this->createStub( LessonManager::class ) );
		$projection = new \Inc\Services\Profile\RankingAssignmentProjection();
		$before = $projection->build( $service->tasks( 'math', '2026-09-01 00:00:00', '2026-12-01 00:00:00', '2026-12-01 12:00:00' ) );
		self::assertSame( 0.0, $before[1]['practice']['amount'] );
		self::assertSame( 4.0, $before[1]['practice']['max_amount'] );
		$after = $projection->build( $service->tasks( 'math', '2026-09-01 00:00:00', '2026-12-01 00:00:00', '2026-12-05 00:00:00' ) );
		self::assertSame( 4.0, $after[1]['practice']['amount'] );
		self::assertSame( 4.0, $after[1]['practice']['max_amount'] );
		self::assertSame( array(), $service->tasks( 'math', '2026-12-01 00:00:00', '2027-03-01 00:00:00', '2026-12-05 00:00:00' ) );
	}

	public function test_assessment_repeated_in_continuation_is_credited_once_to_original_period(): void {
		$repo = $this->createStub( LearnerRankingRepository::class );
		$base = array( 'student_person_id' => 1, 'opened_at' => null, 'visibility' => 'open', 'work_ids_snapshot' => '[]', 'extra_work_ids' => '[]', 'lesson_id' => 9 );
		$repo->method( 'assignmentLessons' )->willReturn( array( $base + array( 'group_lesson_id' => 3, 'continued_from_id' => null, 'scheduled_at' => '2026-11-30 12:00:00' ), $base + array( 'group_lesson_id' => 4, 'continued_from_id' => 3, 'scheduled_at' => '2026-12-02 12:00:00' ) ) );
		$repo->method( 'assessmentEvents' )->willReturn( array( array( 'student_person_id' => 1, 'group_lesson_id' => 4, 'assessment_id' => 20, 'task_id' => 12 ), array( 'student_person_id' => 1, 'group_lesson_id' => 3, 'assessment_id' => 20, 'task_id' => 12 ) ) );
		$lessons = $this->createStub( LessonManager::class );
		$lessons->method( 'get' )->willReturn( \Inc\DTO\Course\LessonDTO::fromArray( array( 'id' => 9, 'subject_key' => 'math', 'steps' => array( array( 'key' => 'a', 'type' => 'assessment', 'payload' => array( 'ref' => 20 ) ) ) ) ) );
		$assessments = $this->createStub( \Inc\Managers\Assessment\AssessmentManager::class );
		$assessments->method( 'get' )->willReturn( new \Inc\DTO\Assessment\AssessmentDTO( 20, 'math', 'Контрольная', array( 12 ), 0, 1, 0, \Inc\Enums\Assessment\ScoringPolicy::Highest, 'publish', \Inc\Enums\Assessment\AssessmentKind::Control, array(), array() ) );
		$service = new RankingAssignmentsService( $repo, $this->createStub( WorkManager::class ), $lessons, null, $assessments );
		$autumn = $service->tasks( 'math', '2026-09-01 00:00:00', '2026-12-01 00:00:00', '2026-12-05 00:00:00' );
		self::assertCount( 1, $autumn );
		self::assertTrue( $autumn[0]['credited'] );
		self::assertSame( array(), $service->tasks( 'math', '2026-12-01 00:00:00', '2027-03-01 00:00:00', '2026-12-05 00:00:00' ) );
	}

	public function test_assessment_added_to_continuation_uses_continuation_period_and_original_progress(): void {
		$repo = $this->createStub( LearnerRankingRepository::class );
		$base = array( 'student_person_id' => 1, 'opened_at' => null, 'visibility' => 'open', 'work_ids_snapshot' => '[]', 'extra_work_ids' => '[]' );
		$repo->method( 'assignmentLessons' )->willReturn( array( $base + array( 'group_lesson_id' => 3, 'continued_from_id' => null, 'lesson_id' => 8, 'scheduled_at' => '2026-11-30 12:00:00' ), $base + array( 'group_lesson_id' => 4, 'continued_from_id' => 3, 'lesson_id' => 9, 'scheduled_at' => '2026-12-02 12:00:00' ) ) );
		$repo->method( 'assessmentEvents' )->willReturn( array( array( 'student_person_id' => 1, 'group_lesson_id' => 4, 'assessment_id' => 20, 'task_id' => 12 ) ) );
		$lessons = $this->createStub( LessonManager::class );
		$lessons->method( 'get' )->willReturnCallback( static fn( int $id ): \Inc\DTO\Course\LessonDTO => \Inc\DTO\Course\LessonDTO::fromArray( array( 'id' => $id, 'subject_key' => 'math', 'steps' => 9 === $id ? array( array( 'key' => 'a', 'type' => 'assessment', 'payload' => array( 'ref' => 20 ) ) ) : array() ) ) );
		$assessments = $this->createStub( \Inc\Managers\Assessment\AssessmentManager::class );
		$assessments->method( 'get' )->willReturn( new \Inc\DTO\Assessment\AssessmentDTO( 20, 'math', 'Контрольная', array( 12, 13 ), 0, 1, 0, \Inc\Enums\Assessment\ScoringPolicy::Highest, 'publish', \Inc\Enums\Assessment\AssessmentKind::Control, array(), array() ) );
		$service = new RankingAssignmentsService( $repo, $this->createStub( WorkManager::class ), $lessons, null, $assessments );
		self::assertSame( array(), $service->tasks( 'math', '2026-09-01 00:00:00', '2026-12-01 00:00:00', '2026-12-05 00:00:00' ) );
		$winter = $service->tasks( 'math', '2026-12-01 00:00:00', '2027-03-01 00:00:00', '2026-12-05 00:00:00' );
		self::assertCount( 2, $winter );
		self::assertSame( array( 3, 3 ), array_column( $winter, 'group_lesson_id' ) );
		self::assertSame( array( true, false ), array_column( $winter, 'credited' ) );
	}

	public function test_continuation_credits_original_work_once_in_original_period(): void {
		$repo = $this->createStub( LearnerRankingRepository::class );
		$base = array( 'student_person_id' => 1, 'lesson_id' => null, 'opened_at' => null, 'visibility' => 'open', 'work_ids_snapshot' => '[7]', 'extra_work_ids' => '[]' );
		$repo->method( 'assignmentLessons' )->willReturn( array( $base + array( 'group_lesson_id' => 3, 'continued_from_id' => null, 'scheduled_at' => '2026-11-30 12:00:00' ), $base + array( 'group_lesson_id' => 4, 'continued_from_id' => 3, 'scheduled_at' => '2026-12-02 12:00:00' ) ) );
		$repo->method( 'workEvents' )->willReturn( array( array( 'student_person_id' => 1, 'group_lesson_id' => 3, 'work_id' => 7, 'task_id' => 12 ) ) );
		$works = $this->createStub( WorkManager::class );
		$works->method( 'get' )->willReturn( new WorkDTO( 7, 'math', 'Работа', WorkType::Practice, array( 12 ), '', 1, 'publish' ) );
		$service = new RankingAssignmentsService( $repo, $works, $this->createStub( LessonManager::class ) );
		$autumn = $service->tasks( 'math', '2026-09-01 00:00:00', '2026-12-01 00:00:00', '2026-12-05 00:00:00' );
		self::assertCount( 1, $autumn );
		self::assertTrue( $autumn[0]['credited'] );
		self::assertSame( array(), $service->tasks( 'math', '2026-12-01 00:00:00', '2027-03-01 00:00:00', '2026-12-05 00:00:00' ) );
	}

	public static function seasonBoundaries(): array {
		return array(
			'зима' => array( '2026-11-30 23:59:59', '2026-09-01 00:00:00', '2026-12-01 00:00:00', '2027-03-01 00:00:00' ),
			'весна' => array( '2027-02-28 23:59:59', '2026-12-01 00:00:00', '2027-03-01 00:00:00', '2027-06-01 00:00:00' ),
			'високосный февраль' => array( '2028-02-29 23:59:59', '2027-12-01 00:00:00', '2028-03-01 00:00:00', '2028-06-01 00:00:00' ),
			'лето' => array( '2027-05-31 23:59:59', '2027-03-01 00:00:00', '2027-06-01 00:00:00', '2027-09-01 00:00:00' ),
			'осень' => array( '2027-08-31 23:59:59', '2027-06-01 00:00:00', '2027-09-01 00:00:00', '2027-12-01 00:00:00' ),
		);
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'seasonBoundaries' )]
	public function test_late_solution_counts_in_assignment_season_at_every_boundary( string $issued, string $from, string $until, string $nextUntil ): void {
		$repo = $this->createStub( LearnerRankingRepository::class );
		$repo->method( 'assignmentLessons' )->willReturn( array( array( 'student_person_id' => 1, 'group_lesson_id' => 3, 'lesson_id' => null, 'scheduled_at' => $issued, 'opened_at' => null, 'visibility' => 'open', 'work_ids_snapshot' => '[7]', 'extra_work_ids' => '[]' ) ) );
		$repo->method( 'workEvents' )->willReturn( array( array( 'student_person_id' => 1, 'group_lesson_id' => 3, 'work_id' => 7, 'task_id' => 12 ) ) );
		$works = $this->createStub( WorkManager::class );
		$works->method( 'get' )->willReturn( new WorkDTO( 7, 'math', 'Работа', WorkType::Practice, array( 12 ), '', 1, 'publish' ) );
		$service = new RankingAssignmentsService( $repo, $works, $this->createStub( LessonManager::class ) );
		$now = ( new \DateTimeImmutable( $until ) )->modify( '+1 day' )->format( 'Y-m-d H:i:s' );
		$rows = $service->tasks( 'math', $from, $until, $now );
		self::assertCount( 1, $rows );
		self::assertTrue( $rows[0]['credited'] );
		self::assertSame( array(), $service->tasks( 'math', $until, $nextUntil, $now ) );
	}

	public function test_old_work_uses_lesson_date_and_one_credit_despite_retries(): void {
		$repository = $this->createStub( LearnerRankingRepository::class );
		$repository->method( 'assignmentLessons' )->willReturn( array( array( 'student_person_id' => 1, 'group_lesson_id' => 3, 'lesson_id' => null, 'scheduled_at' => '2026-11-30 12:00:00', 'opened_at' => null, 'visibility' => 'open', 'work_ids_snapshot' => '[7]', 'extra_work_ids' => '[]' ) ) );
		$repository->method( 'workEvents' )->willReturn( array_fill( 0, 2, array( 'student_person_id' => 1, 'group_lesson_id' => 3, 'work_id' => 7, 'task_id' => 12 ) ) );
		$works = $this->createStub( WorkManager::class );
		$works->method( 'get' )->willReturn( new WorkDTO( 7, 'math', 'Работа', WorkType::Practice, array( 12, 13 ), '', 1, 'publish', 0, array( 12 => 2, 13 => 1 ) ) );
		$service = new RankingAssignmentsService( $repository, $works, $this->createStub( LessonManager::class ) );
		$autumn = $service->tasks( 'math', '2026-09-01 00:00:00', '2026-12-01 00:00:00', '2026-12-05 00:00:00' );
		self::assertCount( 2, $autumn );
		self::assertTrue( $autumn[0]['credited'] );
		self::assertFalse( $autumn[1]['credited'] );
		self::assertSame( array(), $service->tasks( 'math', '2026-12-01 00:00:00', '2027-03-01 00:00:00', '2026-12-05 00:00:00' ) );
	}
}
