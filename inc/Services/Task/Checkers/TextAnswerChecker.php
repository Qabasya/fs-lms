<?php

declare( strict_types=1 );

namespace Inc\Services\Task\Checkers;

use Inc\Contracts\TaskCheckerInterface;
use Inc\DTO\Task\CheckResultDTO;

/**
 * Class TextAnswerChecker
 *
 * Проверяет текстовый ответ (регистронезависимо).
 * Покрывает: Standard, Common, Audio — все имеют `task_answer` как строку.
 *
 * @package Inc\Services\Task\Checkers
 */
class TextAnswerChecker implements TaskCheckerInterface {

	public function check( array $content, mixed $studentAnswer ): CheckResultDTO {
		$correct = mb_strtolower( trim( (string) ( $content['task_answer'] ?? '' ) ) );
		$student = mb_strtolower( trim( (string) $studentAnswer ) );

		if ( '' === $correct ) {
			return CheckResultDTO::incorrect();
		}

		return $correct === $student
			? CheckResultDTO::correct()
			: CheckResultDTO::incorrect();
	}
}
