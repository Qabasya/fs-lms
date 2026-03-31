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
	public function render( \WP_Post $post): void {
		// 1. Достаем наш единый массив данных из мета-поля
		$values = get_post_meta( $post->ID, 'fs_lms_meta', true );

		// Если данных еще нет, делаем пустой массив
		if ( ! is_array( $values ) ) {
			$values = [];
		}

		echo '<div class="fs-lms-template-wrapper" id="template-' . esc_attr( $this->get_id() ) . '">';

		// 2. Проходим по всем зарегистрированным полям
		foreach ( $this->fields as $field_id => $config ) {
			$label  = $config['label'];
			$field  = $config['object'];
			$value  = $values[ $field_id ] ?? '';

			// 3. Вызываем рендер самого поля (InputField, TextareaField и т.д.)
			$field->render( $post, $field_id, $label, $value );
		}

		echo '</div>';
	}

	/**
	 * Возвращает конфигурацию полей для процесса сохранения.
	 */
	public function get_fields(): array {
		return $this->fields;
	}
}