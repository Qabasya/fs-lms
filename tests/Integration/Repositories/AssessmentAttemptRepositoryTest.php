<?php

declare( strict_types=1 );

namespace Integration\Repositories;

use FakeWpdb;
use Inc\Repositories\WPDBRepositories\AssessmentAttemptRepository;
use PHPUnit\Framework\TestCase;

/**
 * D18: approve() пишет approved_at/approved_by_user_id — отдельный от status флаг
 * подтверждения учителем (см. AttemptRevealPolicy).
 */
class AssessmentAttemptRepositoryTest extends TestCase {

	private FakeWpdb $wpdb;
	private AssessmentAttemptRepository $repo;

	protected function setUp(): void {
		parent::setUp();
		$this->wpdb = new FakeWpdb();
		$this->repo = new AssessmentAttemptRepository( $this->wpdb );
	}

	public function test_create_writes_exam_context(): void {
		$this->repo->create( new \Inc\DTO\Assessment\AttemptInputDTO(
			assessmentId: 500, studentPersonId: null, groupId: null, attemptNumber: 1,
			startedAt: '2026-03-12 10:00:00', deadlineAt: '2026-03-12 13:55:00', examParticipationId: 7, examRegistrationId: 20
		) );

		$data = $this->wpdb->inserts[0]['data'];
		self::assertSame( 7, $data['exam_participation_id'] );
		self::assertSame( 20, $data['exam_registration_id'] );
		self::assertNull( $data['student_person_id'], 'Гостевая попытка без ученика.' );
	}

	public function test_create_of_course_attempt_leaves_exam_context_empty(): void {
		$this->repo->create( new \Inc\DTO\Assessment\AttemptInputDTO(
			assessmentId: 500, studentPersonId: 11, groupId: 3, attemptNumber: 1, startedAt: '2026-03-12 10:00:00', deadlineAt: '2026-03-12 11:00:00'
		) );

		$data = $this->wpdb->inserts[0]['data'];
		self::assertNull( $data['exam_participation_id'] );
		self::assertNull( $data['exam_registration_id'] );
	}

	public function test_approve_writes_approved_at_and_user(): void {
		$this->repo->approve( 5, 42, '2026-01-01 12:00:00' );

		self::assertCount( 1, $this->wpdb->updates );
		self::assertSame( [ 'id' => 5 ], $this->wpdb->updates[0]['where'] );
		self::assertSame( '2026-01-01 12:00:00', $this->wpdb->updates[0]['data']['approved_at'] );
		self::assertSame( 42, $this->wpdb->updates[0]['data']['approved_by_user_id'] );
	}

	/** D3: агрегация по нескольким группам сразу (вкладка «Работы»). */
	public function test_list_by_groups_for_gradebook_filters_by_groups_and_status(): void {
		$this->wpdb->queueResults( [] );

		$this->repo->listByGroupsForGradebook( [ 3, 7 ] );

		$q = $this->wpdb->lastQuery();
		self::assertStringContainsString( 'group_id IN (3, 7)', $q );
		self::assertStringContainsString( "status IN ('graded','submitted')", $q );
	}

	public function test_list_by_groups_for_gradebook_empty_on_no_groups(): void {
		self::assertSame( [], $this->repo->listByGroupsForGradebook( [] ) );
		self::assertEmpty( $this->wpdb->queries );
	}

	public function test_find_any_active_compares_deadline_with_given_local_time_not_db_now(): void {
		$this->wpdb->queueRow( null );

		$this->repo->findAnyActive( 12, '2026-10-04 01:49:47' );

		$q = $this->wpdb->lastQuery();
		self::assertStringContainsString( "deadline_at > '2026-10-04 01:49:47'", $q );
		self::assertStringNotContainsString( 'NOW()', $q );
	}

