<?php

declare( strict_types=1 );

namespace Inc\Enums\Course;

/**
 * Статус занятия (fs_lms_group_lessons.status): план/факт проведения.
 *
 * По решению D3: `held` фиксирует «занятие проведено»; `cancelled`/`moved`
 * освобождают слот и сдвигают нерассказанный хвост при `reflow`
 * (индивидуальные из раскладки исключены независимо от статуса).
 *
 * @package Inc\Enums\Course
 */
enum LessonStatus: string {

	/** Запланировано (по умолчанию). */
	case Scheduled = 'scheduled';

	/** Проведено (факт). */
	case Held = 'held';

	/** Отменено. */
	case Cancelled = 'cancelled';

	/** Перенесено. */
	case Moved = 'moved';

	/** Человекочитаемое название. */
	public function label(): string {
		return match ( $this ) {
			self::Scheduled => 'Запланировано',
			self::Held      => 'Проведено',
			self::Cancelled => 'Отменено',
			self::Moved     => 'Перенесено',
		};
	}

	/** Освобождает слот и сдвигает хвост нерассказанных тем в reflow. */
	public function freesSlot(): bool {
		return in_array( $this, array( self::Cancelled, self::Moved ), true );
	}

	/** Безопасно приводит произвольную строку к статусу (по умолчанию — Scheduled). */
	public static function fromValueOrDefault( string $value ): self {
		return self::tryFrom( $value ) ?? self::Scheduled;
	}
}
