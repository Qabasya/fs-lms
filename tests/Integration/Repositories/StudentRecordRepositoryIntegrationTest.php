<?php

declare(strict_types=1);

namespace Integration\Repositories;

use FakeWpdb;
use Inc\DTO\Enrollment\StudentRecordInputDTO;
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

	/** Форма зачисления: временный доступ самого ученика индивидуальную группу не занимает. */
	public function test_count_active_by_group_can_skip_trial_of_student(): void {
		$this->wpdb->queueVar( 0 );

		self::assertSame( 0, $this->repo->countActiveByGroup( 5, 10 ) );
		self::assertStringContainsString( 'NOT ( is_trial = 1 AND student_person_id = 10 )', $this->wpdb->lastQuery() );
	}

	public function test_count_active_by_group_counts_everything_by_default(): void {
		$this->wpdb->queueVar( 1 );

		self::assertSame( 1, $this->repo->countActiveByGroup( 5 ) );
		// student_person_id = 0 не бывает — без ученика условие ничего не исключает.
		self::assertStringContainsString( 'student_person_id = 0', $this->wpdb->lastQuery() );
	}

	private function input( string $status = 'active', ?int $groupId = 7 ): StudentRecordInputDTO {
		return new StudentRecordInputDTO(
			studentPersonId: 10,
			parentPersonId:  20,
			status:          $status,
			enrolledAt:      '2026-01-01 00:00:00',
			createdAt:       '2026-01-01 00:00:00',
			updatedAt:       '2026-01-01 00:00:00',
			groupId:         $groupId,
		);
	}

	/** Индивидуальная группа: второго активного ученика создать нельзя, запись не вставляется. */
	public function test_create_rejects_second_active_student_in_individual_group(): void {
		$this->wpdb->queueRow( (object) array( 'access_mode' => 'individual', 'active_count' => 1 ) );

		$this->expectException( \DomainException::class );
		try {
			$this->repo->create( $this->input() );
		} finally {
			self::assertSame( array(), $this->wpdb->inserts );
			self::assertStringContainsString( 'FOR UPDATE', $this->wpdb->lastQuery() );
		}
	}

	public function test_create_allows_first_student_in_individual_group(): void {
		$this->wpdb->queueRow( (object) array( 'access_mode' => 'individual', 'active_count' => 0 ) );

		$this->repo->create( $this->input() );

		self::assertCount( 1, $this->wpdb->inserts );
	}

	public function test_create_does_not_limit_regular_group(): void {
		$this->wpdb->queueRow( (object) array( 'access_mode' => 'scheduled', 'active_count' => 12 ) );

		$this->repo->create( $this->input() );

		self::assertCount( 1, $this->wpdb->inserts );
	}

	/** Архивная (неактивная) запись инвариант не затрагивает и лишнего запроса не делает. */
	public function test_create_skips_check_for_non_active_record(): void {
		$this->repo->create( $this->input( 'expelled' ) );

		self::assertCount( 1, $this->wpdb->inserts );
		self::assertStringNotContainsString( 'FOR UPDATE', $this->wpdb->lastQuery() );
	}
}
