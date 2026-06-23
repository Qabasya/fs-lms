<?php

declare( strict_types=1 );

namespace Inc\Services\Task\Checkers;

use Inc\Contracts\TaskCheckerInterface;
use Inc\DTO\Task\CheckResultDTO;

/**
 * Class OrderingChecker
 *
 * Проверяет задание «Сортировка».
 * Оценка v1: всё или ничего. itemFeedback — позиция => bool.
 * Сравнение регистронезависимо.
 *
 * Ожидаемый формат studentAnswer: string[] (элементы в порядке ученика).
 *
 * @package Inc\Services\Task\Checkers
 */
class OrderingChecker implements TaskCheckerInterface {

	public function check( array $content, mixed $studentAnswer ): CheckResultDTO {
		$data    = is_array( $content['task_order_items'] ?? null ) ? $content['task_order_items'] : array();
		$correct = is_array( $data['items'] ?? null ) ? $data['items'] : array();

		if ( empty( $correct ) ) {
			return CheckResultDTO::incorrect();
		}

		$submitted = is_array( $studentAnswer ) ? array_values( $studentAnswer ) : array();

		if ( count( $submitted ) !== count( $correct ) ) {
			return CheckResultDTO::incorrect();
		}

		$feedback = array();
		$allOk    = true;

		foreach ( $correct as $i => $item ) {
			$ok           = mb_strtolower( trim( (string) $item ) )
				=== mb_strtolower( trim( (string) ( $submitted[ $i ] ?? '' ) ) );
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
