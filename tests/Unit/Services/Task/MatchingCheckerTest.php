<?php

declare( strict_types=1 );

namespace Unit\Services\Task;

use Inc\Services\Task\Checkers\MatchingChecker;
use PHPUnit\Framework\TestCase;

class MatchingCheckerTest extends TestCase {

	private MatchingChecker $checker;

	protected function setUp(): void {
		$this->checker = new MatchingChecker();
	}

	private function content( array $pairs ): array {
		return [ 'task_pairs' => [ 'pairs' => $pairs ] ];
	}

	public function test_all_correct(): void {
		$content = $this->content( [
			[ 'left' => 'Кошка', 'right' => 'Мяукает' ],
			[ 'left' => 'Собака', 'right' => 'Лает' ],
		] );
		$answer = [
			[ 'left' => 'Кошка', 'right' => 'Мяукает' ],
			[ 'left' => 'Собака', 'right' => 'Лает' ],
		];
		$result = $this->checker->check( $content, $answer );
		self::assertTrue( $result->isCorrect );
		self::assertSame( 1.0, $result->score );
	}

	public function test_case_insensitive(): void {
		$content = $this->content( [
			[ 'left' => 'Кошка', 'right' => 'Мяукает' ],
		] );
		$result = $this->checker->check( $content, [
			[ 'left' => 'кошка', 'right' => 'мяукает' ],
		] );
		self::assertTrue( $result->isCorrect );
	}

	public function test_one_wrong_fails_all(): void {
		$content = $this->content( [
			[ 'left' => 'Кошка', 'right' => 'Мяукает' ],
			[ 'left' => 'Собака', 'right' => 'Лает' ],
		] );
		$answer = [
			[ 'left' => 'Кошка', 'right' => 'Мяукает' ],
			[ 'left' => 'Собака', 'right' => 'Мяукает' ],
		];
		$result = $this->checker->check( $content, $answer );
		self::assertFalse( $result->isCorrect );
		self::assertNotNull( $result->itemFeedback );
	}

	public function test_wrong_count_fails(): void {
		$content = $this->content( [
			[ 'left' => 'A', 'right' => 'X' ],
			[ 'left' => 'B', 'right' => 'Y' ],
		] );
		$result = $this->checker->check( $content, [
			[ 'left' => 'A', 'right' => 'X' ],
		] );
		self::assertFalse( $result->isCorrect );
	}

	public function test_empty_content_returns_incorrect(): void {
		$result = $this->checker->check( [], [] );
		self::assertFalse( $result->isCorrect );
	}
}
