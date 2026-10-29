<?php

declare( strict_types=1 );

namespace Inc\Services\Task\Checkers;

use Inc\Contracts\TaskCheckerInterface;
use Inc\DTO\Task\CheckResultDTO;
use Inc\Shared\Traits\AnswerNormalizer;

/**
 * Class DragFillChecker
 *
 * Проверяет задание «Перетаскивание пропусков».
 * Оценка: всё или ничего. itemFeedback — слот => bool.
 *
 * Слоты с одинаковой `group` взаимозаменяемы: ответ верен, если мультимножество плиток в слотах группы
 * равно мультимножеству верных плиток группы. Группы между собой не смешиваются.
 * Сравнение — как у FillChecker (регистр и пробелы не важны).
 *
 * Ожидаемый формат studentAnswer: array<int,string> (индекс слота => плитка, пустой слот — '').
 *
 * @package Inc\Services\Task\Checkers
 */
class DragFillChecker implements TaskCheckerInterface {

	use AnswerNormalizer;

	public function check( array $content, mixed $studentAnswer ): CheckResultDTO {
		$data  = is_array( $content['task_drag_fill'] ?? null ) ? $content['task_drag_fill'] : array();
		$slots = is_array( $data['slots'] ?? null ) ? array_values( $data['slots'] ) : array();

		if ( array() === $slots || ! is_array( $studentAnswer ) || count( $studentAnswer ) > count( $slots ) ) {
			return CheckResultDTO::incorrect();
		}

		// Остаток верных плиток по группам: группа => нормализованная плитка => сколько ещё нужно.
		$pool = array();
		foreach ( $slots as $slot ) {
			$tile                              = self::normalizeStoredAnswer( (string) ( $slot['answer'] ?? '' ) );
			$group                             = (int) ( $slot['group'] ?? 0 );
			$pool[ $group ][ $tile ]           = ( $pool[ $group ][ $tile ] ?? 0 ) + 1;
		}

		$feedback = array();
		$allOk    = true;

		foreach ( $slots as $i => $slot ) {
			$group = (int) ( $slot['group'] ?? 0 );
			$tile  = self::normalizeAnswer( (string) ( $studentAnswer[ $i ] ?? '' ) );
			$ok    = '' !== $tile && ( $pool[ $group ][ $tile ] ?? 0 ) > 0;

			if ( $ok ) {
				--$pool[ $group ][ $tile ];
			} else {
				$allOk = false;
			}
			$feedback[ $i ] = $ok;
		}

		return $allOk
			? CheckResultDTO::correct()
			: new CheckResultDTO( false, 0.0, 1.0, $feedback );
	}
}
