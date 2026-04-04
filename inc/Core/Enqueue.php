<?php

namespace Inc\Core;

use Inc\Contracts\ServiceInterface;

/**
 * Class Enqueue
 *
 * Менеджер подключения ресурсов (CSS/JS) плагина.
 *
 * Регистрирует и подключает все скрипты и стили плагина
 * через стандартные WordPress хуки:
 * - admin_enqueue_scripts — для админ-панели
 * - wp_enqueue_scripts — для фронтенда
 *
 * Использует минифицированные версии файлов для продакшена.
 *
 * @package Inc\Core
 * @implements ServiceInterface
 */
class Enqueue extends BaseController implements ServiceInterface {
	/**
	 * Регистрирует хуки для подключения ресурсов.
	 *
	 * @return void
	 */
	public function register(): void {
		// Админские ресурсы (CSS/JS для админ-панели)
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );

		// Ресурсы фронтенда (CSS/JS для публичной части)
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_frontend_assets' ] );
	}

	/**
	 * Подключает стили и скрипты для административной панели.
	 *
	 * Загружает:
	 * - CSS с зависимостью от wp-components (стили Gutenberg)
	 * - JS с зависимостями от jQuery, WordPress API и интернационализации
	 * - Локализует данные для JavaScript:
	 *   - fsTaskData — данные для создания заданий (на страницах CPT _tasks)
	 *   - fs_lms_vars — общие данные для AJAX (security, ajaxurl)
	 *
	 * @return void
	 */
	public function enqueue_admin_assets(): void {
		// 1. Подключаем стили админ-панели
		wp_enqueue_style(
			'fs-lms-admin-style',                         // Уникальный идентификатор
			$this->url( 'assets/css/admin.min.css' ),       // Путь к минифицированному CSS
			[ 'wp-components' ],                            // Зависимость от Gutenberg стилей
			$this->plugin_version                         // Версия для сброса кэша
		);

		// 2. Подключаем основной скрипт админ-панели
		// Сохраняем идентификатор в переменную для дальнейшего использования
		$script_handle = 'fs-lms-admin-script';

		wp_enqueue_script(
			$script_handle,                               // Уникальный идентификатор
			$this->url( 'assets/js/admin.min.js' ),         // Путь к минифицированному JS
			[ 'jquery', 'wp-api', 'wp-i18n' ],              // Зависимости: jQuery, WP REST API, интернационализация
			$this->plugin_version,                        // Версия для сброса кэша
			true                                          // Загрузка в футере для производительности
		);

		// 3. Локализация данных для JavaScript
		$screen = get_current_screen();

		// Данные для создания заданий (модальное окно на страницах CPT * _tasks)
		// Проверяем, находимся ли мы на странице редактирования/создания задания
		if ( is_admin() && $screen && str_contains( $screen->post_type, '_tasks' ) ) {
			wp_localize_script( $script_handle, 'fsTaskData', [
				'ajax_url'    => admin_url( 'admin-ajax.php' ),      // URL для AJAX-запросов
				'nonce'       => wp_create_nonce( 'fs_task_creation_nonce' ), // Nonce для безопасности
				'subject_key' => str_replace( '_tasks', '', $screen->post_type ), // Ключ предмета из CPT
				'post_type'   => $screen->post_type               // Тип поста задания
			] );
		}

		// Общие данные для менеджера заданий и настроек предметов
		// Используем тот же идентификатор скрипта для локализации
		wp_localize_script( $script_handle, 'fs_lms_vars', [
			'ajaxurl'  => admin_url( 'admin-ajax.php' ),             // URL для AJAX-запросов
			'security' => wp_create_nonce( 'fs_subject_nonce' )      // Nonce для операций с предметами
		] );
	}

	/**
	 * Подключает стили и скрипты для публичной части сайта.
	 *
	 * Загружает:
	 * - CSS без зависимостей
	 * - JS с зависимостью от jQuery
	 *
	 * @return void
	 */
	public function enqueue_frontend_assets(): void {
		// Подключение CSS стилей для фронтенда
		wp_enqueue_style(
			'fs-lms-frontend-style',                         // Уникальный идентификатор
			$this->url( 'assets/css/frontend.min.css' ),       // Путь к минифицированному CSS
			[],                                              // Нет зависимостей
			$this->plugin_version                            // Версия для сброса кэша
		);

		// Подключение JavaScript для фронтенда
		wp_enqueue_script(
			'fs-lms-frontend-script',                        // Уникальный идентификатор
			$this->url( 'assets/js/frontend.min.js' ),         // Путь к минифицированному JS
			[ 'jquery' ],                                      // Зависимость от jQuery
			$this->plugin_version,                           // Версия для сброса кэша
			true                                             // Загрузка в футере для производительности
		);
	}
}