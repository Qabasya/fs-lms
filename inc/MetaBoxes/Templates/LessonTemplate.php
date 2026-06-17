<?php

declare( strict_types=1 );

namespace Inc\MetaBoxes\Templates;

use Inc\MetaBoxes\Fields\ArticleRefField;
use Inc\MetaBoxes\Fields\WorkRefField;
use Inc\Services\Course\LessonAuthoringService;
use Inc\Services\PostTypeResolver;

/**
 * Class LessonTemplate
 *
 * Шаблон метабокса урока: источник теории (статья) + упорядоченные ссылки на работы.
 * Урок ссылается на работы, не на задачи.
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
			'work_ids' => array(
				'label'  => 'Работы',
				'object' => new WorkRefField(),
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
	 * Рендер с подгрузкой статей предмета в ArticleRefField.
	 *
	 * @param \WP_Post $post
	 */
	public function render( \WP_Post $post ): void {
		$subject_key = PostTypeResolver::subjectFromLessonPostType( $post->post_type );

		if ( '' !== $subject_key ) {
			/** @var ArticleRefField $article_field */
			$article_field = $this->fields['theory_article_id']['object'];
			$article_field->setOptions( $this->authoringService->getArticles( $subject_key ) );
		}

		parent::render( $post );
	}
}
