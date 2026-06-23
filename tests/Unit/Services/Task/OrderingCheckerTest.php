<?php

declare( strict_types=1 );

namespace Unit\Services\Task;

use Inc\Services\Task\Checkers\OrderingChecker;
use PHPUnit\Framework\TestCase;

class OrderingCheckerTest extends TestCase {

	private OrderingChecker $checker;

	protected function setUp(): void {
		$this->checker = new OrderingChecker();
	}

	private function content( array $items ): array {
		return [ 'task_order_items' => [ 'items' => $items ] ];
	}

	public function test_correct_order(): void {
		$content = $this->content( [ 'Alpha', 'Beta', 'Gamma' ] );
		$result  = $this->checker->check( $content, [ 'Alpha', 'Beta', 'Gamma' ] );
		self::assertTrue( $result->isCorrect );
		self::assertSame( 1.0, $result->score );
	}

	public function test_wrong_order(): void {
		$content = $this->content( [ 'Alpha', 'Beta', 'Gamma' ] );
		$result  = $this->checker->check( $content, [ 'Beta', 'Alpha', 'Gamma' ] );
		self::assertFalse( $result->isCorrect );
		self::assertNotNull( $result->itemFeedback );
		self::assertFalse( $result->itemFeedback[0] );
		self::assertFalse( $result->itemFeedback[1] );
		self::assertTrue( $result->itemFeedback[2] );
	}

	public function test_case_insensitive(): void {
		$content = $this->content( [ 'Alpha', 'Beta' ] );
		$result  = $this->checker->check( $content, [ 'alpha', 'BETA' ] );
		self::assertTrue( $result->isCorrect );
	}

	public function test_wrong_count_fails(): void {
		$content = $this->content( [ 'A', 'B', 'C' ] );
		$result  = $this->checker->check( $content, [ 'A', 'B' ] );
		self::assertFalse( $result->isCorrect );
	}

	public function test_empty_content_returns_incorrect(): void {
		$result = $this->checker->check( [], [] );
		self::assertFalse( $result->isCorrect );
	}
}
