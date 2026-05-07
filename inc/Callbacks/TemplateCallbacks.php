<?php

declare( strict_types=1 );

namespace Inc\Callbacks;

use Inc\Controllers\Builders\TaskDataBuilder;
use Inc\Core\BaseController;
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
					return $custom_template;
				}
			}
		}

		return $template;
	}

	/**
	 * Возвращает данные задания для frontend-шаблона.
	 *
	 * Вызывается напрямую из single-task.php. Делегирует сборку данных в TaskDataBuilder.
	 *
	 * @param int $post_id ID записи задания.
	 *
	 * @return array Массив данных страницы задания.
	 */
	public function getTaskData( int $post_id ): array {
		return $this->task_data_builder->getTaskData( $post_id );
	}
}