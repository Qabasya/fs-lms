<?php

declare( strict_types=1 );

namespace Inc\Enums\Course;

/**
 * Тип шага в последовательности (урок / работа / контрольная).
 *
 * «Шаг» — рекурсивный паттерн (Courses.md → ★): урок собирается из шагов всех типов,
 * работа и контрольная — только из task-шагов. Текст/видео/материал — инлайновые
 * (принадлежат уроку), задача/работа/контрольная — ссылочные (на сущность банка).
 *
 * @package Inc\Enums
 */
enum StepType: string {

	/** Теория, объяснение (rich-text, инлайн). */
	case Text = 'text';

	/** Видео (url/embed; позже — запись из S3). */
	case Video = 'video';

	/** Материал: вложение или ссылка на статью. */
	case Material = 'material';

	/** Одна задача (ссылка): самопроверка, без записи сдачи. */
	case Task = 'task';

	/** Работа (ссылка): оцениваемая, сдача + грейдбук (Этап 3). */
	case Work = 'work';

	/** Контрольная/экзамен (ссылка): таймер/попытки/автопроверка (Этап 4). */
	case Assessment = 'assessment';

	/** Уровень сборки: урок (допускает все типы шагов). */
	public const string LEVEL_LESSON = 'lesson';

	/** Уровень сборки: работа (только task-шаги). */
	public const string LEVEL_WORK = 'work';

	/** Уровень сборки: контрольная (только task-шаги). */
	public const string LEVEL_ASSESSMENT = 'assessment';

	/**
	 * Человекочитаемое название типа шага.
	 */
	public function label(): string {
		return match ( $this ) {
			self::Text       => 'Текст',
			self::Video      => 'Видео',
			self::Material   => 'Материал',
			self::Task       => 'Задача',
			self::Work       => 'Работа',
			self::Assessment => 'Контрольная',
		};
	}

	/**
	 * Инлайновый шаг — контент принадлежит уроку (правится в самой карточке),
	 * а не разворачивается в отдельную сущность банка.
	 */
	public function isInline(): bool {
		return match ( $this ) {
			self::Text, self::Video, self::Material => true,
			default                                 => false,
		};
	}

	/**
	 * Ссылочный шаг — указывает на переиспользуемую сущность (задача/работа/контрольная),
	 * разворачивается в её содержимое (drill-down / инлайн-превью).
	 */
	public function isRef(): bool {
		return match ( $this ) {
			self::Task, self::Work, self::Assessment => true,
			default                                  => false,
		};
	}

	/**
	 * Допустимые типы шага для уровня сборки.
	 * Урок — все типы; работа/контрольная — только задачи.
	 *
	 * @param string $level Один из LEVEL_* .
	 *
	 * @return self[]
	 */
	public static function allowedTypesFor( string $level ): array {
		return match ( $level ) {
			self::LEVEL_LESSON                       => self::cases(),
			self::LEVEL_WORK, self::LEVEL_ASSESSMENT => array( self::Task ),
			default                                  => array(),
		};
	}

	/**
	 * Разрешён ли этот тип шага на указанном уровне сборки.
	 */
	public function allowedFor( string $level ): bool {
		return in_array( $this, self::allowedTypesFor( $level ), true );
	}

	/**
	 * Безопасно приводит произвольную строку к типу шага.
	 *
	 * @param string $value Сырое значение.
	 *
	 * @return self Тип шага (по умолчанию — Text).
	 */
	public static function fromValueOrDefault( string $value ): self {
		return self::tryFrom( $value ) ?? self::Text;
	}

	/**
	 * Карта значений для рендера select/модалки: value => label.
	 *
	 * @return array<string, string>
	 */
	public static function options(): array {
		$options = array();
		foreach ( self::cases() as $case ) {
			$options[ $case->value ] = $case->label();
		}

		return $options;
	}
}
