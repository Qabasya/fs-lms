<?php

declare(strict_types=1);

namespace Inc\Core;

use Inc\Contracts\ServiceInterface;
use Inc\Enums\AjaxHook;
use Inc\Enums\Nonce;
use Inc\Repositories\TaxonomyRepository;

/**
 * Class Enqueue
 *
 * Управление подключением скриптов и стилей плагина.
 *
 * @package Inc\Core
 * @implements ServiceInterface
 *
 * ### Основные обязанности:
 *
 * 1. **Подключение стилей** — регистрация и подключение CSS-файлов для админки и фронтенда.
 * 2. **Подключение скриптов** — регистрация JS-файлов с зависимостями (jQuery, wp-api, wp-i18n).
 * 3. **Локализация данных** — передача PHP-данных (nonce, AJAX URL, таксономии) в JavaScript.
 * 4. **Рендеринг модалки** — вывод HTML-шаблона модального окна подтверждения в админ-футере.
 *
 * ### Архитектурная роль:
 *
 * Делегирует получение обязательных таксономий репозиторию TaxonomyRepository,
 * а версионирование и пути — родительскому классу BaseController.
 */
class Enqueue extends BaseController implements ServiceInterface {

	public function __construct( private readonly TaxonomyRepository $taxonomy_repository ) {
		parent::__construct();
	}

