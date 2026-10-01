<?php

declare( strict_types=1 );

namespace Inc\Services\Assessment;

/**
 * Class ArchiveTaskNumber
 *
 * Номер архивного задания → номер «живого» задания КИМ.
 *
 * Архивные задания лежат в той же таксономии `{key}_task_number`, но под номером с «10»
 * впереди: старое №3 — это №103, старое №17 — №1017 (допустима и запись 117). Для
 * людей и бланка номер остаётся архивным (так задание узнают), а всё, что зависит от
 * устройства задания, — форма ответа, число позиций в листе ответов, составной блок
 * 19–21 — должно считаться по «живому» номеру: у архивного №117 та же табличная форма,
 * что у №17. Реальных номеров больше {@see self::MAX_NUMBER} не бывает, поэтому
 * сдвинутые значения с живыми не пересекаются.
 *
 * @package Inc\Services\Assessment
 */
class ArchiveTaskNumber {

	/** Наибольший номер задания КИМ (КЕГЭ); архивным считается только сдвиг в пределах 1…MAX. */
	private const MAX_NUMBER = 27;

	/**
	 * Номер для расчётов: архивный (103, 117, 1017, 1003) приводится к живому (3, 17, 17, 3),
	 * остальные — без изменений.
	 *
	 * @param int $number Номер задания из таксономии (0 — номер не определён)
	 */
	public function base( int $number ): int {
		foreach ( array( 1000, 100 ) as $shift ) {
			if ( $number > $shift && $number <= $shift + self::MAX_NUMBER ) {
				return $number - $shift;
			}
		}

		return $number;
	}

	/**
	 * То же для номера-строки (имя терма, ручной номер работы): нецифровое значение
	 * («19-21», «13.1», пусто) возвращается как есть.
	 *
	 * @param string $number Номер задания
	 */
	public function baseOf( string $number ): string {
		$number = trim( $number );

		return ctype_digit( $number ) ? (string) $this->base( (int) $number ) : $number;
	}
}
