<?php

namespace Inc\Enums;

use Inc\MetaBoxes\Templates\CodeTaskTemplate;
use Inc\MetaBoxes\Templates\CommonConditionTemplate;
use Inc\MetaBoxes\Templates\FileCodeTaskTemplate;
use Inc\MetaBoxes\Templates\FileTaskTemplate;
use Inc\MetaBoxes\Templates\StandardTaskTemplate;
use Inc\MetaBoxes\Templates\TaskTextSolution;
use Inc\MetaBoxes\Templates\ThreeInOneTemplate;
use Inc\MetaBoxes\Templates\TwoFileCodeTaskTemplate;

/**
 * Enum TaskTemplate
 *
 * Перечисление доступных типов шаблонов заданий.
 * Используется для типобезопасного управления шаблонами метабоксов.
 *
 * Каждый кейс содержит:
 * - Строковое значение (ID шаблона) — хранится в БД
 * - Метод class() — возвращает FQCN класса для динамического создания
 * - Метод label() — человекочитаемое название для UI
 *
 * @package Inc\Enums
 */
enum TaskTemplate: string {
	/**
	 * Стандартное задание с одним условием.
	 */
	case STANDARD = 'standard_task';

	/**
	 * Задание "Три в одном" (для ЕГЭ 19-21).
	 */
	case TRIPLE = 'triple_task';

	/**
	 * Задание с общим (неизменяемым) условием.
	 */
	case COMMON = 'common_standard_task';

	case CODE          = 'code_task';
	case FILE_CODE     = 'file_code_task';
	case FILE          = 'file_task';
	case TWO_FILE      = 'two_file_code_task';
	case TEXT_SOLUTION = 'text_task';

	/**
	 * Умный конструктор Enum с фолбеком на STANDARD.
	 *
	 * Если в БД сохранён ID шаблона, которого нет в списке
	 * (например, кастомный шаблон номера), приводим его к STANDARD,
	 * чтобы не ломать интерфейс.
	 *
	 * @param string|null $value Строковое значение из БД
	 *
	 * @return self Соответствующий кейс или STANDARD по умолчанию
	 */
	public static function fromDatabase( ?string $value ): self {
		// Если значение пустое — возвращаем STANDARD
		if ( ! $value ) {
			return self::STANDARD;
		}

		// Пытаемся найти точное совпадение
		$tryCase = self::tryFrom( $value );

		if ( $tryCase ) {
			return $tryCase;
		}

		// Если это не TRIPLE и не COMMON — считаем стандартным визуальным редактором
		return self::STANDARD;
	}

	/**
	 * Возвращает FQCN (полное имя класса) шаблона.
	 *
	 * Используется для динамического создания экземпляра шаблона
	 * через new $className() или Reflection.
	 *
	 * @return string Полное имя класса шаблона
	 */
	public function class(): string {
		return match ( $this ) {
			self::STANDARD => StandardTaskTemplate::class,
			self::TRIPLE => ThreeInOneTemplate::class,
			self::COMMON => CommonConditionTemplate::class,

			self::CODE      => CodeTaskTemplate::class,
			self::FILE_CODE => FileCodeTaskTemplate::class,
			self::FILE      => FileTaskTemplate::class,
			self::TWO_FILE  => TwoFileCodeTaskTemplate::class,
			self::TEXT_SOLUTION  => TaskTextSolution::class,
		};
	}

	/**
	 * Возвращает человекочитаемое название шаблона для boilerplate.
	 *
	 * Используется в выпадающих списках и метках интерфейса.
	 *
	 * @return string Название шаблона
	 */
	public function label(): string {
		return match ( $this ) {
			self::STANDARD => 'Стандартное задание',
			self::TRIPLE => 'Три в одном (ЕГЭ 19-21)',
			self::COMMON => 'Общее условие',
		};
	}
}
