<?php

declare( strict_types=1 );

namespace Unit\Services\Task;

use Inc\Services\Task\Checkers\TextAnswerChecker;
use PHPUnit\Framework\TestCase;

class TextAnswerCheckerTest extends TestCase {

	private TextAnswerChecker $checker;

	protected function setUp(): void {
		$this->checker = new TextAnswerChecker();
	}

	public function test_single_line_answer_is_case_insensitive(): void {
		$result = $this->checker->check( [ 'task_answer' => 'Ответ' ], 'ОТВЕТ ' );

		self::assertTrue( $result->isCorrect );
	}

	public function test_multiline_answer_matches_exactly(): void {
		$result = $this->checker->check(
			[ 'task_answer' => "первая\nвторая" ],
			"первая\nвторая"
		);

		self::assertTrue( $result->isCorrect );
	}

	public function test_multiline_answer_normalizes_crlf_from_browser(): void {
		$result = $this->checker->check(
			[ 'task_answer' => "первая\nвторая" ],
			"первая\r\nвторая"
		);

		self::assertTrue( $result->isCorrect );
	}

	public function test_multiline_answer_ignores_trailing_spaces_in_lines(): void {
		$result = $this->checker->check(
			[ 'task_answer' => "первая  \nвторая" ],
			"первая\nвторая  "
		);

		self::assertTrue( $result->isCorrect );
	}

	/**
	 * Tasks.md, п. 4: пробельные символы в сверке не участвуют вообще — тот же
	 * ответ без переноса строки засчитывается.
	 */
	public function test_missing_line_break_is_still_correct(): void {
		$result = $this->checker->check(
			[ 'task_answer' => "первая\nвторая" ],
			'перваявторая'
		);

		self::assertTrue( $result->isCorrect );
	}

	/** Пример из Tasks.md, п. 4: «Макс: 2; 3» ↔ «Макс:2;3». */
	public function test_inner_spaces_are_ignored(): void {
		$result = $this->checker->check( [ 'task_answer' => 'Макс: 2; 3' ], 'Макс:2;3' );

		self::assertTrue( $result->isCorrect );
	}

	public function test_extra_spaces_and_line_breaks_are_ignored(): void {
		$result = $this->checker->check( [ 'task_answer' => 'Макс: 2; 3' ], " Макс :\n2 ;  3 " );

		self::assertTrue( $result->isCorrect );
	}

	public function test_different_answer_is_still_wrong(): void {
		$result = $this->checker->check( [ 'task_answer' => 'Макс: 2; 3' ], 'Макс:2;4' );

		self::assertFalse( $result->isCorrect );
	}

	public function test_empty_correct_answer_is_never_correct(): void {
		$result = $this->checker->check( [ 'task_answer' => '' ], '' );

		self::assertFalse( $result->isCorrect );
	}
}
