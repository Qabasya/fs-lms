<?php

declare( strict_types=1 );

namespace Unit\Services\Profile\Learner;

use Inc\Services\Profile\Learner\LearnerHighlightsSection;
use PHPUnit\Framework\TestCase;

/**
 * Дополнительные плитки главной ученика (пилот на тестовом аккаунте): серия без пропусков, осталось исправить, последний результат экзамена.
 */
class LearnerHighlightsSectionTest extends TestCase {

	private LearnerHighlightsSection $section;

	protected function setUp(): void {
		parent::setUp();
		$this->section = new LearnerHighlightsSection();
	}

	private function rows( bool ...$present ): array {
		return array_map( static fn( bool $p ): array => array( 'present' => $p ), $present );
	}

	public function test_streak_counts_present_marks_from_the_latest_until_first_absence(): void {
		self::assertSame( 2, $this->section->streak( $this->rows( true, true, false, true ) ) );
		self::assertSame( 0, $this->section->streak( $this->rows( false, true, true ) ) );
		self::assertSame( 3, $this->section->streak( $this->rows( true, true, true ) ) );
		self::assertSame( 0, $this->section->streak( array() ) );
	}

	/** Осталось исправить — нерешённые задания сданных работ, которые ещё можно пересдать (они же стоят в дедлайнах). */
	public function test_to_fix_sums_remaining_tasks_of_submitted_works(): void {
		$deadlines = array(
			array( 'topic' => 'ДЗ 1', 'remaining' => 2, 'total' => 5 ),
			array( 'topic' => 'ДЗ 2' ),
			array( 'topic' => 'ДЗ 3', 'remaining' => 1, 'total' => 3 ),
		);

		self::assertSame( 3, $this->section->toFix( $deadlines ) );
		self::assertSame( 0, $this->section->toFix( array() ) );
	}

	public function test_last_result_takes_the_latest_approved_exam(): void {
		$cards = array(
			array( 'title' => 'Пробник 1', 'result' => array( 'primary' => 10, 'primary_max' => 29, 'secondary' => 55, 'grade' => null ), 'approved_at' => '2027-03-01 10:00:00' ),
			array( 'title' => 'Пробник 2', 'result' => array( 'primary' => 18, 'primary_max' => 29, 'secondary' => 72, 'grade' => null ), 'approved_at' => '2027-04-01 10:00:00' ),
			array( 'title' => 'Ждёт проверки', 'state' => 'awaiting_approval' ),
		);

		self::assertSame( array( 'value' => '72', 'note' => 'Пробник 2 · баллов' ), $this->section->lastResult( $cards ) );
	}

	public function test_last_result_for_oge_is_the_grade_and_falls_back_to_primary(): void {
		self::assertSame(
			array( 'value' => '4', 'note' => 'ОГЭ · отметка' ),
			$this->section->lastResult( array( array( 'title' => 'ОГЭ', 'result' => array( 'primary' => 12, 'primary_max' => 19, 'secondary' => null, 'grade' => 4 ), 'approved_at' => '2027-04-01 10:00:00' ) ) )
		);
		self::assertSame(
			array( 'value' => '12 из 19', 'note' => 'Без шкалы · первичный балл' ),
			$this->section->lastResult( array( array( 'title' => 'Без шкалы', 'result' => array( 'primary' => 12, 'primary_max' => 19, 'secondary' => null, 'grade' => null ), 'approved_at' => '2027-04-01 10:00:00' ) ) )
		);
		self::assertNull( $this->section->lastResult( array( array( 'title' => 'x', 'state' => 'registered' ) ) ) );
	}

	/** Пилот: только тестовый аккаунт; список логинов расширяется фильтром без правки кода. */
	public function test_pilot_is_enabled_only_for_the_test_account(): void {
		self::assertTrue( $this->section->enabledFor( 'belova2024' ) );
		self::assertFalse( $this->section->enabledFor( 'ivanov' ) );
		self::assertFalse( $this->section->enabledFor( '' ) );
	}
}
