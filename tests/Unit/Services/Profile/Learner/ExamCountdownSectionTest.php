<?php

declare( strict_types=1 );

namespace Unit\Services\Profile\Learner;

use Inc\Services\Profile\Learner\ExamCountdownSection;
use Inc\Services\Shared\PluginConfig;
use PHPUnit\Framework\TestCase;

/**
 * Плитка «До экзамена» на главной ученика: по предметам его групп, в днях, а в последние 7 суток — в часах (срочно).
 */
class ExamCountdownSectionTest extends TestCase {

	private const NOW = '2027-05-01 12:00:00';

	/** @param array<string, string> $dates */
	private function section( array $dates ): ExamCountdownSection {
		$config = $this->createMock( PluginConfig::class );
		$config->method( 'examDates' )->willReturn( $dates );

		return new ExamCountdownSection( $config );
	}

	private function groupsOf( string ...$subjectKeys ): array {
		return array_map( static fn( string $key ): array => array( 'subject_key' => $key, 'subject' => 'Предмет ' . $key ), $subjectKeys );
	}

	public function test_far_exam_is_counted_in_calendar_days(): void {
		$items = $this->section( array( 'inf_ege' => '2027-06-02 10:00' ) )->forGroups( $this->groupsOf( 'inf_ege' ), self::NOW );

		self::assertCount( 1, $items );
		self::assertSame( 'inf_ege', $items[0]['subject_key'] );
		self::assertSame( 'Предмет inf_ege', $items[0]['subject'] );
		self::assertSame( 32, $items[0]['days'] );
		self::assertFalse( $items[0]['urgent'] );
	}

	/** Ровно семь суток и меньше — часы и красный цвет. */
	public function test_last_week_is_counted_in_hours_and_marked_urgent(): void {
		$items = $this->section( array( 'inf_ege' => '2027-05-08 12:00' ) )->forGroups( $this->groupsOf( 'inf_ege' ), self::NOW );
		self::assertTrue( $items[0]['urgent'] );
		self::assertSame( 168, $items[0]['hours'] );

		$items = $this->section( array( 'inf_ege' => '2027-05-02 09:30' ) )->forGroups( $this->groupsOf( 'inf_ege' ), self::NOW );
		self::assertTrue( $items[0]['urgent'] );
		self::assertSame( 22, $items[0]['hours'], '21 ч 30 мин округляются вверх' );
	}

	public function test_just_over_a_week_is_still_days(): void {
		$items = $this->section( array( 'inf_ege' => '2027-05-08 12:01' ) )->forGroups( $this->groupsOf( 'inf_ege' ), self::NOW );

		self::assertFalse( $items[0]['urgent'] );
		self::assertSame( 7, $items[0]['days'] );
	}

	public function test_past_exam_and_subjects_without_date_or_group_are_skipped(): void {
		$items = $this->section( array( 'inf_ege' => '2027-04-30 10:00', 'rus_ege' => '2027-06-01 10:00', 'math_ege' => '2027-06-03 10:00' ) )
			->forGroups( $this->groupsOf( 'inf_ege', 'inf_oge' ), self::NOW );

		self::assertSame( array(), $items );
	}

	public function test_nearest_exam_first_and_subjects_are_not_duplicated(): void {
		$items = $this->section( array( 'inf_ege' => '2027-06-10 10:00', 'rus_ege' => '2027-06-01 10:00' ) )
			->forGroups( $this->groupsOf( 'inf_ege', 'rus_ege', 'inf_ege' ), self::NOW );

		self::assertSame( array( 'rus_ege', 'inf_ege' ), array_column( $items, 'subject_key' ) );
	}

	public function test_exam_day_itself_is_shown_with_zero_days_left_when_more_than_a_week_away_never(): void {
		$items = $this->section( array( 'inf_ege' => '2027-05-01 15:00' ) )->forGroups( $this->groupsOf( 'inf_ege' ), self::NOW );

		self::assertTrue( $items[0]['urgent'] );
		self::assertSame( 3, $items[0]['hours'] );
		self::assertSame( 0, $items[0]['days'] );
	}
}
