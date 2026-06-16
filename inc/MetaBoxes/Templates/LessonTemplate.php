<?php

declare( strict_types=1 );

namespace Inc\MetaBoxes\Templates;

use Inc\MetaBoxes\Fields\ArticleRefField;
use Inc\MetaBoxes\Fields\TaskBucketField;
use Inc\MetaBoxes\Fields\TaskTypeField;
use Inc\Services\Course\LessonAuthoringService;
use Inc\Services\PostTypeResolver;

/**
 * Class LessonTemplate
 *
 * Единственный шаблон метабокса урока.
 * Секции: источник теории, тип заданий, практика, СР, ДЗ.
 *
 * @package Inc\MetaBoxes\Templates
 */
class LessonTemplate extends BaseTemplate {

	public function __construct(
		private readonly LessonAuthoringService $authoringService,
	) {
		$this->fields = array(
			'theory_article_id' => array(
				'label'  => 'Теория: статья предмета (опционально)',
				'object' => new ArticleRefField(),
			),
			'task_type' => array(
				'label'  => 'Тип заданий в бакетах (опционально)',
				'object' => new TaskTypeField(),
			),
			'practice' => array(
				'label'  => 'Практика',
				'object' => new TaskBucketField(),
			),
			'independent' => array(
				'label'  => 'Самостоятельная работа',
				'object' => new TaskBucketField(),
			),
			'homework' => array(
				'label'  => 'Домашнее задание',
				'object' => new TaskBucketField(),
			),
		);
	}

	public function get_id(): string {
		return 'lesson';
	}

	public function get_name(): string {
		return 'Урок';
	}

	/**
	 * Рендер с подгрузкой динамических опций из сервиса.
	 *
	 * @param \WP_Post $post
	 */
	public function render( \WP_Post $post ): void {
		$subject_key = PostTypeResolver::subjectFromLessonPostType( $post->post_type );

		if ( $subject_key !== '' ) {
			/** @var ArticleRefField $article_field */
			$article_field = $this->fields['theory_article_id']['object'];
			$article_field->setOptions( $this->authoringService->getArticles( $subject_key ) );

			/** @var TaskTypeField $type_field */
			$type_field = $this->fields['task_type']['object'];
			$type_field->setOptions( $this->authoringService->getTaskTypes( $subject_key ) );
		}

		parent::render( $post );
	}
}
