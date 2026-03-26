<?php

	namespace Inc;

	use Inc\Contracts\Service;
	use Inc\Core\Container;
	use Inc\Core\Enqueue;
	use Inc\Managers\CPTManager;
	use Inc\Controllers\Admin;

	/**
	 * Class Init
	 *
	 * Точка входа для инициализации всех сервисов плагина.
	 *
	 * Реализует паттерн Service Registry — централизованно управляет
	 * списком всех сервисов, которые необходимо зарегистрировать.
	 *
	 * Использует DI-контейнер для автоматического разрешения зависимостей
	 * и гарантирует, что каждый сервис реализует интерфейс Service.
	 *
	 * @package Inc
	 *
	 * @example
	 * // Запуск плагина
	 * Init::run();
	 */
	final class Init
	{
		/**
		 * Возвращает список всех сервисов плагина.
		 *
		 * Сервисы регистрируются в порядке добавления.
		 * Каждый сервис должен реализовывать интерфейс Service.
		 *
		 * @return array<int, class-string<Service>> Массив имён классов сервисов
		 */
		public static function getServices(): array
		{
			return [
				Enqueue::class,      // Подключение скриптов и стилей
				Admin::class,        // Административное меню
				CPTManager::class    // Пользовательские типы записей
			];
		}

		/**
		 * Запускает регистрацию всех сервисов плагина.
		 *
		 * Процесс инициализации:
		 * 1. Создаёт DI-контейнер
		 * 2. Для каждого сервиса из списка получает экземпляр через контейнер
		 * 3. Проверяет, реализует ли объект интерфейс Service
		 * 4. Вызывает метод register() для инициализации сервиса
		 *
		 * @return void
		 */
		public static function run(): void
		{
			$container = new Container();

			foreach (self::getServices() as $class) {
				$service = $container->get($class);

				// Проверяем, что объект реализует интерфейс Service
				if ($service instanceof Service) {
					$service->register();
				}
			}
		}
	}