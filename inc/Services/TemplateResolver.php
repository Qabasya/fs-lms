<?php

declare( strict_types=1 );

namespace Inc\Services;

use Inc\Enums\TaskTemplate;
use Inc\Repositories\MetaBoxRepository;

/**
 * Class TemplateResolver
 *
 * Отвечает за определение того, какой шаблон должен использоваться для конкретного задания.
 *
 * @package Inc\Services
 *
 * ### Основные обязанности:
 *
 * 1. **Определение шаблона поста** — выбор шаблона на основе приоритетов: привязка в БД → мета-поле поста → стандартный.
 * 2. **Извлечение ключа предмета** — парсинг типа поста для получения ключа предмета.
 * 3. **Получение Enum шаблона** — возврат объекта TaskTemplate вместо строки.
 *
 * ### Архитектурная роль:
 *
 * Реализует паттерн Strategy (стратегия) для выбора шаблона.
 * Используется в MetaBoxController для рендеринга правильных полей при редактировании задания.
 *
 * ### Приоритеты выбора шаблона:
 *
 * 1. **Глобальная привязка в БД** — настройка на уровне типа задания (термина таксономии).
 * 2. **Мета-поле поста** — индивидуальная настройка для конкретного задания (обратная совместимость).
 * 3. **Стандартный шаблон** — значение по умолчанию из Enum TaskTemplate::STANDARD.
 */
class TemplateResolver {
	
	/**
	 * Конструктор.
	 *
	 * @param MetaBoxRepository $metaboxes Репозиторий привязок шаблонов к номерам заданий
	 */
	public function __construct(
		private readonly MetaBoxRepository $metaboxes
	) {}
	
	/**
	 * Определяет ID шаблона для конкретного поста.
	 *
	 * @param \WP_Post $post Объект поста (задания)
	 *
	 * @return string ID выбранного шаблона
	 */
	public function resolveId( \WP_Post $post ): string {
		// 1. Извлечение ключа предмета из типа поста (например, "math_tasks" -> "math")
		$subject_key = str_replace( '_tasks', '', $post->post_type );
		
		// 2. ПРИОРИТЕТ 1: Глобальные настройки в БД (MetaBoxRepository)
		$taxonomy = "{$subject_key}_task_number";
		// wp_get_post_terms() — возвращает массив терминов, привязанных к посту
		$terms    = wp_get_post_terms( $post->ID, $taxonomy );
		
		// Проверка, что термины получены без ошибок и не пустые
		if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
			$term_slug  = (string) $terms[0]->slug;
			// getAssignment() — получает привязку шаблона для этого типа задания
			$assignment = $this->metaboxes->getAssignment( $subject_key, $term_slug );
			
			if ( $assignment && ! empty( $assignment->template_id ) ) {
				return $assignment->template_id;
			}
		}
		
		// 3. ПРИОРИТЕТ 2: Мета-поле конкретного поста (обратная совместимость со старыми данными)
		// get_post_meta() — получает мета-поле поста (третий параметр true — одно значение)
		$saved_meta = get_post_meta( $post->ID, '_fs_lms_template_type', true );
		if ( ! empty( $saved_meta ) ) {
			return (string) $saved_meta;
		}
		
		// 4. ПРИОРИТЕТ 3: Стандартный шаблон (из Enum)
		return TaskTemplate::STANDARD->value;
	}
	
	/**
	 * Возвращает объект Enum для текущего поста.
	 *
	 * @param \WP_Post $post Объект поста (задания)
	 *
	 * @return TaskTemplate
	 */
	public function resolveEnum( \WP_Post $post ): TaskTemplate {
		// fromDatabase() — преобразует строку в enum TaskTemplate
		return TaskTemplate::fromDatabase( $this->resolveId( $post ) );
	}
}