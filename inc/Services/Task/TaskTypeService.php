<?php

declare(strict_types=1);

namespace Inc\Services\Task;

use Inc\DTO\Task\TaskTypeDTO;
use Inc\Enums\Subject\TaskTemplate;
use Inc\Managers\PostManager;
use Inc\Repositories\OptionsRepositories\MetaBoxRepository;
use Inc\Services\PostTypeResolver;

/**
 * Class TaskTypeService
 *
 * Сервис для работы с типами заданий (терминами таксономии номеров заданий).
 *
 * @package Inc\Services
 *
 * ### Основные обязанности:
 *
 * 1. **Получение типов заданий** — извлечение терминов таксономии номеров заданий
 *    для указанного предмета с преобразованием в DTO.
 * 2. **Обогащение данными** — добавление к каждому типу задания информации о привязанном
 *    шаблоне метабокса и количестве созданных заданий.
 *
 * ### Архитектурная роль:
 *
 * Делегирует получение привязок шаблонов MetaBoxRepository,
 * а подсчёт заданий — PostManager. Служит прослойкой между репозиториями
 * и контроллерами, преобразуя данные в типобезопасные DTO.
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
		// Формирование имени таксономии номеров заданий (например, 'math_task_number')
		$taxonomy  = "{$subject_key}_task_number";

		// Получение типа поста заданий через статический метод PostTypeResolver
		$post_type = PostTypeResolver::tasks( $subject_key );

		// get_terms() — WordPress-функция для получения терминов таксономии
		$terms = get_terms( array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,   // Включать термины без постов
			'orderby'    => 'slug',  // Сортировка по слагу (1, 2, 10...)
			'order'      => 'ASC',   // По возрастанию
		) );

		// is_wp_error() — проверка на ошибку WordPress
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return array();
		}

		// array_map() — преобразование каждого термина в TaskTypeDTO
		return array_map(
			function ( $term ) use ( $subject_key, $taxonomy, $post_type ): TaskTypeDTO {
				// Получение привязки шаблона для данного типа задания (термина)
				$assignment = $this->metaboxes->getAssignment( $subject_key, $term->slug );

				// ID шаблона из БД или стандартный, если привязки нет
				$db_id = $assignment ? $assignment->template_id : 'standard_task';

				// Преобразование строкового ID в enum TaskTemplate
				$template_enum = TaskTemplate::fromDatabase( $db_id );

				// Подсчёт количества заданий, созданных для этого типа
				$post_count = $this->posts->countByTerm( $post_type, $taxonomy, (int) $term->term_id );

				// Создание DTO с данными термина
				return new TaskTypeDTO(
					$term->term_id,
					$term->slug,
					$term->taxonomy,
					$term->description,
					$template_enum,
					$db_id,
					$post_count,
				);
			},
			$terms
		);
	}
}