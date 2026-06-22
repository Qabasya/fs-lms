<?php

declare( strict_types=1 );

namespace Inc\Enums\Subject;

/**
 * Категория шаблона задачи — крупное деление по типу взаимодействия.
 *
 * Привязывает визуальный шаблон задачи к пункту меню добавления шага:
 * `question` → «Вопрос» (вписать ответ / выбор, авто-проверка),
 * `code`     → «Задание с кодом» (редактор кода + интерпретатор).
 *
 * Шаг-атом (`StepType::Task`) ссылается на задачу; её шаблон несёт категорию,
 * по которой type-first меню фильтрует кандидатов и создание.
 *
 * @package Inc\Enums\Subject
 */
enum TemplateCategory: string {

	/** Вписать ответ / выбрать вариант. Авто-проверяемо. */
	case Question = 'question';

	/** Студент пишет код (редактор, интерпретатор, файлы). */
	case Code = 'code';

	public function label(): string {
		return match ( $this ) {
			self::Question => 'Вопрос',
			self::Code     => 'Задание с кодом',
		};
	}

	public static function fromValueOrDefault( string $value ): self {
		return self::tryFrom( $value ) ?? self::Question;
	}
}