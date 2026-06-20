<?php

declare( strict_types=1 );

namespace Inc\MetaBoxes\Fields;

use Inc\Contracts\FieldInterface;
use Inc\Enums\Wp\PostMetaName;
use Inc\Shared\Traits\Sanitizer;

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

	use Sanitizer;
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
		return PostMetaName::Meta->value . "[$id]";
	}
}