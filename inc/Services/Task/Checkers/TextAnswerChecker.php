<?php

declare( strict_types=1 );

namespace Inc\Services\Task\Checkers;

use Inc\Contracts\TaskCheckerInterface;
use Inc\DTO\Task\CheckResultDTO;

/**
 * Class TextAnswerChecker
 *
 * Проверяет текстовый ответ (регистронезависимо) по полю `task_answer`.
 * Покрывает все шаблоны с полем ответа: Standard, Common, Audio, а также
 * код/файловые (Code, FileCode, File, TwoFile) и TextSolution — у них сверяется
 * ТОЛЬКО ответ, сам код/файл не автопроверяется. Ручной лишь FileAnswer (без
 * `task_answer`).
 *
 * У Code/FileCode/TwoFile (`TaskTemplate::hasCodeField()`) ответ ученика может
 * прийти объектом `{text, code}` вместо голой строки — сверяется всё равно
 * только `text`.
 *
 * @package Inc\Services\Task\Checkers
 */
class TextAnswerChecker implements TaskCheckerInterface {

	public function check( array $content, mixed $studentAnswer ): CheckResultDTO {
		// Код/файловые шаблоны с необязательным полем «Код» (TaskTemplate::hasCodeField())
		// присылают ответ объектом { text, code } вместо голой строки — проверке
		// подлежит только text, код в сверке не участвует.
		if ( is_array( $studentAnswer ) && array_key_exists( 'text', $studentAnswer ) ) {
			$studentAnswer = $studentAnswer['text'];
		}

		$correct = $this->normalize( (string) ( $content['task_answer'] ?? '' ) );
		$student = $this->normalize( (string) $studentAnswer );

		if ( '' === $correct ) {
			return CheckResultDTO::incorrect();
		}

		return $correct === $student
			? CheckResultDTO::correct()
			: CheckResultDTO::incorrect();
	}

	/**
	 * Приводит ответ к сравнимому виду: регистр, пробелы по краям и — для
	 * многострочных ответов — переводы строк (CRLF/CR браузеров → LF) и
	 * хвостовые пробелы каждой строки.
	 */
	private function normalize( string $value ): string {
		$value = str_replace( array( "\r\n", "\r" ), "\n", $value );
		$lines = array_map( 'rtrim', explode( "\n", $value ) );

		return mb_strtolower( trim( implode( "\n", $lines ) ) );
	}
}
