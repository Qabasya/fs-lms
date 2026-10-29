<?php

declare( strict_types=1 );

namespace Unit\Services\Task;

use Inc\Services\Task\Checkers\DragFillChecker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DragFillCheckerTest extends TestCase {

	private DragFillChecker $checker;

	protected function setUp(): void {
		$this->checker = new DragFillChecker();
	}

	/**
	 * @param string[] $answers Верная плитка по слотам.
	 * @param int[]    $groups  Группа по слотам (одинаковая — взаимозаменяемы).
	 */
	private function content( string $formula, array $answers, array $groups, array $extraTiles = array() ): array {
		$slots = array();
		foreach ( $answers as $i => $answer ) {
			$slots[] = array( 'answer' => $answer, 'group' => $groups[ $i ] );
		}

		return array( 'task_drag_fill' => array(
			'formula' => $formula,
			'tiles'   => array_merge( $answers, $extraTiles ),
			'slots'   => $slots,
		) );
	}

	private function volume( array $groups ): array {
		return $this->content( 'V = {1} * {2} * {3}', array( 'h', 'w', 'i' ), $groups, array( 'k', 'd' ) );
	}

	public function test_ordered_slots_correct(): void {
		$result = $this->checker->check( $this->volume( array( 1, 2, 3 ) ), array( 0 => 'h', 1 => 'w', 2 => 'i' ) );

		self::assertTrue( $result->isCorrect );
		self::assertSame( 1.0, $result->score );
	}

	public function test_ordered_slots_wrong_order_is_incorrect(): void {
		$result = $this->checker->check( $this->volume( array( 1, 2, 3 ) ), array( 0 => 'w', 1 => 'h', 2 => 'i' ) );

		self::assertFalse( $result->isCorrect );
		self::assertSame( 0.0, $result->score );
		self::assertSame( array( false, false, true ), $result->itemFeedback );
	}

	#[DataProvider( 'permutations' )]
	public function test_single_group_accepts_any_permutation( array $answer ): void {
		self::assertTrue( $this->checker->check( $this->volume( array( 1, 1, 1 ) ), $answer )->isCorrect );
	}

	public static function permutations(): array {
		return array(
			'h*w*i' => array( array( 'h', 'w', 'i' ) ),
			'i*w*h' => array( array( 'i', 'w', 'h' ) ),
			'w*h*i' => array( array( 'w', 'h', 'i' ) ),
		);
	}

	public function test_groups_do_not_mix(): void {
		$content = $this->content( '{1} + {2} * {3}', array( 'a', 'b', 'c' ), array( 1, 2, 2 ) );

		self::assertTrue( $this->checker->check( $content, array( 'a', 'c', 'b' ) )->isCorrect );
		self::assertFalse( $this->checker->check( $content, array( 'b', 'a', 'c' ) )->isCorrect );
	}

	public function test_repeats_are_compared_as_multiset(): void {
		$content = $this->content( '{1} * {2} * {3}', array( 'h', 'h', 'i' ), array( 1, 1, 1 ) );

		self::assertTrue( $this->checker->check( $content, array( 'h', 'i', 'h' ) )->isCorrect );
		self::assertFalse( $this->checker->check( $content, array( 'h', 'i', 'i' ) )->isCorrect );
	}

	public function test_empty_and_partial_answers_are_incorrect(): void {
		$content = $this->volume( array( 1, 1, 1 ) );

		self::assertFalse( $this->checker->check( $content, array() )->isCorrect );
		self::assertFalse( $this->checker->check( $content, array( 0 => 'h', 1 => '', 2 => '' ) )->isCorrect );
	}

	public function test_extra_tile_is_incorrect(): void {
		self::assertFalse( $this->checker->check( $this->volume( array( 1, 1, 1 ) ), array( 'h', 'w', 'k' ) )->isCorrect );
		self::assertFalse( $this->checker->check( $this->volume( array( 1, 1, 1 ) ), array( 'h', 'w', 'i', 'k' ) )->isCorrect );
	}

	public function test_case_and_spaces_are_ignored(): void {
		$content = $this->content( '{1} * {2}', array( 'H', 'w' ), array( 1, 2 ) );

		self::assertTrue( $this->checker->check( $content, array( ' h ', 'W' ) )->isCorrect );
	}

	public function test_non_array_answer_and_empty_content_are_incorrect(): void {
		self::assertFalse( $this->checker->check( $this->volume( array( 1, 1, 1 ) ), 'h*w*i' )->isCorrect );
		self::assertFalse( $this->checker->check( array(), array( 'h' ) )->isCorrect );
		self::assertFalse( $this->checker->check( array( 'task_drag_fill' => array( 'formula' => 'x', 'slots' => array() ) ), array() )->isCorrect );
	}

	public function test_feedback_marks_each_slot_within_group(): void {
		$result = $this->checker->check( $this->volume( array( 1, 1, 1 ) ), array( 'i', 'k', 'h' ) );

		self::assertFalse( $result->isCorrect );
		self::assertSame( array( true, false, true ), $result->itemFeedback );
	}
}
