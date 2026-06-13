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
use Inc\Repositories\OptionsRepositories\ConsentDefinitionsRepository;
use Inc\Services\PageGeneratorService;
use Inc\Services\Security\PiiCryptoService;

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
	 * Выводит admin notice с инструкцией по добавлению констант шифрования.
	 *
	 * @return void
	 */
	public static function showConfigNotice(): void {
		echo '<div class="notice notice-error"><p>'
			. '<strong>FS LMS:</strong> Плагин не запущен — добавьте в <code>wp-config.php</code> следующие константы:'
			. '</p><pre style="margin:4px 0">'
			. "define('FS_LMS_ENC_KEY', '&lt;base64_ключ_32_байта&gt;');\n"
			. "define('FS_LMS_HASH_SALT', '&lt;случайная_строка&gt;');"
			. '</pre><p>'
			. 'Сгенерировать ключ: <code>php -r "echo base64_encode(sodium_crypto_secretbox_keygen());"</code>'
			. '</p></div>';
	}

	/**
	 * Генерирует служебные страницы плагина, если они не существуют.
	 *
	 * @return void
	 */
	private static function generatePages(): void {
		$generator = new PageGeneratorService();

		$generator->createPageIfNeeded( PageRoutes::SignIn, 'Авторизация', ShortCode::LoginForm->tag() );
		$generator->createPageIfNeeded( PageRoutes::Apply, 'Подать заявку', ShortCode::ApplyForm->tag() );
		$generator->createPageIfNeeded( PageRoutes::UserProfile, 'Личный кабинет', ShortCode::Profile->tag() );

		self::createDefaultConsentIfNeeded();
	}

	/**
	 * Создаёт согласие 'pd_processing' при активации.
	 * Пересоздаёт страницу если определение есть, но страница удалена или не опубликована.
	 */
	private static function createDefaultConsentIfNeeded(): void {
		$repo    = new ConsentDefinitionsRepository();
		$name    = 'Согласие на обработку персональных данных';
		$slug    = 'lms-consent-pd-processing';
		$content = self::defaultConsentContent();

		$existing = $repo->findByKey( 'pd_processing' );

		if ( null !== $existing ) {
			$pageId = (int) ( $existing['page_id'] ?? 0 );
			$page   = $pageId > 0 ? get_post( $pageId ) : null;

			// Страница существует и опубликована — всё в порядке
			if ( $page instanceof \WP_Post && 'publish' === $page->post_status ) {
				return;
			}

			// Страница удалена или это черновик — пересоздаём
		}

		// Ищем уже существующую страницу по slug (могла быть создана вручную)
		$existing_page = get_page_by_path( $slug );
		if ( $existing_page instanceof \WP_Post ) {
			// Обновляем её до publish с нужным контентом
			$pageId = wp_update_post( array(
				'ID'           => $existing_page->ID,
				'post_status'  => 'publish',
				'post_content' => $existing_page->post_content ?: $content,
			) );
		} else {
			$pageId = wp_insert_post( array(
				'post_title'   => $name,
				'post_name'    => $slug,
				'post_status'  => 'publish',
				'post_type'    => 'page',
				'post_content' => $content,
			) );
		}

		if ( $pageId && ! is_wp_error( $pageId ) ) {
			$repo->save( 'pd_processing', $name, (int) $pageId );
		}
	}

	private static function defaultConsentContent(): string {
		return '<p>Настоящим я, в соответствии с Федеральным законом № 152-ФЗ «О персональных данных», '
			. 'свободно, своей волей и в своём интересе даю согласие на обработку моих персональных данных '
			. 'и персональных данных моего ребёнка (подопечного).</p>'
			. '<h3>Перечень персональных данных</h3>'
			. '<p>Фамилия, имя, отчество; дата рождения; сведения об образовании (школа, класс); '
			. 'контактные данные (телефон, электронная почта); реквизиты документа, удостоверяющего личность; ИНН.</p>'
			. '<h3>Цели обработки</h3>'
			. '<p>Заключение и исполнение договора об оказании образовательных услуг.</p>'
			. '<h3>Срок действия</h3>'
			. '<p>До отзыва настоящего согласия или до истечения срока, установленного законодательством РФ.</p>'
			. '<p><em>Текст согласия носит ознакомительный характер. Перед публикацией замените его актуальным '
			. 'юридическим текстом, согласованным с вашим юристом.</em></p>';
	}
}
