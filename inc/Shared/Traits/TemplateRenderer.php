<?php

namespace Inc\Shared\Traits;

/**
 * Trait TemplateRenderer
 *
 * Трейт для рендеринга PHP-шаблонов в административной панели.
 *
 * Обеспечивает единый механизм загрузки шаблонов с передачей
 * переменных через extract(). Используется в классах-коллбеках
 * для отображения страниц админ-панели.
 *
 * @package Inc\Shared\Traits
 */

/**
 * Trait TemplateRenderer
 */
trait TemplateRenderer {
	/**
	 * Рендерит шаблон, принимая данные в виде массива или объекта (DTO).
	 *
	 * @param string       $template_name Имя файла без .php
	 * @param array|object $data          Данные для шаблона
	 */
	protected function render( string $template_name, array|object $data = [] ): void {
		$file = $this->path( "templates/{$template_name}.php" );
		
		if ( ! file_exists( $file ) ) {
			return;
		}
		
		/**
		 * Если передан объект (DTO), мы упаковываем его в массив под ключом 'data'.
		 * В шаблоне он будет доступен как $data.
		 * Если передан массив, используем extract() как раньше для совместимости.
		 */
		if ( is_object( $data ) ) {
			$args = [ 'data' => $data ];
		} else {
			$args = $data;
		}
		
		if ( ! empty( $args ) ) {
			extract( $args );
		}
		
		require $file;
	}
}