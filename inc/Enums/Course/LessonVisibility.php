<?php

declare( strict_types=1 );

namespace Inc\Enums\Course;

/**
 * Видимость урока в программе группы (fs_lms_group_lessons.visibility).
 *
 * Единый источник допустимых значений видимости: используется при валидации
 * входных данных и в политике доступа/публикации.
 *
 * @package Inc\Enums
 */
enum LessonVisibility: string {

	/** Скрыт — недоступен ученикам. */
	case Hidden = 'hidden';

	/** Открыт — доступен ученикам (с учётом дат/статуса). */
	case Open = 'open';

	/** В архиве — резолвится в существующих ссылках, новых не открывает. */
	case Archived = 'archived';

	/**
	 * Человекочитаемое название.
	 *
	 * @return string
	 */
	public function label(): string {
		return match ( $this ) {
			self::Hidden   => 'Скрыт',
			self::Open     => 'Открыт',
			self::Archived => 'В архиве',
		};
	}

	/**
	 * Безопасно приводит произвольную строку к валидной видимости.
	 *
	 * @param string $value Сырое значение.
	 *
	 * @return self Видимость (по умолчанию — Hidden).
	 */
	public static function fromValueOrDefault( string $value ): self {
		return self::tryFrom( $value ) ?? self::Hidden;
	}
}
