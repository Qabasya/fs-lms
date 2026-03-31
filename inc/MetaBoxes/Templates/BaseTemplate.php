<?php

namespace Inc\MetaBoxes\Templates;

/**
 * Базовая логика для всех шаблонов метабоксов.
 * Этот класс будет отвечать за хранение списка полей и их массовый рендеринг.
 * Он не знает, какие поля в нем лежат, он просто умеет по ним "пробегаться".
 */
abstract class BaseTemplate {
	/**
	 * Список полей в шаблоне.
	 * Формат: ['field_id' => ['label' => 'Название', 'object' => FieldInstance]]
	 * * @var array
	 */
	protected array $fields = [];

	/**
	 * Возвращает уникальное имя (ID) шаблона.
	 */
	abstract public function get_id(): string;

	/**
	 * Возвращает человекочитаемое название шаблона.
	 */
	abstract public function get_name(): string;

	/**
	 * Отрисовывает все поля шаблона.
	 * * @param \WP_Post $post Объект текущего поста
	 * @param array $values Текущие значения полей из базы
	 */
	public function render( $post, array $values ): void {
		foreach ( $this->fields as $id => $config ) {
			$value = $values[ $id ] ?? '';
			$config['object']->render( $post, $id, $config['label'], $value );
		}
	}

	/**
	 * Возвращает конфигурацию полей для процесса сохранения.
	 */
	public function get_fields(): array {
		return $this->fields;
	}
}