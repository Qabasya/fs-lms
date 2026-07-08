<?php

declare( strict_types=1 );

namespace Unit\Services\Assessment;

use Inc\DTO\Assessment\AssessmentDTO;
use Inc\Enums\Assessment\AssessmentKind;
use Inc\Enums\Assessment\ScoringPolicy;
use Inc\Services\Assessment\EgeCompletenessChecker;
use PHPUnit\Framework\TestCase;

/**
 * T16.11 / T16.6: строгая проверка биекции задание↔номер (D16.2).
 */
class EgeCompletenessCheckerTest extends TestCase {

	private const SUBJECT  = 'inf';
	private const TAXONOMY = 'inf_task_number';

	private EgeCompletenessChecker $checker;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_fs_test_terms']      = array();
		$GLOBALS['_fs_test_post_terms'] = array();
		$this->checker                  = new EgeCompletenessChecker();
	}

	/** Регистрирует N номеров-термов (1..N) в таксономии предмета. */
	private function seedNumbers( int $n ): void {
		$rows = array();
		for ( $i = 1; $i <= $n; $i++ ) {
			$rows[] = array( 'slug' => (string) $i, 'name' => (string) $i );
		}
		$GLOBALS['_fs_test_terms'][ self::TAXONOMY ] = $rows;
	}

	/** Назначает заданию $taskId номер $number (slug). */
	private function tagTask( int $taskId, ?string $number ): void {
		$GLOBALS['_fs_test_post_terms'][ $taskId ][ self::TAXONOMY ] = null === $number ? array() : array( $number );
	}

	private function assessment( array $taskIds ): AssessmentDTO {
		return new AssessmentDTO(
			id: 1, subjectKey: self::SUBJECT, title: 'ЕГЭ', taskIds: $taskIds,
			timeLimit: 0, attemptsAllowed: 0, passScore: 0.0,
			scoringPolicy: ScoringPolicy::Highest, status: 'draft',
			kind: AssessmentKind::Ege, taskPoints: array(), scoreMap: array(),
		);
	}

	public function test_complete_bijection_on_27_numbers(): void {
		$this->seedNumbers( 27 );
		$taskIds = array();
		for ( $i = 1; $i <= 27; $i++ ) {
			$taskId    = 100 + $i;
			$taskIds[] = $taskId;
			$this->tagTask( $taskId, (string) $i );
		}

		$result = $this->checker->validate( $this->assessment( $taskIds ), self::SUBJECT );

		$this->assertTrue( $result->isStrictlyComplete() );
		$this->assertSame( 27, $result->expectedCount );
		$this->assertSame( 27, $result->actualCount );
		$this->assertSame( '', $result->summary() );
	}

	public function test_missing_number_is_reported(): void {
		$this->seedNumbers( 3 );
		$this->tagTask( 101, '1' );
		$this->tagTask( 102, '2' );
		// номер 3 не покрыт

		$result = $this->checker->validate( $this->assessment( array( 101, 102 ) ), self::SUBJECT );

		$this->assertFalse( $result->isStrictlyComplete() );
		$this->assertSame( array( '3' ), $result->missing );
		$this->assertStringContainsString( '3', $result->summary() );
	}

	public function test_duplicated_number_is_reported(): void {
		$this->seedNumbers( 2 );
		$this->tagTask( 101, '1' );
		$this->tagTask( 102, '1' ); // дубль номера 1
		$this->tagTask( 103, '2' );

		$result = $this->checker->validate( $this->assessment( array( 101, 102, 103 ) ), self::SUBJECT );

		$this->assertFalse( $result->isStrictlyComplete() );
		$this->assertSame( array( '1' ), $result->duplicated );
	}

	public function test_orphan_task_without_number_is_reported(): void {
		$this->seedNumbers( 2 );
		$this->tagTask( 101, '1' );
		$this->tagTask( 102, '2' );
		$this->tagTask( 103, null ); // задание без номера

		$result = $this->checker->validate( $this->assessment( array( 101, 102, 103 ) ), self::SUBJECT );

		$this->assertFalse( $result->isStrictlyComplete() );
		$this->assertSame( array( 103 ), $result->orphans );
	}

	public function test_no_terms_means_not_complete(): void {
		// у предмета не заведены номера
		$result = $this->checker->validate( $this->assessment( array( 101 ) ), self::SUBJECT );

		$this->assertFalse( $result->isStrictlyComplete() );
		$this->assertSame( 0, $result->expectedCount );
	}

	public function test_missing_numbers_sorted_numerically(): void {
		$this->seedNumbers( 12 );
		// покрыты только 1 и 2 → пропущены 3..12, должны идти по возрастанию
		$this->tagTask( 101, '1' );
		$this->tagTask( 102, '2' );

		$result = $this->checker->validate( $this->assessment( array( 101, 102 ) ), self::SUBJECT );

		$this->assertSame(
			array( '3', '4', '5', '6', '7', '8', '9', '10', '11', '12' ),
			$result->missing
		);
	}
}