	/**
	 * Регистрация всех хуков подключения ресурсов.
	 *
	 * @return void
	 */
	public function register(): void {
		// 'admin_enqueue_scripts' — хук для подключения ресурсов в админ-панели
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		// 'wp_enqueue_scripts' — хук для подключения ресурсов на фронтенде
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );
		// 'admin_footer' — хук для вывода HTML в подвале админки
		add_action( 'admin_footer', array( $this, 'render_confirm_modal' ) );
	}

	/**
	 * Подключение ресурсов в административной панели.
	 *
	 * @return void
	 */
	public function enqueue_admin_assets(): void {
		// wp_enqueue_media() — подключает медиа-библиотеку WordPress (для загрузки изображений)
		wp_enqueue_media();

		// wp_enqueue_style() — подключает CSS-файл
		wp_enqueue_style(
			'fs-lms-common-style',                        // Идентификатор стиля
			$this->url( 'assets/css/common.min.css' ),    // URL файла
			array(),                                            // Зависимости
			$this->plugin_version                         // Версия (для очистки кеша)
		);

		wp_enqueue_style(
			'fs-lms-admin-style',
			$this->url( 'assets/css/admin.min.css' ),
			array( 'wp-components', 'fs-lms-common-style' ),  // Зависит от компонентов WP и общего стиля
			$this->plugin_version
		);

		$script_handle = 'fs-lms-admin-script';

		// wp_enqueue_script() — подключает JS-файл
		wp_enqueue_script(
			'fs-lms-common-script',
			$this->url( 'assets/js/common.min.js' ),
			array( 'jquery' ),      // Зависимость от jQuery
			$this->plugin_version,
			true               // Загружать в футере
		);

		wp_enqueue_script(
			$script_handle,
			$this->url( 'assets/js/admin.min.js' ),
			array( 'jquery', 'wp-api', 'wp-i18n', 'editor', 'quicktags' ),
			$this->plugin_version,
			true
		);

		// get_current_screen() — возвращает объект текущего экрана админки
		$screen = get_current_screen();

		$page = sanitize_text_field( $_GET['page'] ?? '' );

		// На странице списка заданий (CPT с суффиксом '_tasks')
		if ( is_admin() && $screen && str_contains( $screen->post_type, '_tasks' ) ) {
			$subject_key = str_replace( '_tasks', '', $screen->post_type );

			// wp_localize_script() — передаёт PHP-данные в JS-объект
			wp_localize_script(
				$script_handle,
				'fs_lms_task_data',
				array(
					'ajax_url'            => admin_url( 'admin-ajax.php' ),
					'security'            => Nonce::TaskCreation->create(),  // Создание nonce
					'subject_key'         => $subject_key,
					'post_type'           => $screen->post_type,
					'required_taxonomies' => $this->getRequiredTaxonomies( $subject_key ),
				)
			);
		}
		// На странице управления предметом (префикс 'fs_subject_')
		elseif ( str_starts_with( $page, 'fs_subject_' ) ) {
			$subject_key = substr( $page, strlen( 'fs_subject_' ) );
			wp_localize_script(
				$script_handle,
				'fs_lms_task_data',
				array(
					'ajax_url'            => admin_url( 'admin-ajax.php' ),
					'security'            => Nonce::TaskCreation->create(),
					'subject_key'         => $subject_key,
					'post_type'           => $subject_key . '_tasks',
					'required_taxonomies' => $this->getRequiredTaxonomies( $subject_key ),
				)
			);
			// inline-edit-post — скрипт для быстрого редактирования постов в админке
			wp_enqueue_script( 'inline-edit-post' );
		}

		// Глобальные переменные для всех страниц админки
		wp_localize_script(
			$script_handle,
			'fs_lms_vars',
			array(
				'ajaxurl'       => admin_url( 'admin-ajax.php' ),
				'subject_nonce' => Nonce::Subject->create(),
				'manager_nonce' => Nonce::Manager->create(),
				'ajax_actions'  => AjaxHook::toJsArray(),  // Массив AJAX-действий для JS
			)
		);
	}

	/**
	 * Подключение ресурсов на фронтенде (публичная часть сайта).
	 *
	 * @return void
	 */
	public function enqueue_frontend_assets(): void {
		wp_enqueue_style(
			'fs-lms-common-style',
			$this->url( 'assets/css/common.min.css' ),
			array(),
			$this->plugin_version
		);

		wp_enqueue_style(
			'fs-lms-frontend-style',
			$this->url( 'assets/css/frontend.min.css' ),
			array( 'fs-lms-common-style' ),
			$this->plugin_version
		);

		wp_enqueue_script(
			'fs-lms-common-script',
			$this->url( 'assets/js/common.min.js' ),
			array( 'jquery' ),
			$this->plugin_version,
			true
		);

		wp_enqueue_script(
			'fs-lms-frontend-script',
			$this->url( 'assets/js/frontend.min.js' ),
			array( 'jquery', 'fs-lms-common-script' ),
			$this->plugin_version,
			true
		);
	}

	/**
	 * Возвращает список обязательных таксономий для указанного предмета.
	 *
	 * @param string $subject_key Ключ предмета
	 *
	 * @return array
	 */
	private function getRequiredTaxonomies( string $subject_key ): array {
		// array_filter() — оставляет только таксономии с флагом is_required = true
		// array_values() — сбрасывает ключи после фильтрации
		return array_values(
			array_map(
				fn( $dto ) => array(
					'slug' => $dto->slug,
					'name' => $dto->name,
				),
				array_filter(
					$this->taxonomy_repository->getBySubject( $subject_key ),
					fn( $dto ) => $dto->is_required
				)
			)
		);
	}

	/**
	 * Глобально рендерит HTML модального окна подтверждения в админке.
	 *
	 * @return void
	 */
	public function render_confirm_modal(): void {
		$page = sanitize_text_field( $_GET['page'] ?? '' );

		// Показываем модалку только на страницах нашего плагина (с префиксом 'fs_')
		if ( ! str_starts_with( $page, 'fs_' ) ) {
			return;
		}

		// dirname(, 2) — поднимаемся на 2 уровня вверх от текущей директории
		// Из Inc/Core/Enqueue.php → Inc/Core → Inc → корень плагина
		$modal_path = dirname( __DIR__, 2 ) . '/templates/components/modals/confirm-modal.php';

		// file_exists() — проверяет существование файла перед подключением
		if ( file_exists( $modal_path ) ) {
			require_once $modal_path;
		}
	}
}
