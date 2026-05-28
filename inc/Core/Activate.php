<?php

declare( strict_types=1 );

namespace Inc\Core;

use Inc\Enums\PageRoutes;
use Inc\Enums\ShortCode;
use Inc\Enums\CronHook;
use Inc\Managers\CronManager;
use Inc\Managers\RoleManager;
use Inc\Migrations\Migration_1_0_0;
use Inc\Migrations\MigrationRunner;
use Inc\Services\PageGeneratorService;
use Inc\Services\PiiCryptoService;

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
		if ( ! PiiCryptoService::isAvailable() ) {
			wp_die(
				'<h1>FS LMS: Ошибка активации</h1>'
				. '<p>Плагин не может быть активирован без настроенного шифрования персональных данных.</p>'
				. '<p>Добавьте в файл <code>wp-config.php</code> следующие константы:</p>'
				. '<pre>'
				. "define('FS_LMS_ENC_KEY', '&lt;base64_ключ_32_байта&gt;');\n"
				. "define('FS_LMS_HASH_SALT', '&lt;случайная_строка&gt;');"
				. '</pre>'
				. '<p>Для генерации ключа выполните в терминале:</p>'
				. '<pre>php -r "echo base64_encode(sodium_crypto_secretbox_keygen());"</pre>'
				. '<p>Для `FS_LMS_HASH_SALT` подойдёт любая уникальная строка.</p>'
				. '<p>Рекомендуется использовать генератор случайных паролей или выполнить:</p>'
				. '<pre>php -r "echo bin2hex(random_bytes(32));"</pre>',
				'FS LMS — Требуется настройка шифрования',
				array(
					'response'  => 200,
					'back_link' => true,
				)
			);
		}

		// Создание экземпляра DI-контейнера
		$container = new Container();

		/** @var RoleManager $role_manager */
		$role_manager = $container->get( RoleManager::class );
		$role_manager->registerAll();

		/** @var CronManager $cron_manager */
		$cron_manager = $container->get( CronManager::class );
		$cron_manager->addCustomInterval( 'every_15_minutes', 900, 'Every 15 minutes' );
		add_filter( 'cron_schedules', array( $cron_manager, 'filterCronSchedules' ) );
		$cron_manager->schedule( CronHook::ExpireApplications->value, 'daily' );
		$cron_manager->schedule( CronHook::RetentionCleanup->value, 'daily' );
		$cron_manager->schedule( CronHook::RecoveryTick->value, 'every_15_minutes' );

		$migration_runner = new MigrationRunner();
		$migration_runner->register( new Migration_1_0_0() );
		$migration_runner->run();

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
