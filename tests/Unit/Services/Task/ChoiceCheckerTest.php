<?php

declare( strict_types=1 );

namespace Unit\Services\Task;

use Inc\Services\Task\Checkers\ChoiceChecker;
use PHPUnit\Framework\TestCase;

class ChoiceCheckerTest extends TestCase {

	private ChoiceChecker $checker;

	protected function setUp(): void {
		$this->checker = new ChoiceChecker();
	}

	private function content( array $options, bool $multiple = false ): array {
		return [ 'task_options' => [ 'multiple' => $multiple, 'options' => $options ] ];
	}

	public function test_single_correct_answer(): void {
		$content = $this->content( [
			[ 'id' => '0', 'text' => 'A', 'correct' => true ],
			[ 'id' => '1', 'text' => 'B', 'correct' => false ],
		] );
		$result = $this->checker->check( $content, [ '0' ] );
		self::assertTrue( $result->isCorrect );
		self::assertSame( 1.0, $result->score );
	}

	public function test_single_wrong_answer(): void {
		$content = $this->content( [
			[ 'id' => '0', 'text' => 'A', 'correct' => true ],
			[ 'id' => '1', 'text' => 'B', 'correct' => false ],
		] );
		$result = $this->checker->check( $content, [ '1' ] );
		self::assertFalse( $result->isCorrect );
		self::assertSame( 0.0, $result->score );
	}

	public function test_multiple_all_correct(): void {
		$content = $this->content( [
			[ 'id' => '0', 'text' => 'A', 'correct' => true ],
			[ 'id' => '1', 'text' => 'B', 'correct' => true ],
			[ 'id' => '2', 'text' => 'C', 'correct' => false ],
		], true );
		$result = $this->checker->check( $content, [ '1', '0' ] );
		self::assertTrue( $result->isCorrect );
	}

	public function test_multiple_partial_incorrect(): void {
		$content = $this->content( [
			[ 'id' => '0', 'text' => 'A', 'correct' => true ],
			[ 'id' => '1', 'text' => 'B', 'correct' => true ],
			[ 'id' => '2', 'text' => 'C', 'correct' => false ],
		], true );
		$result = $this->checker->check( $content, [ '0' ] );
		self::assertFalse( $result->isCorrect );
	}

	public function test_extra_selection_incorrect(): void {
		$content = $this->content( [
			[ 'id' => '0', 'text' => 'A', 'correct' => true ],
			[ 'id' => '1', 'text' => 'B', 'correct' => false ],
		] );
		$result = $this->checker->check( $content, [ '0', '1' ] );
		self::assertFalse( $result->isCorrect );
	}

	public function test_no_options_empty_selection_matches(): void {
		// No options defined → no correct IDs → empty selection equals empty correct set.
		$result = $this->checker->check( [], [] );
		self::assertTrue( $result->isCorrect );
	}
}
