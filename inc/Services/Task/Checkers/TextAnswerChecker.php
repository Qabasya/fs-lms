<?php

declare( strict_types=1 );

namespace Inc\Services\Task\Checkers;

use Inc\Contracts\TaskCheckerInterface;
use Inc\DTO\Task\CheckResultDTO;
use Inc\Shared\Traits\AnswerNormalizer;

/**
 * Class TextAnswerChecker
 *
 * Проверяет текстовый ответ (регистронезависимо) по полю `task_answer`.
 * Покрывает все шаблоны с полем ответа: Standard, Common, Audio, а также
 * код/файловые (Code, FileCode, File) и TextSolution — у них сверяется
 * ТОЛЬКО ответ, сам код/файл не автопроверяется. Ручной лишь FileAnswer (без
 * `task_answer`).
 *
 * У Code/FileCode (`TaskTemplate::hasCodeField()`) ответ ученика может
 * прийти объектом `{text, code}` вместо голой строки — сверяется всё равно
 * только `text`.
 *
 * @package Inc\Services\Task\Checkers
 */
class TextAnswerChecker implements TaskCheckerInterface {

	use AnswerNormalizer;

	public function check( array $content, mixed $studentAnswer ): CheckResultDTO {
		// Код/файловые шаблоны с необязательным полем «Код» (TaskTemplate::hasCodeField())
		// присылают ответ объектом { text, code } вместо голой строки — проверке
		// подлежит только text, код в сверке не участвует.
		if ( is_array( $studentAnswer ) && array_key_exists( 'text', $studentAnswer ) ) {
			$studentAnswer = $studentAnswer['text'];
		}

		// Пробелы и переносы строк в сверке не участвуют вообще (Tasks.md, п. 4) —
		// см. AnswerNormalizer. Ответ ученика при этом хранится и показывается сырым.
		$correct = self::normalizeAnswer( (string) ( $content['task_answer'] ?? '' ) );
		$student = self::normalizeAnswer( (string) $studentAnswer );

		if ( '' === $correct ) {
			return CheckResultDTO::incorrect();
		}

		return $correct === $student
			? CheckResultDTO::correct()
			: CheckResultDTO::incorrect();
	}
}
