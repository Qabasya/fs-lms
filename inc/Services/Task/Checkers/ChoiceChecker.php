<?php

declare( strict_types=1 );

namespace Inc\Services\Task\Checkers;

use Inc\Contracts\TaskCheckerInterface;
use Inc\DTO\Task\CheckResultDTO;

/**
 * Class ChoiceChecker
 *
 * Проверяет задание «Выбор варианта ответа».
 * Множество выбранных ID должно совпасть с множеством правильных ID.
 *
 * Ожидаемый формат studentAnswer: string[] (IDs выбранных вариантов).
 *
 * @package Inc\Services\Task\Checkers
 */
class ChoiceChecker implements TaskCheckerInterface {

	public function check( array $content, mixed $studentAnswer ): CheckResultDTO {
		$data    = is_array( $content['task_options'] ?? null ) ? $content['task_options'] : array();
		$options = is_array( $data['options'] ?? null ) ? $data['options'] : array();

		$correctIds = array();
		foreach ( $options as $opt ) {
			if ( ! empty( $opt['correct'] ) ) {
				$correctIds[] = (string) ( $opt['id'] ?? '' );
			}
		}
		sort( $correctIds );

		$submitted = is_array( $studentAnswer )
			? array_map( 'strval', $studentAnswer )
			: array( (string) $studentAnswer );
		sort( $submitted );

		return $correctIds === $submitted
			? CheckResultDTO::correct()
			: CheckResultDTO::incorrect();
	}
}
