<?php

declare( strict_types=1 );

namespace Unit\Services\Assessment;

use Inc\DTO\Assessment\AssessmentDTO;
use Inc\Enums\Assessment\AssessmentKind;
use Inc\Enums\Assessment\ScoringPolicy;
use Inc\Managers\Wp\TermManager;
use Inc\Services\Assessment\ArchiveTaskNumber;
use Inc\Services\Assessment\ScoringUnits;
use PHPUnit\Framework\TestCase;

/**
 * Одинаковые номера заданий в экзамене станции — одна единица зачёта: верны все —
 * полный балл номера, иначе 0; максимум работы не растёт от повторов номера.
 */
class ScoringUnitsTest extends TestCase {

	/** @param array<int, string> $numbers task_id => терм таксономии */
	private function units( array $numbers ): ScoringUnits {
		$terms = $this->createMock( TermManager::class );
		$terms->method( 'getPostTerms' )->willReturnCallback(
			static fn( int $id ) => isset( $numbers[ $id ] ) ? array( (object) array( 'name' => $numbers[ $id ] ) ) : array()
		);

		return new ScoringUnits( $terms, new ArchiveTaskNumber() );
	}

	/** @param int[] $taskIds @param array<int, string> $manual */
	private function assessment( AssessmentKind $kind, array $taskIds, array $manual = array() ): AssessmentDTO {
		return new AssessmentDTO(
			id: 1, subjectKey: 'inf', title: 'Экзамен', taskIds: $taskIds,
			timeLimit: 0, attemptsAllowed: 0, passScore: 0.0,
			scoringPolicy: ScoringPolicy::Highest, status: 'publish',
			kind: $kind, taskPoints: array(), scoreMap: array(), taskNumbers: $manual,
		);
	}

	private function r( float $score, float $max = 1.0, bool $pending = false ): array {
		return array( 'score' => $score, 'max' => $max, 'pending' => $pending );
	}

	public function test_three_equal_numbers_count_as_one_when_all_correct(): void {
		$a = $this->assessment( AssessmentKind::EgeComputer, array( 1, 2, 3, 4 ) );
		$u = $this->units( array( 1 => '14', 2 => '14', 3 => '14', 4 => '15' ) );

		$totals = $u->totals( $a, array( 1 => $this->r( 1 ), 2 => $this->r( 1 ), 3 => $this->r( 1 ), 4 => $this->r( 1 ) ) );

		self::assertSame( 2.0, $totals['score'] );
		self::assertSame( 2.0, $totals['max'] );
	}

	public function test_one_wrong_task_zeroes_the_whole_number(): void {
		$a = $this->assessment( AssessmentKind::EgeComputer, array( 1, 2, 3, 4 ) );
		$u = $this->units( array( 1 => '14', 2 => '14', 3 => '14', 4 => '15' ) );

		$totals = $u->totals( $a, array( 1 => $this->r( 1 ), 2 => $this->r( 0 ), 3 => $this->r( 1 ), 4 => $this->r( 1 ) ) );

		self::assertSame( 1.0, $totals['score'] );
		self::assertSame( 2.0, $totals['max'] );
	}

	public function test_single_task_keeps_partial_score_of_two_slot_number(): void {
		$a = $this->assessment( AssessmentKind::EgeComputer, array( 1 ) );
		$u = $this->units( array( 1 => '26' ) );

		$totals = $u->totals( $a, array( 1 => $this->r( 1, 2 ) ) );

		self::assertSame( 1.0, $totals['score'] );
		self::assertSame( 2.0, $totals['max'] );
	}

