<?php

declare( strict_types=1 );

namespace Inc\Services\Task\Checkers;

use Inc\Contracts\TaskCheckerInterface;
use Inc\DTO\Task\CheckResultDTO;
use Inc\Services\Task\FillTextParser;

/**
 * Class FillChecker
 *
 * Проверяет задание «Пропуски в тексте».
 * Оценка v1: всё или ничего. itemFeedback — gap_index => bool.
 * Синонимы и регистр учитываются через FillTextParser::checkGap().
 *
 * Ожидаемый формат studentAnswer: array<int,string> (gap_index => ответ ученика).
 *
 * @package Inc\Services\Task\Checkers
 */
class FillChecker implements TaskCheckerInterface {

	public function check( array $content, mixed $studentAnswer ): CheckResultDTO {
		$data = is_array( $content['task_gap_text'] ?? null ) ? $content['task_gap_text'] : array();
		$text = trim( (string) ( $data['text'] ?? '' ) );

		if ( '' === $text ) {
			return CheckResultDTO::incorrect();
		}

		$parsed   = FillTextParser::parse( $text );
		$gapCount = $parsed->gapCount();

		if ( 0 === $gapCount ) {
			return CheckResultDTO::incorrect();
		}

		$submitted = is_array( $studentAnswer ) ? $studentAnswer : array();
		$feedback  = array();
		$allOk     = true;

		for ( $i = 0; $i < $gapCount; $i++ ) {
			$ok           = FillTextParser::checkGap( $parsed, $i, (string) ( $submitted[ $i ] ?? '' ) );
			$feedback[ $i ] = $ok;
			if ( ! $ok ) {
				$allOk = false;
			}
		}

		return $allOk
			? CheckResultDTO::correct()
			: new CheckResultDTO( false, 0.0, 1.0, $feedback );
	}
}
