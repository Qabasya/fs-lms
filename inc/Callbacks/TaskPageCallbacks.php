<?php

declare( strict_types=1 );

namespace Inc\Callbacks;

use Inc\Core\BaseController;
use Inc\Managers\PostManager;
use Inc\Managers\TermManager;
use Inc\Repositories\TaxonomyRepository;

class TaskPageCallbacks extends BaseController {

	public function __construct(
		private readonly TaxonomyRepository $taxonomy_repository,
		private readonly PostManager $post_manager,
		private readonly TermManager $term_manager,
	) {
		parent::__construct();
	}

	/**
	 * Подменяет путь к шаблону для одиночной страницы задания.
	 * Подключается к фильтру 'template_include'.
	 *
	 * @param string $template Путь к текущему шаблону темы
	 *
	 * @return string Путь к шаблону плагина или оригинальный путь
	 */
	public function loadTaskFrontendTemplate( string $template ): string {
		if ( is_singular() ) {
			$post_type = get_post_type();

			if ( $post_type && str_ends_with( $post_type, '_tasks' ) ) {
				$custom_template = FS_LMS_PATH . 'templates/frontend/single-task.php';

				if ( file_exists( $custom_template ) ) {
					return $custom_template;
				}
			}
		}

		return $template;
	}

	/**
	 * Получает данные задания для отображения в шаблоне.
	 * Вызывается прямо в single-task.php.
	 *
	 * @param int $post_id ID поста задания
	 *
	 * @return array Массив с данными задания (condition, answer, code, taxonomies)
	 *               TODO: Enum для полей в БД
	 */
	public function getTaskData( int $post_id ): array {
		$post        = $this->post_manager->get( $post_id );
		$subject_key = substr( $post->post_type, 0, -strlen( '_tasks' ) );
		$meta        = $this->post_manager->getMeta( $post_id, 'fs_lms_meta' );
		$meta        = is_array( $meta ) ? $meta : array();

		return array(
			'condition'  => $this->getCombinedCondition( $meta ),
			'answer'     => $meta['task_answer'] ?? '',
			'code'       => $meta['task_code']   ?? '',
			'taxonomies' => $this->getRequiredTaxonomies( $post_id, $subject_key ),
		);
	}
	
	// ============================ ПРИВАТНЫЕ МЕТОДЫ ============================ //
	
	/**
	 * Возвращает обязательные таксономии с их терминами для задания.
	 *
	 * @param int    $post_id     ID поста задания
	 * @param string $subject_key Ключ предмета
	 *
	 * @return array
	 */
	private function getRequiredTaxonomies( int $post_id, string $subject_key ): array {
		$result = array();

		foreach ( $this->taxonomy_repository->getBySubject( $subject_key ) as $dto ) {
			if ( $dto->is_required ) {
				$taxonomy             = $dto->slug;
				$result[ $dto->slug ] = $this->term_manager->getPostTerms( $post_id, $taxonomy );
			}
		}

		return $result;
	}

	/**
	 * Собирает все поля с суффиксом '_condition' из fs_lms_meta в один блок контента.
	 *
	 * @param array $meta Массив мета-полей из fs_lms_meta
	 *
	 * @return string
	 */
	private function getCombinedCondition( array $meta ): string {
		if ( empty( $meta ) ) {
			return '';
		}

		ksort( $meta );
		$condition_parts = array();

		foreach ( $meta as $key => $value ) {
			if ( str_contains( $key, '_condition' ) ) {
				$condition_parts[] = apply_filters( 'the_content', $value );
			}
		}

		return implode( '', $condition_parts );
	}
}
