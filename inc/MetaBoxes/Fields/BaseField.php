<?php

namespace Inc\MetaBoxes\Fields;

use Inc\Contracts\FieldInterface;

abstract class BaseField implements FieldInterface {
	/**
	 * Хелпер для генерации атрибута name, чтобы он был в массиве плагина.
	 * Например: fs_lms_meta[field_id]
	 */
	protected function get_field_name( string $id ): string {
		return "fs_lms_meta[$id]";
	}

}