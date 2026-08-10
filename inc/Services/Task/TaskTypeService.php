<?php

declare(strict_types=1);

namespace Inc\Services\Task;

use Inc\DTO\Task\TaskTypeDTO;
use Inc\Enums\Subject\TaskTemplate;
use Inc\Managers\Wp\PostManager;
use Inc\Repositories\OptionsRepositories\MetaBoxRepository;
use Inc\Services\Subject\PostTypeResolver;

/**
 * Class TaskTypeService
 *
 * Типы заданий предмета — термины таксономии номеров, обогащённые привязанным
 * шаблоном метабокса (MetaBoxRepository) и числом созданных заданий (PostManager).
 *
 * @package Inc\Services
 */
class TaskTypeService {

	public function __construct(
		private MetaBoxRepository $metaboxes,
		private PostManager $posts,
	) {}

	/**
	 * Возвращает типы заданий предмета в виде DTO с привязанными шаблонами.
	 *
	 * @param string $subject_key Ключ предмета (например, 'math')
	 *
	 * @return TaskTypeDTO[] Массив DTO типов заданий
	 */
	public function getTaskTypes( string $subject_key ): array {
		$taxonomy  = "{$subject_key}_task_number";
		$post_type = PostTypeResolver::tasks( $subject_key );

		$terms = get_terms( array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
			// Слаг — это номер задания, сортировка по нему даёт порядок 1, 2, 10.
			'orderby'    => 'slug',
			'order'      => 'ASC',
		) );

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return array();
		}

		return array_map(
			function ( $term ) use ( $subject_key, $taxonomy, $post_type ): TaskTypeDTO {
				$assignment = $this->metaboxes->getAssignment( $subject_key, $term->slug );
				$db_id      = $assignment ? $assignment->template_id : 'standard_task';

				return new TaskTypeDTO(
					$term->term_id,
					$term->slug,
					$term->taxonomy,
					$term->description,
					TaskTemplate::fromDatabase( $db_id ),
					$db_id,
					$this->posts->countByTerm( $post_type, $taxonomy, (int) $term->term_id ),
				);
			},
			$terms
		);
	}
}
