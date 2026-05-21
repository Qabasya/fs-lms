<?php

namespace Inc;

use Inc\Contracts\ServiceInterface;
use Inc\Controllers\AcademicPeriodController;
use Inc\Controllers\AdminController;
use Inc\Controllers\AuthController;
use Inc\Controllers\AuthPageController;
use Inc\Controllers\BoilerplateController;
use Inc\Controllers\MetaBoxController;
use Inc\Controllers\ProfileController;
use Inc\Controllers\StudentGroupController;
use Inc\Controllers\SubjectController;
use Inc\Controllers\TaskCreationController;
use Inc\Controllers\TaskPageController;
use Inc\Controllers\UserController;
use Inc\Core\Container;
use Inc\Core\Enqueue;

/**
 * Class Init
 *
 * Точка входа для инициализации всех сервисов плагина.
 *
 * Реализует паттерн Service Registry — централизованно управляет
 * списком всех сервисов, которые необходимо зарегистрировать.
 *
 * Использует DI-контейнер для автоматического разрешения зависимостей
 * и гарантирует, что каждый сервис реализует интерфейс ServiceInterface.
 *
 * @package Inc
 *
 * @example
 * // Запуск плагина
 * Init::run();
 */
final class Init {

	/**
	 * Возвращает список всех сервисов плагина.
	 *
	 * Сервисы регистрируются в порядке добавления.
	 * Каждый сервис должен реализовывать интерфейс ServiceInterface.
	 *
	 * @return array<int, class-string<ServiceInterface>> Массив имён классов сервисов
	 */
	public static function getServices(): array {
		return array(
			Enqueue::class,           // Подключение скриптов и стилей
			AdminController::class,   // Административное меню
			SubjectController::class, // Управление предметами и CPT
			MetaBoxController::class, // Метабоксы заданий
			TaskCreationController::class, // Создание заданий
			TaskPageController::class,     // Frontend-страница задания
			BoilerplateController::class,  // Типовые условия (boilerplate)
			UserController::class,
			AuthController::class,
			AuthPageController::class,
			ProfileController::class,
			AcademicPeriodController::class,
			StudentGroupController::class,
		);
	}

	/**
	 * Запускает регистрацию всех сервисов плагина.
	 *
	 * Процесс инициализации:
	 * 1. Создаёт DI-контейнер
	 * 2. Для каждого сервиса из списка получает экземпляр через контейнер
	 * 3. Проверяет, реализует ли объект интерфейс ServiceInterface
	 * 4. Вызывает метод register() для инициализации сервиса
	 *
	 * @return void
	 */
	public static function run(): void {
		$container = new Container();

		foreach ( self::getServices() as $class ) {
			$service = $container->get( $class );

			// Проверяем, что объект реализует интерфейс ServiceInterface
			if ( $service instanceof ServiceInterface ) {
				$service->register();
			}
		}
	}
}
