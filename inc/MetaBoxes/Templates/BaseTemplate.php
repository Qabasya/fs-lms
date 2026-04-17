<?php

namespace Inc\MetaBoxes\Templates;

/**
 * Class BaseTemplate
 *
 * Абстрактный базовый класс для всех шаблонов метабоксов.
 *
 * Отвечает за хранение списка полей шаблона и их массовый рендеринг.
 * Не знает, какие конкретно поля в нём лежат, просто умеет по ним "пробегаться"
 * и делегировать рендеринг каждому полю.
 *
 * Паттерн: Template Method — определяет общий алгоритм рендеринга,
 *          а конкретные реализации задают get_id(), get_name() и состав полей.
 *
 * @package Inc\MetaBoxes\Templates
 */
abstract class BaseTemplate {
	/**
	 * Список полей в шаблоне.
	 *
	 * Структура:
	 * [
	 *     'field_id' => [
	 *         'label'  => 'Название поля',
	 *         'object' => FieldInstance  // Экземпляр класса поля (InputField, TextareaField и т.д.)
	 *     ]
	 * ]
	 *
	 * @var array<string, array{label: string, object: object}>
	 */
	public array $fields = [];
	
	/**
	 * Возвращает уникальное имя (ID) шаблона.
	 *
	 * Используется для идентификации шаблона в системе.
	 *
	 * @return string Уникальный идентификатор шаблона (например, 'standard_task')
	 */
	abstract public function get_id(): string;
	
	/**
	 * Возвращает человекочитаемое название шаблона.
	 *
	 * Отображается в интерфейсе при выборе типа задания.
	 *
	 * @return string Название шаблона (например, 'Стандартное задание')
	 */
	abstract public function get_name(): string;
	
	/**
	 * Отрисовывает все поля шаблона.
	 *
	 * Загружает сохранённые значения мета-полей поста,
	 * проходит по всем зарегистрированным полям и вызывает
	 * метод render() каждого поля.
	 *
	 * @param \WP_Post $post Объект текущего поста
	 *
	 * @return void
	 */
	public function render( \WP_Post $post ): void {
		// 1. Достаём единый массив данных из мета-поля
		$values = get_post_meta( $post->ID, 'fs_lms_meta', true );
		
		// Если данных ещё нет, инициализируем пустым массивом
		if ( ! is_array( $values ) ) {
			$values = [];
		}
		
		// 2. Открываем обёртку для шаблона
		echo '<div class="fs-lms-template-wrapper" id="template-' . esc_attr( $this->get_id() ) . '">';
		
		// 3. Проходим по всем зарегистрированным полям
		foreach ( $this->fields as $field_id => $config ) {
			$label = $config['label'];                 // Текст метки поля
			$field = $config['object'];                // Экземпляр класса поля
			$value = $values[ $field_id ] ?? '';       // Текущее значение поля
			
			// 4. Делегируем рендеринг самому полю
			$field->render( $post, $field_id, $label, $value );
		}
		
		echo '</div>';
	}
	
	/**
	 * Возвращает конфигурацию полей для процесса сохранения.
	 *
	 * Используется в обработчике сохранения для определения,
	 * какие поля нужно санитизировать и сохранить.
	 *
	 * @return array<string, array{label: string, object: object}> Список полей
	 */
	public function get_fields(): array {
		return $this->fields;
	}
}