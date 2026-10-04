<?php

declare( strict_types=1 );

namespace Unit\Services\Assessment;

use Inc\DTO\Assessment\AssessmentDTO;
use Inc\DTO\Exam\ExamFormatDTO;
use Inc\Enums\Assessment\AssessmentKind;
use Inc\Enums\Assessment\ScoringPolicy;
use Inc\Enums\Exam\ExamDirection;
use Inc\Services\Assessment\EgeCompletenessChecker;
use Inc\Services\Exam\ExamFormatRegistry;
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
		$GLOBALS['_fs_test_terms']          = array();
		$GLOBALS['_fs_test_post_terms']     = array();
		$GLOBALS['_fs_test_filter_returns'] = array();
		$this->checker                      = new EgeCompletenessChecker( new ExamFormatRegistry() );
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

	private function assessment( array $taskIds, AssessmentKind $kind = AssessmentKind::EgeComputer, array $taskNumbers = array() ): AssessmentDTO {
		return new AssessmentDTO(
			id: 1, subjectKey: self::SUBJECT, title: 'ЕГЭ', taskIds: $taskIds,
			timeLimit: 0, attemptsAllowed: 0, passScore: 0.0,
			scoringPolicy: ScoringPolicy::Highest, status: 'draft',
			kind: $kind, taskPoints: array(), scoreMap: array(), taskNumbers: $taskNumbers,
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

	/**
	 * ОГЭ №13-16 не имеют терма таксономии по замыслу (ручная проверка, номер
	 * только в AssessmentDTO::$taskNumbers) — модуль EgeComputer добавляет их
	 * фильтром EXTRA_POSITIONS_FILTER, иначе строгая проверка никогда не
	 * признаёт ОГЭ-работу укомплектованной.
	 */
	public function test_oge_extra_positions_from_filter_complete_bijection(): void {
		$GLOBALS['_fs_test_filter_returns'][ EgeCompletenessChecker::EXTRA_POSITIONS_FILTER ] = array( '13', '14', '15', '16' );

		$this->seedNumbers( 12 );
		$taskIds     = array();
		$taskNumbers = array();
		for ( $i = 1; $i <= 12; $i++ ) {
			$taskId    = 100 + $i;
			$taskIds[] = $taskId;
			$this->tagTask( $taskId, (string) $i );
		}
		foreach ( array( '13', '14', '15', '16' ) as $j => $number ) {
			$taskId               = 200 + $j;
			$taskIds[]            = $taskId;
			$taskNumbers[ $taskId ] = $number;
			$this->tagTask( $taskId, null ); // без терма — только ручной номер
		}

		$result = $this->checker->validate(
			$this->assessment( $taskIds, AssessmentKind::OgeComputer, $taskNumbers ),
			self::SUBJECT
		);

		$this->assertTrue( $result->isStrictlyComplete() );
		$this->assertSame( 16, $result->expectedCount );
		$this->assertSame( 16, $result->actualCount );
	}

	/** Без задач на позициях 13-16 работа остаётся неукомплектованной — они попадают в missing. */
	public function test_oge_missing_manual_positions_reported(): void {
		$GLOBALS['_fs_test_filter_returns'][ EgeCompletenessChecker::EXTRA_POSITIONS_FILTER ] = array( '13', '14', '15', '16' );

		$this->seedNumbers( 12 );
		$taskIds = array();
		for ( $i = 1; $i <= 12; $i++ ) {
			$taskId    = 100 + $i;
			$taskIds[] = $taskId;
			$this->tagTask( $taskId, (string) $i );
		}

		$result = $this->checker->validate(
			$this->assessment( $taskIds, AssessmentKind::OgeComputer ),
			self::SUBJECT
		);

		$this->assertFalse( $result->isStrictlyComplete() );
		$this->assertSame( array( '13', '14', '15', '16' ), $result->missing );
		$this->assertSame( 16, $result->expectedCount );
	}

	public function test_expected_count_comes_from_format_not_terms(): void {
		$this->seedNumbers( 36 );
		$format = $this->createFormatDto(
			kind      : AssessmentKind::EgeComputer,
			unitCount : 27,
		);
		$GLOBALS['_fs_test_filter_returns']['fs_lms_exam_formats'] = [ $format ];

		$taskIds = range( 1, 27 );
		foreach ( $taskIds as $i ) {
			$this->tagTask( $i, (string) $i );
		}

		$result = $this->checker->validate(
			$this->assessment( $taskIds, AssessmentKind::EgeComputer ),
			self::SUBJECT
		);

		$this->assertTrue( $result->isStrictlyComplete() );
		$this->assertSame( 27, $result->expectedCount );
	}

	public function test_task_with_number_outside_format_is_orphan(): void {
		$this->seedNumbers( 30 );
		$GLOBALS['_fs_test_filter_returns']['fs_lms_exam_formats'] = array(
			$this->createFormatDto( kind: AssessmentKind::EgeComputer, unitCount: 27 ),
		);

		$taskIds = range( 1, 30 );
		foreach ( $taskIds as $i ) {
			$this->tagTask( $i, (string) $i );
		}

		$result = $this->checker->validate(
			$this->assessment( $taskIds, AssessmentKind::EgeComputer ),
			self::SUBJECT
		);

		$this->assertFalse( $result->isStrictlyComplete() );
		// Номера 28–30 вне формата: их задания не находят терма в пределах 1..27 и становятся сиротами (в списке — ID заданий).
		$this->assertSame( array( 28, 29, 30 ), $result->orphans );
		$this->assertSame( 27, $result->expectedCount );
	}

	public function test_falls_back_to_terms_when_format_missing(): void {
		$this->seedNumbers( 27 );

		$taskIds = range( 1, 27 );
		foreach ( $taskIds as $i ) {
			$this->tagTask( $i, (string) $i );
		}

		$result = $this->checker->validate(
			$this->assessment( $taskIds, AssessmentKind::EgeComputer ),
			self::SUBJECT
		);

		$this->assertTrue( $result->isStrictlyComplete() );
		$this->assertSame( 27, $result->expectedCount );
	}

	public function test_oge_extra_positions_still_counted(): void {
		$this->seedNumbers( 12 );
		$format = $this->createFormatDto(
			kind      : AssessmentKind::OgeComputer,
			unitCount : 16,
		);
		$GLOBALS['_fs_test_filter_returns']['fs_lms_exam_formats'] = [ $format ];
		$GLOBALS['_fs_test_filter_returns']['fs_lms_assessment_completeness_extra_positions'] = [ '13', '14', '15', '16' ];

		$taskIds     = range( 1, 16 );
		$taskNumbers = array();
		foreach ( $taskIds as $i ) {
			if ( $i <= 12 ) {
				$this->tagTask( $i, (string) $i );
			} else {
				$taskNumbers[ $i ] = (string) $i; // позиции 13–16 вне таксономии: номер задан вручную
			}
		}

		$result = $this->checker->validate(
			$this->assessment( $taskIds, AssessmentKind::OgeComputer, $taskNumbers ),
			self::SUBJECT
		);

		$this->assertTrue( $result->isStrictlyComplete() );
		$this->assertSame( 16, $result->expectedCount );
	}

	public function test_format_position_without_term_is_missing_even_when_all_existing_terms_are_covered(): void {
		// Формат на 27 позиций, а в таксономии заведены только 1..26: набор из 26 заданий не может считаться полным.
		$this->seedNumbers( 26 );
		$GLOBALS['_fs_test_filter_returns']['fs_lms_exam_formats'] = array(
			$this->createFormatDto( kind: AssessmentKind::EgeComputer, unitCount: 27 ),
		);

		$taskIds = range( 1, 26 );
		foreach ( $taskIds as $i ) {
			$this->tagTask( $i, (string) $i );
		}

		$result = $this->checker->validate( $this->assessment( $taskIds ), self::SUBJECT );

		$this->assertFalse( $result->isStrictlyComplete() );
		$this->assertSame( array( '27' ), $result->missing );
		$this->assertSame( 27, $result->expectedCount );
	}

	public function test_banked_task_numbered_by_snapshot_closes_position_without_term(): void {
		$this->seedNumbers( 26 );
		$GLOBALS['_fs_test_filter_returns']['fs_lms_exam_formats'] = array(
			$this->createFormatDto( kind: AssessmentKind::EgeComputer, unitCount: 27 ),
		);

		$taskIds = range( 1, 27 );
		foreach ( range( 1, 26 ) as $i ) {
			$this->tagTask( $i, (string) $i );
		}
		$this->tagTask( 27, null );

		$result = $this->checker->validate( $this->assessment( $taskIds, AssessmentKind::EgeComputer, array( 27 => '27' ) ), self::SUBJECT );

		$this->assertTrue( $result->isStrictlyComplete() );
		$this->assertSame( 27, $result->expectedCount );
	}

	public function test_soft_layer_reports_position_without_term(): void {
		$this->seedNumbers( 26 );
		$GLOBALS['_fs_test_filter_returns']['fs_lms_exam_formats'] = array(
			$this->createFormatDto( kind: AssessmentKind::EgeComputer, unitCount: 27 ),
		);

		$taskIds = range( 1, 26 );
		foreach ( $taskIds as $i ) {
			$this->tagTask( $i, (string) $i );
		}

		$this->assertSame( array( '27' ), $this->checker->getMissingTaskNumbers( $this->assessment( $taskIds ), self::SUBJECT ) );
		$this->assertFalse( $this->checker->isComplete( $this->assessment( $taskIds ), self::SUBJECT ) );
	}

	public function test_format_without_any_terms_reports_every_position_missing(): void {
		$GLOBALS['_fs_test_filter_returns']['fs_lms_exam_formats'] = array(
			$this->createFormatDto( kind: AssessmentKind::EgeComputer, unitCount: 3 ),
		);

		$result = $this->checker->validate( $this->assessment( array() ), self::SUBJECT );

		$this->assertFalse( $result->isStrictlyComplete() );
		$this->assertSame( array( '1', '2', '3' ), $result->missing );
	}
	private function createFormatDto( AssessmentKind $kind, int $unitCount ): ExamFormatDTO {
		return new ExamFormatDTO(
			kind          : $kind,
			direction     : AssessmentKind::EgeComputer === $kind ? ExamDirection::Ege : ExamDirection::Oge,
			unitCount     : $unitCount,
			primaryMax    : AssessmentKind::EgeComputer === $kind ? 29 : 21,
			secondaryMax  : AssessmentKind::EgeComputer === $kind ? 100 : null,
			gradeMax      : AssessmentKind::EgeComputer === $kind ? 0 : 5,
			durationMinutes: AssessmentKind::EgeComputer === $kind ? 235 : 150,
			scale         : [],
			unitMaxScores : [],
		);
	}
}