	public function test_find_any_active_requires_in_progress_status(): void {
		// Сдача и автозавершение снимают блокировку именно сменой статуса.
		$this->wpdb->queueRow( null );

		$this->repo->findAnyActive( 12, '2026-10-04 01:49:47' );

		self::assertStringContainsString( "status = 'in_progress'", $this->wpdb->lastQuery() );
	}

	public function test_find_any_active_includes_exam_attempts(): void {
		// Блокировка контента и старт нового экзамена обязаны видеть и экзаменную попытку (6.4).
		$this->wpdb->queueRow( null );

		$this->repo->findAnyActive( 12, '2026-10-04 01:49:47' );

		self::assertStringNotContainsString( 'exam_participation_id', $this->wpdb->lastQuery() );
	}

	/** Условие исключения экзаменных попыток стоит в последнем выполненном запросе. */
	private function assertExcludesExamAttempts(): void {
		self::assertStringContainsString( 'exam_participation_id IS NULL', $this->wpdb->lastQuery() );
	}

	public function test_find_active_excludes_exam_attempts(): void {
		$this->wpdb->queueRow( null );
		$this->repo->findActive( 12, 5 );
		$this->assertExcludesExamAttempts();
	}

	public function test_find_last_submitted_excludes_exam_attempts(): void {
		$this->wpdb->queueRow( null );
		$this->repo->findLastSubmitted( 12, 5 );
		$this->assertExcludesExamAttempts();
	}

	public function test_list_by_student_and_assessment_excludes_exam_attempts(): void {
		$this->wpdb->queueResults( array() );
		$this->repo->listByStudentAndAssessment( 12, 5 );
		$this->assertExcludesExamAttempts();
	}

	public function test_count_by_assessment_and_student_excludes_exam_attempts(): void {
		$this->wpdb->queueVar( 0 );
		$this->repo->countByAssessmentAndStudent( 5, 12 );
		$this->assertExcludesExamAttempts();
	}

	public function test_list_by_group_for_gradebook_excludes_exam_attempts(): void {
		$this->wpdb->queueResults( array() );
		$this->repo->listByGroupForGradebook( 3 );
		$this->assertExcludesExamAttempts();
	}

	public function test_list_by_groups_for_gradebook_excludes_exam_attempts(): void {
		$this->wpdb->queueResults( array() );
		$this->repo->listByGroupsForGradebook( array( 3, 7 ) );
		$this->assertExcludesExamAttempts();
	}

	public function test_list_by_student_for_gradebook_excludes_exam_attempts(): void {
		// Обязательно: без условия экзамен попал бы в «Мои оценки» ученика.
		$this->wpdb->queueResults( array() );
		$this->repo->listByStudentForGradebook( 12 );
		$this->assertExcludesExamAttempts();
	}

	public function test_list_by_group_lesson_excludes_exam_attempts(): void {
		$this->wpdb->queueResults( array() );
		$this->repo->listByGroupLesson( 100 );
		$this->assertExcludesExamAttempts();
	}

	public function test_expire_overdue_excludes_exam_attempts(): void {
		// Обязательно: экзаменную попытку по дедлайну завершает сервис попытки экзамена (оценка, outbox), а не простая пометка «expired».
		$this->repo->expireOverdue();
		$this->assertExcludesExamAttempts();
	}

	public function test_next_attempt_number_counts_exam_attempts(): void {
		// Номер растёт по всем попыткам человека: действует уникальный ключ (работа, ученик, номер).
		$this->wpdb->queueVar( 3 );

		self::assertSame( 4, $this->repo->nextAttemptNumber( 12, 5 ) );
		self::assertStringNotContainsString( 'exam_participation_id', $this->wpdb->lastQuery() );
	}

	public function test_lookup_by_id_does_not_filter_exam_attempts(): void {
		// Работа по идентификатору (find/update/approve/delete) видит и экзаменные попытки — их ведёт сервис экзамена.
		$this->wpdb->queueRow( null );

		$this->repo->find( 9 );

		self::assertStringNotContainsString( 'exam_participation_id', $this->wpdb->lastQuery() );
	}
}
