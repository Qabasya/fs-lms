<?php

declare( strict_types=1 );

namespace Inc\Enums\Course;

/**
 * Режим доступа группы (fs_lms_groups.access_mode, Эпик 15).
 *
 * Определяет, как ученикам открывается программа группы: по расписанию
 * занятий или сразу целиком (открытый self-paced курс).
 *
 * @package Inc\Enums
 */
enum AccessMode: string {

	/** По расписанию — уроки открываются датами занятий/ручной публикацией. */
	case Scheduled = 'scheduled';

	/** Открытая — программа опубликована целиком, контент доступен сразу. */
	case Open = 'open';

	/**
	 * Индивидуальная — один ученик; расписания группы нет, преподаватель сам ставит занятия
	 * в КТП на любые дни и время (автораспределения и публикации КТП нет), занятия видны ученику сразу.
	 */
	case Individual = 'individual';

	/**
	 * Человекочитаемое название.
	 *
	 * @return string
	 */
	public function label(): string {
		return match ( $this ) {
			self::Scheduled => 'По расписанию',
			self::Open      => 'Открытая (свободное прохождение)',
			self::Individual => 'Индивидуальная',
		};
	}

	/**
	 * У группы нет штатного расписания (`groups.meetings` пуст): открытая и индивидуальная.
	 *
	 * @return bool
	 */
	public function isScheduleless(): bool {
		return self::Scheduled !== $this;
	}

	/**
	 * Безопасно приводит произвольную строку к валидному режиму.
	 *
	 * @param string $value Сырое значение (в т.ч. отсутствующая колонка у старых строк).
	 *
	 * @return self Режим (по умолчанию — Scheduled).
	 */
	public static function fromValueOrDefault( string $value ): self {
		return self::tryFrom( $value ) ?? self::Scheduled;
	}
}
