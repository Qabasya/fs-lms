<?php

declare( strict_types=1 );

namespace Integration\Repositories\Exam;

use FakeWpdb;
use Inc\Repositories\WPDBRepositories\ExamEventRepository;
use PHPUnit\Framework\TestCase;

/** Проведения: версионированное обновление (общее для всех таблиц с `version`) и блокирующее чтение. */
class ExamEventRepositoryTest extends TestCase {

	private FakeWpdb $wpdb;
	private ExamEventRepository $repo;

	protected function setUp(): void {
		parent::setUp();
		$this->wpdb = new FakeWpdb();
		$this->repo = new ExamEventRepository( $this->wpdb );
	}

	public function test_update_requires_expected_version(): void {
		$this->repo->update( 3, array( 'title' => 'Новое' ), 5 );

		self::assertStringContainsString( 'WHERE id = 3 AND version = 5', $this->wpdb->lastQuery() );
	}

	public function test_update_increments_version_and_touches_updated_at(): void {
		$this->repo->update( 3, array( 'title' => 'Новое' ), 5 );

		$sql = $this->wpdb->lastQuery();
		self::assertStringContainsString( "title = 'Новое'", $sql );
		self::assertStringContainsString( 'version = version + 1', $sql );
		self::assertMatchesRegularExpression( "/updated_at = '\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}'/", $sql );
	}

	public function test_update_reports_stale_version_when_no_row_matched(): void {
		$this->wpdb->queueQuery( 0 );

		self::assertFalse( $this->repo->update( 3, array( 'title' => 'Новое' ), 1 ) );
	}

	public function test_update_reports_success_only_for_exactly_one_row(): void {
		$this->wpdb->queueQuery( 1 );

		self::assertTrue( $this->repo->update( 3, array( 'title' => 'Новое' ), 1 ) );
	}

	public function test_update_writes_null_as_sql_null_and_integers_as_numbers(): void {
		$this->repo->update( 3, array( 'description' => null, 'default_assessment_id' => 500 ), 1 );

		$sql = $this->wpdb->lastQuery();
		self::assertStringContainsString( 'description = NULL', $sql );
		self::assertStringContainsString( 'default_assessment_id = 500', $sql );
	}

	public function test_update_rejects_column_name_that_is_not_plain_identifier(): void {
		$this->expectException( \InvalidArgumentException::class );

		$this->repo->update( 3, array( 'title = 1, owner_user_id' => 'x' ), 1 );
	}

	public function test_find_for_update_locks_the_row(): void {
		$this->repo->findForUpdate( 3 );

		self::assertStringContainsString( 'WHERE id = 3 FOR UPDATE', $this->wpdb->lastQuery() );
	}

	public function test_find_for_update_maps_row_to_dto(): void {
		$this->wpdb->queueRow( array(
			'id' => '3', 'subject_key' => 'inf_ege', 'title' => 'Пробный', 'owner_user_id' => '10', 'status' => 'draft', 'period_from' => '2026-03-10',
			'period_to' => '2026-03-12', 'guest_registration_enabled' => '0', 'version' => '2', 'created_at' => '2026-02-20 10:00:00', 'updated_at' => '2026-02-20 10:00:00',
		) );

		$event = $this->repo->findForUpdate( 3 );

		self::assertSame( 3, $event?->id );
		self::assertSame( 2, $event?->version );
	}

	public function test_find_returns_null_when_row_is_missing(): void {
		self::assertNull( $this->repo->find( 404 ) );
	}

	public function test_list_by_owner_orders_newest_first(): void {
		$this->repo->listByOwner( 10 );

		self::assertStringContainsString( 'owner_user_id = 10 ORDER BY created_at DESC', $this->wpdb->lastQuery() );
	}
}
