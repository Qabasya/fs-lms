<?php

declare( strict_types=1 );

namespace Unit\Services\Task;

use Inc\Services\Task\Checkers\FillChecker;
use PHPUnit\Framework\TestCase;

class FillCheckerTest extends TestCase {

	private FillChecker $checker;

	protected function setUp(): void {
		$this->checker = new FillChecker();
	}

	private function content( string $text ): array {
		return [ 'task_gap_text' => [ 'text' => $text ] ];
	}

	public function test_single_gap_correct(): void {
		$content = $this->content( 'Столица России — [[Москва]].' );
		$result  = $this->checker->check( $content, [ 0 => 'Москва' ] );
		self::assertTrue( $result->isCorrect );
		self::assertSame( 1.0, $result->score );
	}

	public function test_single_gap_case_insensitive(): void {
		$content = $this->content( 'Столица России — [[Москва]].' );
		$result  = $this->checker->check( $content, [ 0 => 'москва' ] );
		self::assertTrue( $result->isCorrect );
	}

	public function test_single_gap_wrong(): void {
		$content = $this->content( 'Столица России — [[Москва]].' );
		$result  = $this->checker->check( $content, [ 0 => 'Питер' ] );
		self::assertFalse( $result->isCorrect );
		self::assertSame( 0.0, $result->score );
		self::assertFalse( $result->itemFeedback[0] );
	}

	public function test_multiple_gaps_all_correct(): void {
		$content = $this->content( '[[Кошка]] мяукает, [[Собака]] лает.' );
		$result  = $this->checker->check( $content, [ 0 => 'Кошка', 1 => 'Собака' ] );
		self::assertTrue( $result->isCorrect );
	}

	public function test_multiple_gaps_partial_wrong(): void {
		$content = $this->content( '[[Кошка]] мяукает, [[Собака]] лает.' );
		$result  = $this->checker->check( $content, [ 0 => 'Кошка', 1 => 'Кот' ] );
		self::assertFalse( $result->isCorrect );
		self::assertTrue( $result->itemFeedback[0] );
		self::assertFalse( $result->itemFeedback[1] );
	}

	public function test_synonyms_accepted(): void {
		$content = $this->content( 'Это [[большой|огромный|крупный]] город.' );
		$result  = $this->checker->check( $content, [ 0 => 'огромный' ] );
		self::assertTrue( $result->isCorrect );
	}

	public function test_empty_text_returns_incorrect(): void {
		$result = $this->checker->check( $this->content( '' ), [] );
		self::assertFalse( $result->isCorrect );
	}

	public function test_text_without_gaps_returns_incorrect(): void {
		$result = $this->checker->check( $this->content( 'Обычный текст без пропусков.' ), [] );
		self::assertFalse( $result->isCorrect );
	}
}
