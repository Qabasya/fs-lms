<?php

declare( strict_types=1 );

namespace Inc\Contracts;

use Inc\DTO\Task\CheckResultDTO;

/**
 * Interface TaskCheckerInterface
 *
 * Контракт авто-проверки ответа ученика на задание.
 * Реализации регистрируются в TaskCheckerRegistry (T6.9).
 * Шаблоны без чекера → ручная проверка.
 *
 * @package Inc\Contracts
 */
interface TaskCheckerInterface {

	/**
	 * Проверяет ответ ученика.
	 *
	 * @param array $content       Массив fs_lms_meta задачи (содержание: условие, варианты и т.д.).
	 * @param mixed $studentAnswer Ответ ученика. Тип зависит от типа задачи:
	 *   - text/audio:   string
	 *   - triple:       array{'19':string,'20':string,'21':string}
	 *   - choice:       string[] (IDs выбранных вариантов)
	 *   - matching:     array<array{'left':string,'right':string}>
	 *   - ordering:     string[] (элементы в порядке ученика)
	 *   - fill:         array<int,string> (gap_index => ответ)
	 */
	public function check( array $content, mixed $studentAnswer ): CheckResultDTO;
}
