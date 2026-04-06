<?php

namespace Inc\MetaBoxes\Fields;

use Inc\Contracts\FieldInterface;

/**
 * Class BaseField
 *
 * Абстрактный базовый класс для полей метабоксов.
 * Реализует общую логику для всех типов полей.
 *
 * @package Inc\MetaBoxes\Fields
 * @implements FieldInterface
 */
abstract class BaseField implements FieldInterface {
	/**
	 * Хелпер для генерации атрибута name.
	 *
	 * Формирует имя поля в формате массива плагина,
	 * что позволяет собирать все мета-данные в одну опцию.
	 *
	 * @param string $id Идентификатор поля
	 *
	 * @return string Имя поля для атрибута name (fs_lms_meta[field_id])
	 *
	 * @example
	 * $this->get_field_name('task_text')
	 * // Результат: fs_lms_meta[task_text]
	 */
	protected function get_field_name( string $id ): string {
		return "fs_lms_meta[$id]";
	}
}