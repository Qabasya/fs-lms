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
	 *
	 * @return void
	 */
	public function enqueue_admin_assets(): void {
		// 1. Подключаем стили
		wp_enqueue_style(
			'fs-lms-admin-style',
			$this->url( 'assets/css/admin.min.css' ),
			[ 'wp-components' ],
			$this->plugin_version
		);

		// 2. Подключаем основной скрипт
		// Запомним ID в переменную, чтобы не ошибиться
		$script_handle = 'fs-lms-admin-script';

		wp_enqueue_script(
			$script_handle,
			$this->url( 'assets/js/admin.min.js' ),
			[ 'jquery', 'wp-api', 'wp-i18n' ],
			$this->plugin_version,
			true
		);

		// 3. Локализация данных
		$screen = get_current_screen();

		// Данные для создания заданий (твоя старая логика для модалки)
		if ( is_admin() && $screen && str_contains( $screen->post_type, '_tasks' ) ) {
			wp_localize_script( $script_handle, 'fsTaskData', [
				'ajax_url'    => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( 'fs_task_creation_nonce' ),
				'subject_key' => str_replace( '_tasks', '', $screen->post_type ),
				'post_type'   => $screen->post_type
			]);
		}

		// Данные для Менеджера заданий и общих настроек предмета
		// Используем тот же $script_handle!
		wp_localize_script( $script_handle, 'fs_lms_vars', [
			'ajaxurl'  => admin_url( 'admin-ajax.php' ),
			'security' => wp_create_nonce( 'fs_subject_nonce' )
		]);
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
			'fs-lms-frontend-style',                      // Уникальный идентификатор
			$this->url( 'assets/css/frontend.min.css' ),    // Путь к файлу
			[],                                           // Нет зависимостей
			$this->plugin_version                         // Версия для кэширования
		);

		// Подключение JavaScript для фронтенда
		wp_enqueue_script(
			'fs-lms-frontend-script',                     // Уникальный идентификатор
			$this->url( 'assets/js/frontend.min.js' ),      // Путь к файлу
			[ 'jquery' ],                                   // Зависимость от jQuery
			$this->plugin_version,                        // Версия для кэширования
			true                                          // Загрузка в футере
		);
	}


}