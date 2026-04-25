<?php

declare( strict_types=1 );

namespace Inc\Services;

use Inc\Enums\TaskTemplate;
use Inc\Repositories\MetaBoxRepository;

/**
 * Class TemplateResolver
 * * Отвечает за определение того, какой шаблон должен использоваться для конкретного задания.
 * Реализует логику приоритетов (БД -> Мета поста -> Дефолт).
 */
class TemplateResolver {
	/**
	 * Конструктор.
	 * * @param MetaBoxRepository $metaboxes Репозиторий привязок шаблонов к номерам заданий.
	 */
	public function __construct(
		private readonly MetaBoxRepository $metaboxes
	) {}

	/**
	 * Определяет ID шаблона для конкретного поста.
	 * * @param \WP_Post $post Объект поста (задания).
	 *
	 * @return string ID выбранного шаблона.
	 */
	public function resolveId( \WP_Post $post ): string {
		// 1. Извлекаем ключ предмета (например, "math_tasks" -> "math")
		$subject_key = str_replace( '_tasks', '', $post->post_type );

		// 2. ПРИОРИТЕТ 1: Глобальные настройки в БД (MetaBoxRepository)
		// Ищем привязку по номеру задания (таксономии)
		$taxonomy = "{$subject_key}_task_number";
		$terms    = wp_get_post_terms( $post->ID, $taxonomy );

		if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
			$term_slug  = (string) $terms[0]->slug;
			$assignment = $this->metaboxes->getAssignment( $subject_key, $term_slug );

			if ( $assignment && ! empty( $assignment->template_id ) ) {
				return $assignment->template_id;
			}
		}

		// 3. ПРИОРИТЕТ 2: Мета-поле конкретного поста (обратная совместимость)
		$saved_meta = get_post_meta( $post->ID, '_fs_lms_template_type', true );
		if ( ! empty( $saved_meta ) ) {
			return (string) $saved_meta;
		}

		// 4. ПРИОРИТЕТ 3: Стандартный шаблон (из Enum)
		return TaskTemplate::STANDARD->value;
	}

	/**
	 * Возвращает объект Enum для текущего поста.
	 * * @param \WP_Post $post
	 *
	 * @return TaskTemplate
	 */
	public function resolveEnum( \WP_Post $post ): TaskTemplate {
		return TaskTemplate::fromDatabase( $this->resolveId( $post ) );
	}
}
