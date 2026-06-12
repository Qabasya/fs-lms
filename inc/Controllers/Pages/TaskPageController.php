<?php

declare( strict_types=1 );

namespace Inc\Controllers\Pages;

use Inc\Callbacks\Task\TemplateCallbacks;
use Inc\Contracts\ServiceInterface;
use Inc\Core\BaseController;

/**
 * Class TaskPageController
 *
 * Контроллер frontend-страницы задания.
 *
 * @package Inc\Controllers
 * @implements ServiceInterface
 *
 * ### Основные обязанности:
 *
 * 1. **Подмена шаблона** — перехват template_include для загрузки кастомного шаблона задания.
 * 2. **Настройка архивов таксономий** — модификация запросов для корректного отображения
 *    архивов заданий по таксономиям.
 *
 * ### Архитектурная роль:
 *
 * Делегирует бизнес-логику TemplateCallbacks.
 * Регистрирует фильтры для отображения страницы одного задания на фронтенде
 * и архивов заданий по таксономиям.
 */
class TaskPageController extends BaseController implements ServiceInterface {

	/**
	 * Конструктор контроллера.
	 *
	 * @param TemplateCallbacks $callbacks Коллбеки frontend-шаблона задания
	 */
	public function __construct(
		private readonly TemplateCallbacks $callbacks
	) {
		parent::__construct();
	}

	/**
	 * Регистрирует фильтр подключения кастомного шаблона задания.
	 *
	 * @return void
	 */
	public function register(): void {
		// 'template_include' — фильтр для подмены шаблона темы
		add_filter( 'template_include', array( $this->callbacks, 'loadTaskFrontendTemplate' ) );

		// 'pre_get_posts' — фильтр для изменения параметров запроса перед выполнением
		add_action( 'pre_get_posts', array( $this->callbacks, 'filterTaskTaxonomyArchive' ) );

		// 'request' — фильтр для изменения параметров запроса к базе данных
		add_filter( 'request', array( $this->callbacks, 'filterTaskTaxonomyRequest' ) );
	}
}