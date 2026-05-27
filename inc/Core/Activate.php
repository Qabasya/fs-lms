<?php

declare( strict_types=1 );

namespace Inc\Core;

use Inc\Enums\PageRoutes;
use Inc\Enums\ShortCode;
use Inc\Managers\UserManager;
use Inc\Services\PageGeneratorService;

/**
 * Class Activate
 *
 * Класс, отвечающий за действия при активации плагина.
 *
 * @package Inc\Core
 *
 * ### Основные обязанности:
 *
 * 1. **Создание ролей пользователей** — регистрация кастомных ролей (преподаватель, ученик, родитель).
 * 2. **Генерация страниц** — автоматическое создание страниц входа, регистрации и профиля.
 * 3. **Обновление правил перезаписи** — сброс ЧПУ для корректной работы кастомных маршрутов.
 *
 * ### Архитектурная роль:
 *
 * Вызывается через register_activation_hook при активации плагина.
 * Использует DI-контейнер для получения сервисов и отдельный сервис PageGeneratorService
 * для создания страниц.
 */
class Activate {

	/**
	 * Основной метод активации плагина.
	 *
	 * @return void
	 */
	public static function activate(): void {
		// Создание экземпляра DI-контейнера
		$container = new Container();

		/** @var UserManager $user_manager */
		$user_manager = $container->get( UserManager::class );
		// Создание кастомных ролей пользователей
		$user_manager->createRoles();

		// Автоматическое создание страниц входа, регистрации и профиля
		self::generatePages();

		// flush_rewrite_rules() — сбрасывает и пересобирает правила ЧПУ в WordPress
		// Необходимо после регистрации новых CPT, таксономий или маршрутов
		flush_rewrite_rules();
	}

	/**
	 * Генерирует служебные страницы плагина, если они не существуют.
	 *
	 * @return void
	 */
	private static function generatePages(): void {
		$generator = new PageGeneratorService();

		// createPageIfNeeded() — создаёт страницу, если её ещё нет
		// Параметры: enum страницы, заголовок, шорткод для вставки
		$generator->createPageIfNeeded( PageRoutes::SignIn, 'Авторизация', ShortCode::LoginForm->tag() );
		$generator->createPageIfNeeded( PageRoutes::SignUp, 'Регистрация', ShortCode::RegisterForm->tag() );
		$generator->createPageIfNeeded( PageRoutes::UserProfile, 'Личный кабинет', ShortCode::Profile->tag() );
	}
}