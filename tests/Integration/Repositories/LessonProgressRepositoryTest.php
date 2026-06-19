<?php

declare( strict_types=1 );

namespace Integration\Repositories;

use FakeWpdb;
use Inc\DTO\Course\LessonProgressDTO;
use Inc\Enums\ProgressStatus;
use Inc\Repositories\WPDBRepositories\LessonProgressRepository;
use PHPUnit\Framework\TestCase;

class LessonProgressRepositoryTest extends TestCase {

	private FakeWpdb                 $wpdb;
	private LessonProgressRepository $repo;

	protected function setUp(): void {
		parent::setUp();
		$this->wpdb = new FakeWpdb();
		$this->repo = new LessonProgressRepository( $this->wpdb );
	}

	private function row( array $overrides = array() ): array {
		return array_merge( array(
			'id'                => 5,
			'student_person_id' => 9,
			'group_lesson_id'   => 3,
			'lesson_id'         => 7,
			'step_key'          => 's_a',
			'status'            => 'viewed',
			'completed_at'      => null,
			'created_at'        => '2024-01-01 00:00:00',
			'updated_at'        => '2024-01-01 00:00:00',
		), $overrides );
	}

	public function test_find_queries_by_unique_triple_and_maps_dto(): void {
		$this->wpdb->queueRow( $this->row() );

		$dto = $this->repo->find( 9, 3, 's_a' );

		self::assertInstanceOf( LessonProgressDTO::class, $dto );
		self::assertSame( ProgressStatus::Viewed, $dto->status );
		self::assertSame( 7, $dto->lessonId );

		$q = $this->wpdb->lastQuery();
		self::assertStringContainsString( 'student_person_id = 9', $q );
		self::assertStringContainsString( 'group_lesson_id = 3', $q );
		self::assertStringContainsString( "step_key = 's_a'", $q );
	}

	public function test_find_returns_null_when_absent(): void {
		$this->wpdb->queueRow( null );

		self::assertNull( $this->repo->find( 1, 2, 's_x' ) );
	}

	public function test_upsert_inserts_when_absent(): void {
		$this->wpdb->queueRow( null ); // find → строки нет

		$this->repo->upsert( 9, 3, 7, 's_a', ProgressStatus::Completed, '2024-02-02 12:00:00' );

		self::assertCount( 1, $this->wpdb->inserts );
		self::assertCount( 0, $this->wpdb->updates );
		$data = $this->wpdb->inserts[0]['data'];
		self::assertSame( 9, $data['student_person_id'] );
		self::assertSame( 's_a', $data['step_key'] );
		self::assertSame( 'completed', $data['status'] );
		self::assertSame( '2024-02-02 12:00:00', $data['completed_at'] );
	}

	public function test_upsert_updates_existing_by_id(): void {
		$this->wpdb->queueRow( $this->row( array( 'status' => 'available' ) ) ); // find → строка есть

		$id = $this->repo->upsert( 9, 3, 7, 's_a', ProgressStatus::Viewed );

		self::assertSame( 5, $id );
		self::assertCount( 0, $this->wpdb->inserts );
		self::assertCount( 1, $this->wpdb->updates );
		self::assertSame( array( 'id' => 5 ), $this->wpdb->updates[0]['where'] );
		self::assertSame( 'viewed', $this->wpdb->updates[0]['data']['status'] );
	}

	public function test_list_for_student_filters_by_student_and_group_lesson(): void {
		$this->wpdb->queueResults( array() );

		$this->repo->listForStudent( 9, 3 );

		$q = $this->wpdb->lastQuery();
		self::assertStringContainsString( 'student_person_id = 9', $q );
		self::assertStringContainsString( 'group_lesson_id = 3', $q );
	}

	public function test_list_by_group_lesson_maps_rows(): void {
		$this->wpdb->queueResults( array(
			$this->row( array( 'id' => 1, 'student_person_id' => 9,  'status' => 'completed' ) ),
			$this->row( array( 'id' => 2, 'student_person_id' => 10, 'status' => 'viewed' ) ),
		) );

		$list = $this->repo->listByGroupLesson( 3 );

		self::assertCount( 2, $list );
		self::assertSame( ProgressStatus::Completed, $list[0]->status );
		self::assertSame( 10, $list[1]->studentPersonId );
	}

	public function test_delete_by_group_lesson(): void {
		$this->repo->deleteByGroupLesson( 3 );

		self::assertCount( 1, $this->wpdb->deletes );
		self::assertSame( array( 'group_lesson_id' => 3 ), $this->wpdb->deletes[0]['where'] );
	}
}
