<?php

declare( strict_types=1 );

namespace Integration\Repositories\Log;

use FakeWpdb;
use Inc\DTO\Log\LearningEventInputDTO;
use Inc\Enums\LogEvent;
use Inc\Repositories\WPDBRepositories\Log\LearningEventRepository;
use PHPUnit\Framework\TestCase;

class LearningEventRepositoryTest extends TestCase {

	private FakeWpdb $wpdb;
	private LearningEventRepository $repo;

	protected function setUp(): void {
		parent::setUp();
		$this->wpdb = new FakeWpdb();
		$this->repo = new LearningEventRepository( $this->wpdb );
	}

	public function test_create_inserts_into_correct_table(): void {
		$dto = new LearningEventInputDTO(
			action      : LogEvent::CourseAssigned->value,
			subjectKey  : 'inf',
			groupId     : 3,
			actorUserId : 5,
			actorRole   : 'teacher',
			entityType  : 'course',
			entityId    : '10',
			isPublic    : true,
		);

		$id = $this->repo->create( $dto );

		self::assertSame( 1, $id );
		self::assertCount( 1, $this->wpdb->inserts );
		self::assertStringContainsString( 'fs_lms_learning_events', $this->wpdb->inserts[0]['table'] );
		self::assertSame( 5, $this->wpdb->inserts[0]['data']['actor_user_id'] );
		self::assertSame( LogEvent::CourseAssigned->value, $this->wpdb->inserts[0]['data']['action'] );
	}

	public function test_list_by_group_builds_paginated_query(): void {
		$this->wpdb->queueResults( [] );

		$this->repo->listByGroup( 3, page: 2, perPage: 10 );

		$q = $this->wpdb->lastQuery();
		self::assertStringContainsString( 'group_id = 3', $q );
		self::assertStringContainsString( 'LIMIT 10', $q );
		self::assertStringContainsString( 'OFFSET 10', $q );
		self::assertStringContainsString( 'ORDER BY created_at DESC', $q );
	}

	public function test_list_by_group_maps_rows_to_dtos(): void {
		$this->wpdb->queueResults( [ $this->eventRow( 1, groupId: 3 ) ] );

		$events = $this->repo->listByGroup( 3, page: 1, perPage: 20 );

		self::assertCount( 1, $events );
		self::assertSame( 1, $events[0]->id );
		self::assertSame( 3, $events[0]->groupId );
	}

	public function test_list_by_group_public_filters_by_is_public_or_own_actor(): void {
		$this->wpdb->queueResults( [] );

		$this->repo->listByGroupPublic( 3, actorUserId: 7, page: 1, perPage: 10 );

		$q = $this->wpdb->lastQuery();
		self::assertStringContainsString( 'is_public = 1', $q );
		self::assertStringContainsString( 'actor_user_id = 7', $q );
		self::assertStringContainsString( 'group_id = 3', $q );
	}

	public function test_list_by_actor_filters_by_actor_user_id(): void {
		$this->wpdb->queueResults( [] );

		$this->repo->listByActor( 5, page: 1, perPage: 10 );

		$q = $this->wpdb->lastQuery();
		self::assertStringContainsString( 'actor_user_id = 5', $q );
	}

	public function test_list_by_group_first_page_has_zero_offset(): void {
		$this->wpdb->queueResults( [] );

		$this->repo->listByGroup( 1, page: 1, perPage: 20 );

		self::assertStringContainsString( 'OFFSET 0', $this->wpdb->lastQuery() );
	}

	public function test_count_by_group_returns_int(): void {
		$this->wpdb->queueVar( '7' );

		$count = $this->repo->countByGroup( 3 );

		self::assertSame( 7, $count );
		self::assertStringContainsString( 'group_id = 3', $this->wpdb->lastQuery() );
	}

	public function test_update_throws_logic_exception(): void {
		$this->expectException( \LogicException::class );
		$this->repo->update();
	}

	// --- helpers ---

	private function eventRow( int $id, int $groupId = 1 ): array {
		return [
			'id'             => $id,
			'actor_user_id'  => 5,
			'actor_role'     => 'teacher',
			'action'         => LogEvent::CourseAssigned->value,
			'subject_key'    => 'inf',
			'group_id'       => $groupId,
			'entity_type'    => 'course',
			'entity_id'      => '10',
			'is_public'      => 1,
			'created_at'     => '2024-06-01 12:00:00',
		];
	}
}
