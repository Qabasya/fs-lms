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
}
