<?php

declare( strict_types=1 );

namespace Inc\Enums\Course;

/**
 * Тип занятия в программе группы (fs_lms_group_lessons.kind).
 *
 * По решению D3: эволюционируем `group_lessons`, отдельной таблицы занятий нет.
 * `individual` — занятие на одного ученика (`student_person_id`); НЕ входит в
 * программу группы (`position`) и НЕ участвует в раскладке `reflow` (привязано
 * к дате, а не к последовательности). Отработок нет — всё негрупповое = `individual`.
 *
 * @package Inc\Enums\Course
 */
enum LessonKind: string {

	/** Групповое занятие программы. */
	case Group = 'group';

	/** Индивидуальное занятие на одного ученика. */
	case Individual = 'individual';

	/** Человекочитаемое название. */
	public function label(): string {
		return match ( $this ) {
			self::Group      => 'Групповое',
			self::Individual => 'Индивидуальное',
		};
	}

	public function isIndividual(): bool {
		return self::Individual === $this;
	}

	/** Безопасно приводит произвольную строку к виду занятия (по умолчанию — Group). */
	public static function fromValueOrDefault( string $value ): self {
		return self::tryFrom( $value ) ?? self::Group;
	}
}
