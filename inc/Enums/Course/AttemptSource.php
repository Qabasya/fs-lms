<?php

declare( strict_types=1 );

namespace Inc\Enums\Course;

/**
 * Enum AttemptSource
 *
 * Откуда пришла попытка в `fs_lms_task_attempts`.
 *
 * @package Inc\Enums\Course
 *
 * ### Зачем
 *
 * Таблица попыток изначально обслуживала только задания-шаги урока, где
 * `step_key` — ключ шага из конструктора. Работы (`fs_lms_submissions`) историю
 * не копят: строка сдачи перезаписывается при пересдаче. Чтобы пересдачи работ
 * тоже были видны, их попытки пишутся в ту же таблицу с синтетическим ключом
 * `work:{id}` — формат живёт здесь, а не размазан по сервисам.
 */
enum AttemptSource: string {

	/** Задание-шаг урока: `step_key` — ключ шага из конструктора. */
	case Lesson = 'lesson';

	/** Задача внутри работы: `step_key` = `work:{work_id}`. */
	case Work = 'work';

	/** Префикс синтетического ключа работы. */
	private const string WORK_PREFIX = 'work:';

	/**
	 * Ключ шага для попыток по задачам работы.
	 */
	public static function workStepKey( int $workId ): string {
		return self::WORK_PREFIX . $workId;
	}

	/**
	 * Источник попытки по её `step_key`.
	 */
	public static function fromStepKey( string $stepKey ): self {
		return str_starts_with( $stepKey, self::WORK_PREFIX ) ? self::Work : self::Lesson;
	}

	/**
	 * ID работы из ключа; 0 — ключ не работы.
	 */
	public static function workIdFromStepKey( string $stepKey ): int {
		return self::Work === self::fromStepKey( $stepKey )
			? (int) substr( $stepKey, strlen( self::WORK_PREFIX ) )
			: 0;
	}
}