	public function test_full_kim_keeps_max_29_with_extra_equal_numbers(): void {
		$ids     = range( 1, 30 );
		$numbers = array();
		$perTask = array();
		foreach ( $ids as $i => $id ) {
			// 27 номеров, №14 — трижды (ids 14–16), №25 — дважды.
			$numbers[ $id ] = (string) match ( true ) {
				$id <= 13         => $id,
				$id <= 16         => 14,
				$id <= 24         => $id - 2,
				$id <= 26         => 25,
				default           => $id - 1,
			};
		}
		foreach ( $ids as $id ) {
			$max            = in_array( $numbers[ $id ], array( '26', '27' ), true ) ? 2.0 : 1.0;
			$perTask[ $id ] = $this->r( $max, $max );
		}

		$totals = $this->units( $numbers )->totals( $this->assessment( AssessmentKind::EgeComputer, $ids ), $perTask );

		self::assertSame( 29.0, $totals['max'] );
		self::assertSame( 29.0, $totals['score'] );
	}

	public function test_pending_task_gives_zero_for_its_number(): void {
		$a = $this->assessment( AssessmentKind::EgeComputer, array( 1, 2 ) );
		$u = $this->units( array( 1 => '14', 2 => '14' ) );

		$totals = $u->totals( $a, array( 1 => $this->r( 1 ), 2 => $this->r( 0, 1, true ) ) );

		self::assertSame( 0.0, $totals['score'] );
		self::assertSame( 1.0, $totals['max'] );
	}

	public function test_tasks_without_number_are_separate_units(): void {
		$a = $this->assessment( AssessmentKind::EgeComputer, array( 1, 2 ) );

		$totals = $this->units( array() )->totals( $a, array( 1 => $this->r( 1 ), 2 => $this->r( 0 ) ) );

		self::assertSame( 1.0, $totals['score'] );
		self::assertSame( 2.0, $totals['max'] );
	}

	public function test_manual_number_groups_when_no_term(): void {
		$a = $this->assessment( AssessmentKind::EgeComputer, array( 1, 2 ), array( 1 => '14', 2 => '14' ) );

		$totals = $this->units( array() )->totals( $a, array( 1 => $this->r( 1 ), 2 => $this->r( 1 ) ) );

		self::assertSame( 1.0, $totals['score'] );
		self::assertSame( 1.0, $totals['max'] );
	}

	/** Архивное №114 — тот же тип, что №14: 29 баллов не растут от архивных заданий. */
	public function test_archive_and_live_numbers_are_the_same_unit(): void {
		$a = $this->assessment( AssessmentKind::EgeComputer, array( 1, 2 ) );

		$totals = $this->units( array( 1 => '14', 2 => '114' ) )->totals( $a, array( 1 => $this->r( 1 ), 2 => $this->r( 1 ) ) );

		self::assertSame( 1.0, $totals['max'] );
		self::assertSame( 1.0, $totals['score'] );
	}

	/** 20 заданий №1 и по одному на остальные 26 типов — те же 29 баллов. */
	public function test_twenty_tasks_of_one_type_collapse_into_one_point(): void {
		$ids     = range( 1, 46 );
		$numbers = array();
		$perTask = array();
		foreach ( $ids as $id ) {
			$numbers[ $id ] = (string) ( $id <= 20 ? 1 : $id - 19 );  // 1×20, затем 2…27
			$max            = in_array( $numbers[ $id ], array( '26', '27' ), true ) ? 2.0 : 1.0;
			$perTask[ $id ] = $this->r( $max, $max );
		}

		$totals = $this->units( $numbers )->totals( $this->assessment( AssessmentKind::EgeComputer, $ids ), $perTask );

		self::assertSame( 29.0, $totals['max'] );
		self::assertSame( 29.0, $totals['score'] );
	}

	public function test_other_kinds_are_plain_sums(): void {
		foreach ( array( AssessmentKind::Control, AssessmentKind::OgeComputer ) as $kind ) {
			$a      = $this->assessment( $kind, array( 1, 2 ) );
			$totals = $this->units( array( 1 => '14', 2 => '14' ) )->totals( $a, array( 1 => $this->r( 1 ), 2 => $this->r( 1 ) ) );

			self::assertSame( 2.0, $totals['score'], $kind->value );
			self::assertSame( 2.0, $totals['max'], $kind->value );
		}
	}
}
