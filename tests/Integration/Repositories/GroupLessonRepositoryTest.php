<?php

declare( strict_types=1 );

namespace Integration\Repositories;

use FakeWpdb;
use Inc\DTO\Course\GroupLessonInputDTO;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use PHPUnit\Framework\TestCase;

class GroupLessonRepositoryTest extends TestCase {

	private FakeWpdb $wpdb;
	private GroupLessonRepository $repo;

	protected function setUp(): void {
		parent::setUp();
		$this->wpdb = new FakeWpdb();
		$this->repo = new GroupLessonRepository( $this->wpdb );
	}

	public function test_list_by_group_orders_by_position(): void {
		$this->wpdb->queueResults( [] );

		$this->repo->listByGroup( 3 );

		self::assertStringContainsString( 'ORDER BY position ASC', $this->wpdb->lastQuery() );
		self::assertStringContainsString( 'group_id = 3', $this->wpdb->lastQuery() );
	}

	public function test_list_open_by_group_filters_visibility(): void {
		$this->wpdb->queueResults( [] );

		$this->repo->listOpenByGroup( 5 );

		$q = $this->wpdb->lastQuery();
		self::assertStringContainsString( "visibility IN ('open','archived')", $q );
		self::assertStringContainsString( 'group_id = 5', $q );
	}

	public function test_find_maps_row_to_dto(): void {
		$this->wpdb->queueRow( [
			'id'               => 10,
			'group_id'         => 3,
			'lesson_id'        => 7,
			'position'         => 1,
			'work_ids_snapshot'=> '[1,2]',
			'extra_work_ids'   => '[]',
			'scheduled_at'     => null,
			'teacher_user_id'  => null,
			'visibility'       => 'open',
			'opened_at'        => '2024-01-15 10:00:00',
			'homework_due_at'  => null,
			'allow_late'       => 1,
			'recording_url'    => null,
			'created_by_user_id' => 1,
			'updated_by_user_id' => null,
		] );

		$dto = $this->repo->find( 10 );

		self::assertNotNull( $dto );
		self::assertSame( 10, $dto->id );
		self::assertSame( 3, $dto->groupId );
		self::assertSame( 7, $dto->lessonId );
		self::assertSame( [ 1, 2 ], $dto->workIdsSnapshot );
		self::assertSame( 'open', $dto->visibility );
		self::assertSame( '2024-01-15 10:00:00', $dto->openedAt );
		self::assertTrue( $dto->isPublished() );
	}

	public function test_find_returns_null_when_not_found(): void {
		$this->wpdb->queueRow( null );

		self::assertNull( $this->repo->find( 99 ) );
	}

	public function test_add_inserts_into_correct_table(): void {
		$dto = new GroupLessonInputDTO(
			groupId         : 3,
			lessonId        : 7,
			position        : 0,
			createdByUserId : 1,
		);

		$this->repo->add( $dto );

		self::assertCount( 1, $this->wpdb->inserts );
		self::assertStringContainsString( 'fs_lms_group_lessons', $this->wpdb->inserts[0]['table'] );
		self::assertSame( 3, $this->wpdb->inserts[0]['data']['group_id'] );
	}

	public function test_next_position_returns_max_plus_one(): void {
		$this->wpdb->queueVar( '4' );

		self::assertSame( 5, $this->repo->nextPosition( 3 ) );
	}

	public function test_next_position_returns_zero_when_empty(): void {
		$this->wpdb->queueVar( null );

		self::assertSame( 0, $this->repo->nextPosition( 3 ) );
	}

	public function test_reorder_issues_one_update_per_id(): void {
		$this->repo->reorder( 5, [ 10, 20, 30 ] );

		self::assertCount( 3, $this->wpdb->updates );
		self::assertSame( 0, $this->wpdb->updates[0]['data']['position'] );
		self::assertSame( 1, $this->wpdb->updates[1]['data']['position'] );
		self::assertSame( 2, $this->wpdb->updates[2]['data']['position'] );
	}

	public function test_set_visibility_with_opened_at_includes_it_in_update(): void {
		$this->repo->setVisibility( 42, 'open', '2024-06-01 09:00:00' );

		self::assertCount( 1, $this->wpdb->updates );
		self::assertSame( 'open', $this->wpdb->updates[0]['data']['visibility'] );
		self::assertSame( '2024-06-01 09:00:00', $this->wpdb->updates[0]['data']['opened_at'] );
	}

	public function test_set_visibility_without_opened_at_omits_the_field(): void {
		$this->repo->setVisibility( 42, 'hidden', null );

		self::assertCount( 1, $this->wpdb->updates );
		self::assertArrayNotHasKey( 'opened_at', $this->wpdb->updates[0]['data'] );
	}

	public function test_set_work_ids_snapshot_json_encodes(): void {
		$this->repo->setWorkIdsSnapshot( 42, [ 10, 20 ] );

		self::assertSame( '[10,20]', $this->wpdb->updates[0]['data']['work_ids_snapshot'] );
	}

	public function test_set_extra_work_ids_json_encodes(): void {
		$this->repo->setExtraWorkIds( 42, [ 30 ] );

		self::assertSame( '[30]', $this->wpdb->updates[0]['data']['extra_work_ids'] );
	}

