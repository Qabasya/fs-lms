<?php

declare( strict_types=1 );

namespace Inc\Enums\Course;

/**
 * Политика назначения курса группе.
 *
 * @package Inc\Enums
 */
enum AssignmentPolicy: string {

	/** Дописать уроки курса к текущей программе. */
	case Append = 'append';

	/** Заменить текущую программу уроками курса. */
	case Replace = 'replace';

	/**
	 * Безопасно приводит произвольную строку к валидной политике.
	 *
	 * @param string $value Сырое значение.
	 *
	 * @return self Политика (по умолчанию — Append).
	 */
	public static function fromValueOrDefault( string $value ): self {
		return self::tryFrom( $value ) ?? self::Append;
	}
}
