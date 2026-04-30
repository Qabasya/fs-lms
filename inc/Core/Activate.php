<?php

namespace Inc\Core;

use Inc\Managers\UserManager;
use Inc\Core\Container;

/**
 * Class Activate
 *
 * Обработчик события активации плагина.
 *
 * Вызывается при активации плагина через WordPress admin.
 * Содержит все необходимые операции для корректного запуска плагина:
 * - Сброс правил перезаписи (flush rewrite rules)
 * - Создание таблиц базы данных
 * - Установка значений по умолчанию
 * - Инициализация опций
 *
 * @package Inc\Core
 *
 * @example
 * // Регистрация в главном файле плагина
 * register_activation_hook(__FILE__, [Activate::class, 'activate']);
 */
class Activate {
	/**
	 * Выполняет действия при активации плагина.
	 *
	 * Сбрасывает правила перезаписи WordPress, чтобы пользовательские
	 * типы записей (CPT) и таксономии корректно работали с ЧПУ.
	 *
	 * @return void
	 */
	public static function activate(): void {
		// Создаем контейнер, чтобы разрешить зависимости UserManager
		$container = new Container();
		
		/** @var UserManager $user_manager */
		$user_manager = $container->get( UserManager::class );
		
		$user_manager->createRoles();
		
		flush_rewrite_rules();
	}
}