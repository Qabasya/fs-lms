<?php

namespace Inc\MetaBoxes\Templates;

use Inc\Enums\Subject\TemplateCategory;
use Inc\Enums\Wp\PostMetaName;
use Inc\MetaBoxes\Fields\HintField;

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
	public array $fields = array();

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
	 * Категория шаблона — крупное деление по типу взаимодействия (question|code).
	 * По умолчанию «Вопрос» (вписать ответ); code-шаблоны переопределяют.
	 * Type-first меню добавления шага фильтрует шаблоны/кандидатов по категории.
	 */
	public function get_category(): TemplateCategory {
		return TemplateCategory::Question;
	}

	/**
	 * Разворачивает составной шаблон в список отдельно оцениваемых элементов для экзамена (T7.12).
	 * Дефолт — пустой массив: шаблон не разворачивается (один элемент = сам шаблон).
	 * ThreeInOneTemplate переопределяет и возвращает три суб-задания (19 / 20 / 21).
	 *
	 * @return array{key: string, condition_field: string, answer_field: string}[]
	 */
	public function expandsForExam(): array {
		return [];
	}

	/**
	 * Отрисовывает все поля шаблона, включая подсказку.
	 *
	 * @param \WP_Post $post Объект текущего поста
	 *
	 * @return void
	 */
	public function render( \WP_Post $post ): void {
		$values = get_post_meta( $post->ID, PostMetaName::Meta->value, true );
		if ( ! is_array( $values ) ) {
			$values = array();
		}

		echo '<div class="fs-lms-template-wrapper" id="template-' . esc_attr( $this->get_id() ) . '">';

		foreach ( $this->get_fields() as $field_id => $config ) {
			$field = $config['object'];
			$value = $values[ $field_id ] ?? '';
			$field->render( $post, $field_id, $config['label'], $value );
		}

		echo '</div>';
	}

	/**
	 * Возвращает конфигурацию полей для процесса сохранения.
	 * Всегда включает поле подсказки (task_hint) в конце.
	 *
	 * @return array<string, array{label: string, object: object}> Список полей
	 */
	public function get_fields(): array {
		return array_merge( $this->fields, $this->hintFieldConfig() );
	}

	/**
	 * Возвращает схему полей шаблона для JS-редактора задач.
	 *
	 * @return array{id: string, label: string, category: string, fields: list<array{key: string, label: string, type: string, config: array}>}
	 */
	public function getEditorSchema(): array {
		$fields = [];
		foreach ( $this->get_fields() as $key => $config ) {
			$fields[] = [
				'key'    => $key,
				'label'  => $config['label'],
				'type'   => $config['object']->editorType(),
				'config' => $config['object']->editorConfig(),
			];
		}

		return [
			'id'       => $this->get_id(),
			'label'    => $this->get_name(),
			'category' => $this->get_category()->value,
			'fields'   => $fields,
		];
	}

	/**
	 * Конфигурация поля подсказки — общего для всех типов заданий.
	 *
	 * @return array<string, array{label: string, object: HintField}>
	 */
	private function hintFieldConfig(): array {
		return array(
			'task_hint' => array(
				'label'  => 'Подсказка',
				'object' => new HintField(),
			),
		);
	}
}
