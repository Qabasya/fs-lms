<?php

declare( strict_types=1 );

namespace Inc\Controllers;

use Inc\Core\BaseController;
use Inc\Contracts\ServiceInterface;
use Inc\Managers\UserManager;
use Inc\Repositories\UserRepository;
use Inc\Enums\AjaxHook;

/**
 * Class UserController
 *
 * Контроллер управления пользователями.
 *
 * @package Inc\Controllers
 * @implements ServiceInterface
 *
 * ### Основные обязанности:
 *
 * 1. **Ограничение доступа** — фильтрация доступа к админ-панели и медиафайлам.
 * 2. **Регистрация хуков** — подключение всех WordPress-хуков для управления пользователями.
 *
 * ### Архитектурная роль:
 *
 * Делегирует управление пользователями UserManager, а получение данных — UserRepository.
 * Является точкой входа для регистрации всех хуков, связанных с пользовательской системой.
 */
class UserController extends BaseController implements ServiceInterface {

	/**
	 * Конструктор контроллера.
	 *
	 * @param UserManager    $user_manager Менеджер для работы с WordPress (роли, доступ, медиа)
	 * @param UserRepository $user_repo    Репозиторий для работы с данными пользователей
	 */
	public function __construct(
		private readonly UserManager $user_manager,
		private readonly UserRepository $user_repo
	) {
		parent::__construct();
	}

	/**
	 * Регистрирует все необходимые хуки в системе.
	 *
	 * @return void
	 */
	public function register(): void {
		// 1. Ограничение доступа к админ-панели
		// 'admin_init' — хук, срабатывающий при инициализации админ-панели
		// Подходит для проверки прав и редиректа
		add_action( 'admin_init', array( $this->user_manager, 'restrictAdminAccess' ) );

		// 2. Фильтрация медиафайлов в AJAX-запросах (для фронтенда)
		// 'ajax_query_attachments_args' — хук для изменения параметров запроса вложений
		add_filter( 'ajax_query_attachments_args', array( $this->user_manager, 'getMediaFilterArgs' ) );

		// 3. Фильтрация медиафайлов в обычных запросах (админка)
		// 'request' — хук для изменения параметров запроса к WordPress
		add_filter(
			'request',
			function ( $query ) {
				// Проверяем, что запрос идёт к типу поста 'attachment' (медиафайлы)
				if ( isset( $query['post_type'] ) && 'attachment' === $query['post_type'] ) {
					return $this->user_manager->getMediaFilterArgs( $query );
				}
				return $query;
			}
		);

		// 4. В будущем здесь будут AJAX-обработчики для фронтенд-профиля
		// $this->registerAjaxHooks();
	}

	/**
	 * Регистрирует AJAX-хуки для фронтенд-профиля пользователя.
	 * (Заглушка для будущего расширения)
	 *
	 * @return void
	 */
	private function registerAjaxHooks(): void {
		// Здесь будет регистрация хуков
	}
}
