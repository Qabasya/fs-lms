<?php

declare( strict_types=1 );

namespace Integration\Repositories;

use FakeWpdb;
use Inc\DTO\Course\SubmissionInputDTO;
use Inc\Enums\SubmissionStatus;
use Inc\Repositories\WPDBRepositories\SubmissionRepository;
use PHPUnit\Framework\TestCase;

class SubmissionRepositoryTest extends TestCase {

	private FakeWpdb $wpdb;
	private SubmissionRepository $repo;

	protected function setUp(): void {
		parent::setUp();
		$this->wpdb = new FakeWpdb();
		$this->repo = new SubmissionRepository( $this->wpdb );
	}

	private function makeRow( array $overrides = [] ): array {
		return array_merge( [
			'id'                 => 1,
			'student_person_id'  => 10,
			'group_lesson_id'    => 5,
			'work_id'            => 3,
			'work_type'          => 'practice',
			'task_id'            => null,
			'answer_text'        => 'my answer',
			'attachment_id'      => null,
			'due_at'             => null,
			'status'             => 'submitted',
			'score'              => null,
			'max_score'          => null,
			'feedback'           => null,
			'graded_by_user_id'  => null,
			'submitted_at'       => '2024-03-01 10:00:00',
			'graded_at'          => null,
			'created_at'         => '2024-03-01 09:00:00',
			'updated_at'         => '2024-03-01 09:00:00',
		], $overrides );
	}

	// ── create ────────────────────────────────────────────────────────────────

	public function test_create_calls_insert_on_correct_table(): void {
		$dto = new SubmissionInputDTO(
			studentPersonId : 10,
			groupLessonId   : 5,
			workId          : 3,
			workType        : 'practice',
			taskId          : null,
			answerText      : 'answer',
			attachmentId    : null,
			dueAt           : null,
			status          : 'submitted',
			submittedAt     : '2024-03-01 10:00:00',
		);

		$this->repo->create( $dto );

		$insert = $this->wpdb->inserts[0];
		self::assertStringContainsString( 'fs_lms_submissions', $insert['table'] );
		self::assertSame( 10, $insert['data']['student_person_id'] );
		self::assertSame( 3,  $insert['data']['work_id'] );
	}

	// ── find ──────────────────────────────────────────────────────────────────

	public function test_find_maps_row_to_dto(): void {
		$this->wpdb->queueRow( $this->makeRow( [ 'id' => 7, 'score' => 85.5, 'status' => 'graded' ] ) );

		$dto = $this->repo->find( 7 );

		self::assertNotNull( $dto );
		self::assertSame( 7, $dto->id );
		self::assertSame( 85.5, $dto->score );
		self::assertSame( SubmissionStatus::Graded, $dto->status );
	}

	public function test_find_returns_null_when_no_row(): void {
		$this->wpdb->queueRow( null );

		$result = $this->repo->find( 999 );

		self::assertNull( $result );
	}

	public function test_find_query_uses_id_predicate(): void {
		$this->wpdb->queueRow( null );

		$this->repo->find( 42 );

		self::assertStringContainsString( 'id = 42', $this->wpdb->lastQuery() );
	}

	// ── findForWork ───────────────────────────────────────────────────────────

	public function test_findForWork_null_task_uses_IS_NULL(): void {
		$this->wpdb->queueRow( null );

		$this->repo->findForWork( 10, 5, 3, null );

		self::assertStringContainsString( 'task_id IS NULL', $this->wpdb->lastQuery() );
	}

	public function test_findForWork_with_task_uses_task_id_predicate(): void {
		$this->wpdb->queueRow( null );

		$this->repo->findForWork( 10, 5, 3, 77 );

		$q = $this->wpdb->lastQuery();
		self::assertStringNotContainsString( 'IS NULL', $q );
		self::assertStringContainsString( 'task_id = 77', $q );
	}

	public function test_findForWork_returns_dto_on_match(): void {
		$this->wpdb->queueRow( $this->makeRow( [ 'id' => 4, 'status' => 'returned' ] ) );

		$dto = $this->repo->findForWork( 10, 5, 3, null );

		self::assertNotNull( $dto );
		self::assertSame( SubmissionStatus::Returned, $dto->status );
	}

	// ── listByStudentAndGroupLesson ───────────────────────────────────────────

	public function test_list_by_student_and_group_lesson_maps_rows(): void {
		$this->wpdb->queueResults( [
			$this->makeRow( [ 'id' => 1 ] ),
			$this->makeRow( [ 'id' => 2 ] ),
		] );

		$result = $this->repo->listByStudentAndGroupLesson( 10, 5 );

		self::assertCount( 2, $result );
		self::assertSame( 1, $result[0]->id );
	}

	public function test_list_by_student_and_group_lesson_empty_on_no_rows(): void {
		$this->wpdb->queueResults( [] );

		$result = $this->repo->listByStudentAndGroupLesson( 10, 5 );

		self::assertSame( [], $result );
	}

	// ── listQueueByGroup ──────────────────────────────────────────────────────

	public function test_list_queue_by_group_joins_group_lessons(): void {
		$this->wpdb->queueResults( [] );

		$this->repo->listQueueByGroup( 7 );

		$q = $this->wpdb->lastQuery();
		self::assertStringContainsString( 'INNER JOIN', $q );
		self::assertStringContainsString( 'group_id = 7', $q );
	}

	public function test_list_queue_by_group_filters_by_status(): void {
		$this->wpdb->queueResults( [] );

		$this->repo->listQueueByGroup( 7, [ 'submitted', 'returned' ] );

		$q = $this->wpdb->lastQuery();
		self::assertStringContainsString( "'submitted'", $q );
		self::assertStringContainsString( "'returned'", $q );
	}

	public function test_list_queue_by_group_returns_empty_when_no_statuses(): void {
		$result = $this->repo->listQueueByGroup( 7, [] );

		self::assertSame( [], $result );
		self::assertEmpty( $this->wpdb->queries );
	}

	public function test_list_queue_by_group_maps_rows_to_dtos(): void {
		$this->wpdb->queueResults( [ $this->makeRow( [ 'id' => 9 ] ) ] );

		$result = $this->repo->listQueueByGroup( 7 );

		self::assertCount( 1, $result );
		self::assertSame( 9, $result[0]->id );
	}

	// ── listForGradebookByGroup ───────────────────────────────────────────────

	public function test_list_for_gradebook_by_group_filters_graded(): void {
		$this->wpdb->queueResults( [] );

		$this->repo->listForGradebookByGroup( 2 );

		$q = $this->wpdb->lastQuery();
		self::assertStringContainsString( "status = 'graded'", $q );
		self::assertStringContainsString( 'group_id = 2', $q );
	}

	// ── listForGradebookByStudent ─────────────────────────────────────────────

	public function test_list_for_gradebook_by_student_filters_graded(): void {
		$this->wpdb->queueResults( [] );

		$this->repo->listForGradebookByStudent( 10 );

		$q = $this->wpdb->lastQuery();
		self::assertStringContainsString( "status = 'graded'", $q );
		self::assertStringContainsString( 'student_person_id = 10', $q );
	}

	// ── update ────────────────────────────────────────────────────────────────

	public function test_update_calls_wpdb_update_with_id_where(): void {
		$this->repo->update( 5, [ 'status' => 'graded', 'score' => 90 ] );

		$upd = $this->wpdb->updates[0];
		self::assertStringContainsString( 'fs_lms_submissions', $upd['table'] );
		self::assertSame( [ 'id' => 5 ], $upd['where'] );
		self::assertSame( 'graded', $upd['data']['status'] );
	}
}
