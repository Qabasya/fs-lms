<?php

namespace Inc;

use Inc\Contracts\ServiceInterface;
use Inc\Controllers\ApplicationController;
use Inc\Controllers\ApplyPageController;
use Inc\Controllers\ConsentController;
use Inc\Controllers\CronController;
use Inc\Controllers\AdminController;
use Inc\Controllers\AuthController;
use Inc\Controllers\AuthPageController;
use Inc\Controllers\BoilerplateController;
use Inc\Controllers\EnrollmentController;
use Inc\Controllers\MetaBoxController;
use Inc\Controllers\PiiController;
use Inc\Controllers\ProfileController;
use Inc\Controllers\ExpulsionController;
use Inc\Controllers\RecoveryController;
use Inc\Controllers\StudentGroupController;
use Inc\Controllers\SubjectController;
use Inc\Controllers\TaskCreationController;
use Inc\Controllers\TaskPageController;
use Inc\Controllers\LogsController;
use Inc\Controllers\SettingsController;
use Inc\Controllers\AuthLogController;
use Inc\Controllers\DeletionController;
use Inc\Controllers\UserController;
use Inc\Contracts\ClockInterface;
use Inc\Core\Container;
use Inc\Core\Enqueue;
use Inc\Services\WpClock;

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
			ApplyPageController::class,
			ProfileController::class,
			StudentGroupController::class,
			CronController::class,
			ConsentController::class,
			ApplicationController::class,
			EnrollmentController::class,
			PiiController::class,
			RecoveryController::class,
			ExpulsionController::class,
			DeletionController::class,
			SettingsController::class,
			LogsController::class,
			AuthLogController::class,
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
		$container->bind( ClockInterface::class, WpClock::class );

		foreach ( self::getServices() as $class ) {
			$service = $container->get( $class );

			// Проверяем, что объект реализует интерфейс ServiceInterface
			if ( $service instanceof ServiceInterface ) {
				$service->register();
			}
		}

		// Синхронизация capabilities администратора при несоответствии версии.
		// Запись в БД происходит только один раз при смене FS_LMS_CAPS_VERSION.
		$capsVersion = '1.0';
		if ( get_option( 'fs_lms_caps_version' ) !== $capsVersion ) {
			$roleManager = $container->get( \Inc\Managers\RoleManager::class );
			$roleManager->syncCapabilities();
			update_option( 'fs_lms_caps_version', $capsVersion );
		}
	}
}
