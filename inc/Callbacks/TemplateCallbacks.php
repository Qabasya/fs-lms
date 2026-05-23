<?php

declare( strict_types=1 );

namespace Inc\Callbacks;

use Inc\Controllers\Builders\TaskDataBuilder;
use Inc\Core\BaseController;
use Inc\DTO\TaskPageDTO;
use Inc\Services\PostTypeResolver;

/**
 * Class TemplateCallbacks
 *
 * Коллбеки frontend-шаблона задания.
 *
 * Обрабатывает фильтр template_include для подключения кастомного шаблона
 * и предоставляет данные задания для рендеринга в шаблоне single-task.php.
 *
 * @package Inc\Callbacks
 */
class TemplateCallbacks extends BaseController {

	/**
	 * @param TaskDataBuilder $task_data_builder Строитель данных страницы задания.
	 */
	public function __construct(
		private readonly TaskDataBuilder $task_data_builder,
	) {
		parent::__construct();
	}

	/**
	 * Подменяет путь к шаблону для одиночной страницы задания.
	 *
	 * Подключается к фильтру 'template_include'. Возвращает путь к шаблону
	 * плагина, если текущая запись является заданием и файл шаблона существует.
	 *
	 * @param string $template Путь к текущему шаблону темы.
	 *
	 * @return string Путь к шаблону плагина или оригинальный путь.
	 */
	public function loadTaskFrontendTemplate( string $template ): string {
		if ( is_singular() ) {
			$post_type = get_post_type();

			if ( $post_type && PostTypeResolver::isTaskPostType( $post_type ) ) {
				$custom_template = FS_LMS_PATH . 'templates/frontend/single-task.php';

				if ( file_exists( $custom_template ) ) {
					set_query_var( 'fs_task_data', $this->getTaskData( get_queried_object_id() ) );
					return $custom_template;
				}
			}
		}

		return $template;
	}

	/**
	 * Возвращает данные задания для frontend-шаблона.
	 *
	 * Делегирует сборку данных в TaskDataBuilder.
	 *
	 * @param int $post_id ID записи задания.
	 *
	 * @return TaskPageDTO
	 */
	public function getTaskData( int $post_id ): TaskPageDTO {
		return $this->task_data_builder->getTaskData( $post_id );
	}

	/**
	 * Ограничивает архив таксономии типов заданий только CPT заданий.
	 *
	 * Таксономия {key}_task_number зарегистрирована для обоих CPT (задания + статьи),
	 * поэтому без фильтра архив показывает их вперемешку.
	 *
	 * @param \WP_Query $query Текущий запрос WordPress.
	 *
	 * @return void
	 */
	public function filterTaskTaxonomyArchive( \WP_Query $query ): void {
		if ( is_admin() || ! $query->is_main_query() ) {
			return;
		}

		foreach ( $query->query_vars as $key => $value ) {
			if ( '' !== (string) $value && str_ends_with( $key, PostTypeResolver::TASK_NUMBER_SUFFIX ) ) {
				$subject_key = substr( $key, 0, -strlen( PostTypeResolver::TASK_NUMBER_SUFFIX ) );
				$query->set( 'post_type', PostTypeResolver::tasks( $subject_key ) );
				return;
			}
		}
	}

	/**
	 * Ограничивает request-переменные taxonomy-архива только CPT заданий.
	 *
	 * Резервный фильтр — работает до построения WP_Query и не зависит
	 * от is_main_query() / is_tax(). Нужен, если тема не использует
	 * стандартный главный цикл.
	 *
	 * @param array $query_vars Разобранные переменные запроса.
	 *
	 * @return array
	 */
	public function filterTaskTaxonomyRequest( array $query_vars ): array {
		foreach ( $query_vars as $key => $value ) {
			if ( '' !== (string) $value && str_ends_with( $key, PostTypeResolver::TASK_NUMBER_SUFFIX ) ) {
				$subject_key             = substr( $key, 0, -strlen( PostTypeResolver::TASK_NUMBER_SUFFIX ) );
				$query_vars['post_type'] = PostTypeResolver::tasks( $subject_key );
				break;
			}
		}

		return $query_vars;
	}
}