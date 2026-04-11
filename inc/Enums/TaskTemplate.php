<?php

namespace Inc\Enums;

use Inc\MetaBoxes\Templates\StandardTaskTemplate;
use Inc\MetaBoxes\Templates\ThreeInOneTemplate;
use Inc\MetaBoxes\Templates\CommonConditionTemplate;

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
enum TaskTemplate: string
{
	/**
	 * Одно условие
	 */
	case STANDARD = 'standard_task';

	/**
	 * Три условия
	 */
	case TRIPLE = 'triple_task';

	/**
	 * Два условия (одно не меняется)
	 */
	case COMMON = 'common_standard_task';

	/**
	 * Возвращает FQCN (полное имя класса) шаблона.
	 *
	 * Используется для динамического создания экземпляра шаблона
	 * через new $className() или Reflection.
	 *
	 * @return string Полное имя класса шаблона
	 */
	public function class(): string
	{
		return match ($this) {
			self::STANDARD => StandardTaskTemplate::class,
			self::TRIPLE   => ThreeInOneTemplate::class,
			self::COMMON   => CommonConditionTemplate::class,
		};
	}

	/**
	 * Возвращает человекочитаемое название шаблона для интерфейса.
	 *
	 * Используется в выпадающих списках и метках интерфейса.
	 *
	 * @return string Название шаблона
	 */
	public function label(): string
	{
		return match ($this) {
			self::STANDARD => 'Стандартное задание',
			self::TRIPLE   => 'Три в одном (ЕГЭ 19-21)',
			self::COMMON   => 'Общее условие',
		};
	}
}