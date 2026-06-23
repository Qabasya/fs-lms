<?php

declare( strict_types=1 );

namespace Inc\Services\Task\Checkers;

use Inc\Contracts\TaskCheckerInterface;
use Inc\DTO\Task\CheckResultDTO;

/**
 * Class MatchingChecker
 *
 * Проверяет задание «Сопоставление пар».
 * Оценка v1: всё или ничего (1 балл / 0). itemFeedback содержит вердикт
 * по каждой паре для UI-подсветки.
 * Сравнение регистронезависимо.
 *
 * Ожидаемый формат studentAnswer: array<array{'left':string,'right':string}>
 *
 * @package Inc\Services\Task\Checkers
 */
class MatchingChecker implements TaskCheckerInterface {

	public function check( array $content, mixed $studentAnswer ): CheckResultDTO {
		$data  = is_array( $content['task_pairs'] ?? null ) ? $content['task_pairs'] : array();
		$pairs = is_array( $data['pairs'] ?? null ) ? $data['pairs'] : array();

		if ( empty( $pairs ) ) {
			return CheckResultDTO::incorrect();
		}

		$correctMap = array();
		foreach ( $pairs as $pair ) {
			$left               = mb_strtolower( trim( (string) ( $pair['left'] ?? '' ) ) );
			$right              = mb_strtolower( trim( (string) ( $pair['right'] ?? '' ) ) );
			$correctMap[ $left ] = $right;
		}

		$submitted = is_array( $studentAnswer ) ? $studentAnswer : array();

		if ( count( $submitted ) !== count( $correctMap ) ) {
			return CheckResultDTO::incorrect();
		}

		$feedback = array();
		$allOk    = true;

		foreach ( $submitted as $i => $pair ) {
			$left         = mb_strtolower( trim( (string) ( $pair['left'] ?? '' ) ) );
			$right        = mb_strtolower( trim( (string) ( $pair['right'] ?? '' ) ) );
			$ok           = isset( $correctMap[ $left ] ) && $correctMap[ $left ] === $right;
			$feedback[ $i ] = $ok;
			if ( ! $ok ) {
				$allOk = false;
			}
		}

		return $allOk
			? CheckResultDTO::correct( 1.0 )
			: new CheckResultDTO( false, 0.0, 1.0, $feedback );
	}
}
