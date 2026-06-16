<?php

declare(strict_types=1);

namespace Integration\Repositories;

use FakeWpdb;
use Inc\DTO\Person\PersonDTO;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use PHPUnit\Framework\TestCase;

/**
 * Интеграционный тест PersonRepository.
 *
 * Через FakeWpdb проверяет, что репозиторий строит корректный SQL
 * (фильтры soft-delete) и правильно мапит строки в PersonDTO.
 */
class PersonRepositoryIntegrationTest extends TestCase {

	private FakeWpdb $wpdb;
	private PersonRepository $repo;

	protected function setUp(): void {
		parent::setUp();
		$this->wpdb = new FakeWpdb();
		$this->repo = new PersonRepository( $this->wpdb );
	}

	private function row( int $id, ?string $expelledAt = null ): array {
		return array(
			'id'          => $id,
			'wp_user_id'  => null,
			'last_name'   => 'Иванов',
			'first_name'  => 'Иван',
			'middle_name' => 'Иванович',
			'birth_date'  => '2008-05-01',
			'is_student'  => 1,
			'school'      => 'Школа №1',
			'grade'       => '10',
			'expelled_at' => $expelledAt,
			'created_at'  => '2024-01-01 00:00:00',
			'updated_at'  => '2024-01-01 00:00:00',
		);
	}

	// ── find() ────────────────────────────────────────────────────────────────

	public function test_find_constrains_query_to_non_deleted(): void {
		$this->wpdb->queueRow( $this->row( 5 ) );

		$dto = $this->repo->find( 5 );

		self::assertInstanceOf( PersonDTO::class, $dto );
		self::assertSame( 5, $dto->id );
		self::assertStringContainsString( 'expelled_at IS NULL', $this->wpdb->lastQuery() );
	}

	public function test_find_returns_null_when_db_filters_out_expelled(): void {
		// БД не вернула строку (expelled_at IS NOT NULL отфильтрован запросом).
		$this->wpdb->queueRow( null );

		self::assertNull( $this->repo->find( 5 ) );
		self::assertStringContainsString( 'expelled_at IS NULL', $this->wpdb->lastQuery() );
	}

	// ── findIncludingDeleted() ──────────────────────────────────────────────────

	public function test_find_including_deleted_returns_expelled_person(): void {
		$this->wpdb->queueRow( $this->row( 9, '2024-02-01 10:00:00' ) );

		$dto = $this->repo->findIncludingDeleted( 9 );

		self::assertInstanceOf( PersonDTO::class, $dto );
		self::assertSame( '2024-02-01 10:00:00', $dto->expelledAt );
		self::assertStringNotContainsString( 'expelled_at IS NULL', $this->wpdb->lastQuery() );
	}

	// ── softDelete() ────────────────────────────────────────────────────────────

	public function test_soft_delete_sets_expelled_at_on_target_id(): void {
		$ok = $this->repo->softDelete( 7 );

		self::assertTrue( $ok );
		self::assertCount( 1, $this->wpdb->updates );

		$update = $this->wpdb->updates[0];
		self::assertSame( array( 'id' => 7 ), $update['where'] );
		self::assertArrayHasKey( 'expelled_at', $update['data'] );
		self::assertNotNull( $update['data']['expelled_at'] );
	}

	// ── findDeletedOlderThan() ──────────────────────────────────────────────────

	public function test_find_deleted_older_than_filters_by_date(): void {
		$this->wpdb->queueResults( array( $this->row( 1, '2023-01-01 00:00:00' ) ) );

		$result = $this->repo->findDeletedOlderThan( 30 );

		self::assertCount( 1, $result );
		self::assertInstanceOf( PersonDTO::class, $result[0] );

		$query = $this->wpdb->lastQuery();
		self::assertStringContainsString( 'expelled_at IS NOT NULL', $query );
		self::assertStringContainsString( 'INTERVAL 30 DAY', $query );
	}
}
