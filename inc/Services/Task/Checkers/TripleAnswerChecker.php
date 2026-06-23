<?php

declare( strict_types=1 );

namespace Inc\Services\Task\Checkers;

use Inc\Contracts\TaskCheckerInterface;
use Inc\DTO\Task\CheckResultDTO;

/**
 * Class TripleAnswerChecker
 *
 * Проверяет «тройное» задание (ЕГЭ 19-21).
 * Каждый суб-вопрос стоит 1 балл, maxScore = 3.
 *
 * Ожидаемый формат studentAnswer: array{'19':string,'20':string,'21':string}
 *
 * @package Inc\Services\Task\Checkers
 */
class TripleAnswerChecker implements TaskCheckerInterface {

	private const KEYS = array( '19', '20', '21' );

	public function check( array $content, mixed $studentAnswer ): CheckResultDTO {
		$submitted = is_array( $studentAnswer ) ? $studentAnswer : array();
		$score     = 0.0;
		$feedback  = array();

		foreach ( self::KEYS as $n ) {
			$correct = mb_strtolower( trim( (string) ( $content[ "task_{$n}_answer" ] ?? '' ) ) );
			$student = mb_strtolower( trim( (string) ( $submitted[ $n ] ?? '' ) ) );

			$ok               = $correct !== '' && $correct === $student;
			$feedback[ $n ]   = $ok;
			$score           += $ok ? 1.0 : 0.0;
		}

		return CheckResultDTO::partial( $score, 3.0, $feedback );
	}
}
