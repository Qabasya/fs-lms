<?php

declare( strict_types=1 );

namespace Inc\Enums\Course;

/**
 * Статус прохождения шага урока (таблица `fs_lms_lesson_progress`, ★).
 *
 * locked → недоступен (гейтинг), available → открыт, viewed → просмотрен (инлайн-шаг),
 * completed → пройден (для оцениваемых — резолвится из сдач/попыток, T1.5.9).
 *
 * @package Inc\Enums
 */
enum ProgressStatus: string {

	case Locked    = 'locked';
	case Available = 'available';
	case Viewed    = 'viewed';
	case Completed = 'completed';
	case Failed    = 'failed';

	/** Пройден ли шаг (зачитывается в «урок завершён»). */
	public function isComplete(): bool {
		return self::Completed === $this;
	}

	/** Человекочитаемая подпись. */
	public function label(): string {
		return match ( $this ) {
			self::Locked    => 'Заблокирован',
			self::Available => 'Доступен',
			self::Viewed    => 'Просмотрен',
			self::Completed => 'Пройден',
			self::Failed    => 'Провален',
		};
	}

	/** Безопасно приводит строку к статусу (по умолчанию — locked). */
	public static function fromValueOrDefault( string $value ): self {
		return self::tryFrom( $value ) ?? self::Locked;
	}
}
