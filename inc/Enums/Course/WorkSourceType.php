<?php

declare( strict_types=1 );

namespace Inc\Enums\Course;

/**
 * Источник результата работы: обычная сдача или попытка контрольной.
 *
 * Раньше это были строковые литералы `'submission'`/`'attempt'` в четырёх
 * `match` трёх сервисов (аудит §2.7) — опечатка молча уводила в ветку по умолчанию.
 * Значения совпадают с тем, что уходит на фронт (`source_type` в дневнике).
 */
enum WorkSourceType: string {

	/** Сдача работы учеником (`submissions`). */
	case Submission = 'submission';

	/** Попытка прохождения контрольной (`assessment_attempts`). */
	case Attempt = 'attempt';

	/**
	 * Разбор значения из запроса/хранилища; неизвестное — null.
	 *
	 * @param string $value Сырое значение
	 */
	public static function fromValueOrNull( string $value ): ?self {
		return self::tryFrom( $value );
	}
}
