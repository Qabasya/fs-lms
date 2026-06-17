<?php

declare( strict_types=1 );

namespace Inc\MetaBoxes\Templates;

use Inc\MetaBoxes\Fields\LessonRefField;

/**
 * Class CourseTemplate
 *
 * Форма метабокса курса: упорядоченные ссылки на уроки (шаблон курса).
 * Описание курса — нативный редактор (post_content).
 *
 * @package Inc\MetaBoxes\Templates
 */
class CourseTemplate extends BaseTemplate {

	public function __construct() {
		$this->fields = array(
			'lesson_ids' => array(
				'label'  => 'Уроки курса',
				'object' => new LessonRefField(),
			),
		);
	}

	public function get_id(): string {
		return 'course';
	}

	public function get_name(): string {
		return 'Курс';
	}
}
