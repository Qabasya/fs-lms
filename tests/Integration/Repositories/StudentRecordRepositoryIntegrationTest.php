<?php

declare(strict_types=1);

namespace Integration\Repositories;

use FakeWpdb;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use PHPUnit\Framework\TestCase;

/**
 * Интеграционный тест StudentRecordRepository.
 *
 * Проверяет, что existsActive() строит предикат по
 * student_person_id + group_id + status='active' и корректно
 * интерпретирует результат COUNT(*) как bool.
 */
class StudentRecordRepositoryIntegrationTest extends TestCase {

	private FakeWpdb $wpdb;
	private StudentRecordRepository $repo;

	protected function setUp(): void {
		parent::setUp();
		$this->wpdb = new FakeWpdb();
		$this->repo = new StudentRecordRepository( $this->wpdb );
	}

	public function test_exists_active_returns_true_when_active_record_present(): void {
		$this->wpdb->queueVar( 1 );

		self::assertTrue( $this->repo->existsActive( 10, 5 ) );

		$query = $this->wpdb->lastQuery();
		self::assertStringContainsString( "status = 'active'", $query );
		self::assertStringContainsString( 'student_person_id = 10', $query );
		self::assertStringContainsString( 'group_id = 5', $query );
	}

	public function test_exists_active_returns_false_for_expelled_record(): void {
		// Отчисленная запись не попадает в COUNT, т.к. status != 'active'.
		$this->wpdb->queueVar( 0 );

		self::assertFalse( $this->repo->existsActive( 10, 5 ) );
		self::assertStringContainsString( "status = 'active'", $this->wpdb->lastQuery() );
	}

	public function test_exists_active_returns_false_for_other_group(): void {
		// В другой группе активной записи нет.
		$this->wpdb->queueVar( 0 );

		self::assertFalse( $this->repo->existsActive( 10, 999 ) );
		self::assertStringContainsString( 'group_id = 999', $this->wpdb->lastQuery() );
	}

	public function test_count_active_by_group_returns_int(): void {
		$this->wpdb->queueVar( 3 );

		self::assertSame( 3, $this->repo->countActiveByGroup( 5 ) );

		$query = $this->wpdb->lastQuery();
		self::assertStringContainsString( "status = 'active'", $query );
		self::assertStringContainsString( 'group_id = 5', $query );
	}
}
