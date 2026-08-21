<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Task;

use Inc\Controllers\Builders\TaskDataBuilder;
use Inc\Core\BaseController;
use Inc\DTO\Task\TaskPageDTO;
use Inc\Enums\Wp\PostMetaName;
use Inc\Managers\Wp\PostManager;
use Inc\Services\Subject\PostTypeResolver;

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
		private readonly PostManager     $postManager,
	) {
		parent::__construct();
	}

	/**
	 * Подменяет путь к шаблону для одиночной страницы задания.
	 *
	 * Подключается к фильтру 'template_include'. Возвращает путь к шаблону
	 * плагина, если текущая запись является заданием и файл шаблона существует.
	 * Если данных задания нет (запись удалена или это не задание) — отдаёт 404
	 * темы, а не пустую карточку.
	 *
	 * @param string $template Путь к текущему шаблону темы.
	 *
	 * @return string Путь к шаблону плагина или оригинальный путь.
	 */
	public function loadTaskFrontendTemplate( string $template ): string {
		if ( is_singular() ) {
			$post_type = get_post_type();

			if ( $post_type && PostTypeResolver::isTaskPostType( $post_type ) ) {
				$post_id = get_queried_object_id();

				// Дочернее задание связки (19/20/21) собственной публичной страницы не имеет —
				// показывается только parent (см. .docs/Tasks.md, §3.2).
				$parent_id = (int) $this->postManager->getMeta( $post_id, PostMetaName::TaskBundleParentId->value );
				if ( $parent_id > 0 ) {
					$parent_link = get_permalink( $parent_id );
					if ( $parent_link ) {
						wp_safe_redirect( $parent_link, 301 );
						exit;
					}
					return $this->notFound( $template );
				}

				$custom_template = FS_LMS_PATH . 'templates/frontend/single-task.php';

				if ( file_exists( $custom_template ) ) {
					$task_data = $this->getTaskData( $post_id );

					if ( ! $task_data->post ) {
						return $this->notFound( $template );
					}

					set_query_var( 'fs_task_data', $task_data );
					return $custom_template;
				}
			}
		}

		return $template;
	}

	/**
	 * Переводит текущий запрос в 404 и возвращает шаблон «не найдено».
	 *
	 * @param string $template Путь к текущему шаблону темы (фолбэк).
	 *
	 * @return string
	 */
	private function notFound( string $template ): string {
		global $wp_query;

		if ( $wp_query instanceof \WP_Query ) {
			$wp_query->set_404();
		}

		status_header( 404 );
		nocache_headers();

		$not_found = get_404_template();

		return '' !== $not_found ? $not_found : $template;
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
			if ( is_string( $value ) && '' !== $value && str_ends_with( $key, PostTypeResolver::TASK_NUMBER_SUFFIX ) ) {
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
			if ( is_string( $value ) && '' !== $value && str_ends_with( $key, PostTypeResolver::TASK_NUMBER_SUFFIX ) ) {
				$subject_key             = substr( $key, 0, -strlen( PostTypeResolver::TASK_NUMBER_SUFFIX ) );
				$query_vars['post_type'] = PostTypeResolver::tasks( $subject_key );
				break;
			}
		}

		return $query_vars;
	}
}
