<?php

namespace Inc\Contracts;

/**
 * Интерфейс для всех компонентов полей метабоксов.
 */

interface FieldInterface {

	/**
	 * Отрисовка HTML-поля в админке.
	 * * @param \WP_Post $post Объект текущего поста (задания)
	 * @param string $id Уникальный ID поля
	 * @param string $label Заголовок поля
	 * @param mixed $value Текущее значение из базы
	 */
	public function render( $post, string $id, string $label, $value ): void;

	/**
	 * Валидация и подготовка данных перед сохранением.
	 * * @param mixed $value Значение из $_POST
	 * @return mixed Очищенное значение
	 */
	public function sanitize( $value );
}