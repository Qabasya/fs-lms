<?php

declare( strict_types=1 );

namespace Inc\Enums\Course;

use Inc\Enums\Subject\TaskTemplate;

/**
 * Тип работы (набора заданий) урока.
 *
 * Тип живёт на самой работе (CPT {key}_works); сдача (submissions.work_type)
 * снапшотится из работы.
 *
 * @package Inc\Enums
 */
enum WorkType: string {

	/** Практика (классная работа). */
	case Practice = 'practice';

	/** Самостоятельная работа. */
	case Independent = 'independent';

	/** Домашнее задание. */
	case Homework = 'homework';

	/** Опрос: короткие вопросы с ограниченным набором шаблонов заданий ({@see self::allowedTemplates()}). */
	case Survey = 'survey';

	/**
	 * Человекочитаемое название типа работы.
	 *
	 * @return string
	 */
	public function label(): string {
		return match ( $this ) {
			self::Practice    => 'Практика',
			self::Independent => 'Самостоятельная работа',
			self::Homework    => 'Домашнее задание',
			self::Survey      => 'Опрос',
		};
	}

	/**
	 * Шаблоны заданий, которые можно класть в работу этого типа; `null` — без ограничений.
	 * Опрос собирается только из вопросов с готовыми вариантами и простым ответом.
	 *
	 * @return TaskTemplate[]|null
	 */
	public function allowedTemplates(): ?array {
		return self::Survey === $this
			? array( TaskTemplate::Choice, TaskTemplate::Matching, TaskTemplate::Ordering, TaskTemplate::Fill, TaskTemplate::Standard )
			: null;
	}

	/**
	 * Есть ли в работе этого типа кнопка «Проверить ответ» (самопроверка до сдачи).
	 * Только у практики: самостоятельная и домашняя оцениваются по итогу сдачи.
	 */
	public function allowsInlineCheck(): bool {
		return self::Practice === $this;
	}

	/**
	 * Безопасно приводит произвольную строку к валидному типу работы.
	 *
	 * @param string $value Сырое значение.
	 *
	 * @return self Тип работы (по умолчанию — Practice).
	 */
	public static function fromValueOrDefault( string $value ): self {
		return self::tryFrom( $value ) ?? self::Practice;
	}

	/**
	 * Карта значений для рендера select: value => label.
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
