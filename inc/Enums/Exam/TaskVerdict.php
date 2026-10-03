<?php

declare( strict_types=1 );

namespace Inc\Enums\Exam;

/**
 * Вердикты задач экзамена (7.2).
 */
enum TaskVerdict: string {
	/**
	 * Ожидает проверки (остальные задания ещё не проверены,
	 * или есть хотя бы одно задание на ручной проверке).
	 */
	case Pending = 'pending';

	/** Задание решено правильно. */
	case Correct = 'correct';

	/** Задание решено неправильно. */
	case Incorrect = 'incorrect';

	/** Задание решено частично (ручная проверка). */
	case Partial = 'partial';

	/** Задание не отвечено. */
	case Unanswered = 'unanswered';

	/**
	 * Проверить, является ли вердикт "успешным".
	 */
	public function isPassing(): bool {
		return self::Correct === $this || self::Partial === $this;
	}

	/**
	 * Проверить, требует ли вердикт ещё проверку.
	 */
	public function isIncomplete(): bool {
		return self::Pending === $this || self::Partial === $this;
	}
}