	public function test_remove_deletes_by_id(): void {
		$this->repo->remove( 42 );

		self::assertCount( 1, $this->wpdb->deletes );
		self::assertSame( [ 'id' => 42 ], $this->wpdb->deletes[0]['where'] );
	}

	public function test_count_usage_by_lesson_builds_correct_query(): void {
		$this->wpdb->queueVar( 3 );

		$count = $this->repo->countUsageByLesson( 7 );

		self::assertSame( 3, $count );
		self::assertStringContainsString( 'lesson_id = 7', $this->wpdb->lastQuery() );
	}

	public function test_delete_all_by_group_uses_group_id(): void {
		$this->repo->deleteAllByGroup( 5 );

		self::assertCount( 1, $this->wpdb->deletes );
		self::assertSame( [ 'group_id' => 5 ], $this->wpdb->deletes[0]['where'] );
	}

	public function test_json_roundtrip_null_snapshot_means_unpublished(): void {
		$this->wpdb->queueRow( [
			'id'               => 1,
			'group_id'         => 1,
			'lesson_id'        => 1,
			'position'         => 0,
			'work_ids_snapshot'=> null,
			'extra_work_ids'   => null,
			'scheduled_at'     => null,
			'teacher_user_id'  => null,
			'visibility'       => 'hidden',
			'opened_at'        => null,
			'homework_due_at'  => null,
			'allow_late'       => 1,
			'recording_url'    => null,
			'created_by_user_id' => null,
			'updated_by_user_id' => null,
		] );

		$dto = $this->repo->find( 1 );

		self::assertFalse( $dto->isPublished() );
		self::assertSame( [], $dto->extraWorkIds );
	}

	/* ── applySlots (T11.6: раскладка по status) ─────────────────────────── */

	/** Пиннутые и индивидуальные не двигаются и слот не тратят. */
	public function test_apply_slots_skips_pinned_and_individual(): void {
		$this->wpdb->queueResults( [
			$this->slotRow( 1, 0, [ 'is_pinned' => 1 ] ),
			$this->slotRow( 2, 1, [ 'kind' => 'individual' ] ),
			$this->slotRow( 3, 2 ),
		] );

		$this->repo->applySlots( 5, [ $this->slot( '2026-09-01 10:00:00' ), $this->slot( '2026-09-03 10:00:00' ) ] );

		self::assertCount( 1, $this->wpdb->updates );
		self::assertSame( 3, $this->wpdb->updates[0]['where']['id'] );
		self::assertSame( '2026-09-01 10:00:00', $this->wpdb->updates[0]['data']['scheduled_at'] );
	}

	/** held фиксирует свою дату (не переписывается), но занимает слот последовательности. */
	public function test_apply_slots_held_keeps_date_and_consumes_slot(): void {
		$this->wpdb->queueResults( [
			$this->slotRow( 1, 0, [ 'status' => 'held', 'scheduled_at' => '2026-09-01 10:00:00' ] ),
			$this->slotRow( 2, 1 ), // scheduled
		] );

		$this->repo->applySlots( 5, [ $this->slot( '2026-09-01 10:00:00' ), $this->slot( '2026-09-03 10:00:00' ) ] );

		// held-строка не обновляется; scheduled-строка получает ВТОРОЙ слот (хвост после проведённого).
		self::assertCount( 1, $this->wpdb->updates );
		self::assertSame( 2, $this->wpdb->updates[0]['where']['id'] );
		self::assertSame( '2026-09-03 10:00:00', $this->wpdb->updates[0]['data']['scheduled_at'] );
	}

	/** cancelled/moved освобождают слот — хвост сдвигается вперёд (слот не тратится). */
	public function test_apply_slots_freed_status_shifts_tail_forward(): void {
		$this->wpdb->queueResults( [
			$this->slotRow( 1, 0, [ 'status' => 'cancelled' ] ),
			$this->slotRow( 2, 1 ), // scheduled
		] );

		$this->repo->applySlots( 5, [ $this->slot( '2026-09-01 10:00:00' ), $this->slot( '2026-09-03 10:00:00' ) ] );

		// cancelled не обновляется; scheduled получает ПЕРВЫЙ слот (хвост сдвинулся вперёд).
		self::assertCount( 1, $this->wpdb->updates );
		self::assertSame( 2, $this->wpdb->updates[0]['where']['id'] );
		self::assertSame( '2026-09-01 10:00:00', $this->wpdb->updates[0]['data']['scheduled_at'] );
	}

	/** @param array<string,mixed> $over */
	private function slotRow( int $id, int $position, array $over = [] ): array {
		return array_merge( [
			'id'           => $id,
			'group_id'     => 5,
			'lesson_id'    => 10,
			'position'     => $position,
			'scheduled_at' => null,
			'is_pinned'    => 0,
			'kind'         => 'group',
			'status'       => 'scheduled',
		], $over );
	}

	/** @return array{scheduled_at:string,ends_at:string,room:int} */
	private function slot( string $scheduledAt ): array {
		return [ 'scheduled_at' => $scheduledAt, 'ends_at' => $scheduledAt, 'room' => 0 ];
	}
}
