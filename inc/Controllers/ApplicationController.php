<?php

declare( strict_types=1 );

namespace Inc\Controllers;

use Inc\Callbacks\ApplicationCallbacks;
use Inc\Enums\AjaxHook;

/**
 * Class ApplicationController
 *
 * Контроллер публичной формы зачисления (/lms/apply, /lms/join/{code}).
 *
 * @package Inc\Controllers
 *
 * ### Основные обязанности:
 *
 * 1. **Маршрутизация** — добавление кастомных rewrite правил для ЧПУ-ссылок.
 * 2. **Регистрация query-переменных** — добавление кастомных параметров в WordPress.
 * 3. **Подмена шаблонов** — перехват запросов и подключение кастомных шаблонов.
 * 4. **AJAX-обработчики** — регистрация публичных AJAX-действий (без авторизации).
 *
 * ### Архитектурная роль:
 *
 * Наследует AjaxController для регистрации публичных AJAX-хуков.
 * Делегирует бизнес-логику ApplicationCallbacks.
 *
 * ### Маршруты:
 *
 * - `/lms/apply` — форма создания заявки (ученик)
 * - `/lms/join/{code}` — форма присоединения родителя (по JOIN-коду)
 */
class ApplicationController extends AjaxController {

	/**
	 * Конструктор контроллера.
	 *
	 * @param ApplicationCallbacks $callbacks Коллбеки для обработки заявок
	 */
	public function __construct(
		private readonly ApplicationCallbacks $callbacks,
	) {
		parent::__construct();
	}

	/**
	 * Регистрирует все компоненты контроллера.
	 *
	 * @return void
	 */
	public function register(): void {
		// 'init' — хук, срабатывающий после загрузки WordPress
		add_action( 'init', array( $this, 'addRewriteRules' ) );
		// 'query_vars' — фильтр для добавления кастомных query-переменных
		add_filter( 'query_vars', array( $this, 'addQueryVars' ) );
		// 'template_include' — фильтр для подмены шаблона темы
		add_filter( 'template_include', array( $this, 'loadTemplate' ) );

		// Регистрация AJAX-обработчиков (унаследовано из AjaxController)
		parent::register();
	}

	/**
	 * Возвращает список публичных AJAX-действий (доступных без авторизации).
	 *
	 * @return array
	 */
	protected function publicAjaxActions(): array {
		return array(
			// Отправка OTP-кода на email (шаг A)
			array( AjaxHook::SendOtpCode, $this->callbacks ),
			// Создание заявки после верификации OTP (шаг B)
			array( AjaxHook::CreateApplication, $this->callbacks ),
			// Отправка данных родителя по JOIN-ссылке
			array( AjaxHook::SubmitParentData, $this->callbacks ),
		);
	}

	/**
	 * Добавляет кастомные rewrite правила для ЧПУ-ссылок.
	 * Вызывается на хуке 'init'.
	 *
	 * @return void
	 */
	public function addRewriteRules(): void {
		// add_rewrite_rule() — добавляет правило перезаписи URL
		// Параметры: regex, соответствие, позиция ('top' — в начало списка)

		// Маршрут: /lms/join/{code} → index.php?fs_lms_page=join&fs_lms_join_code={code}
		add_rewrite_rule(
			'^lms/join/([A-Z0-9\-]+)/?$',
			'index.php?fs_lms_page=join&fs_lms_join_code=$matches[1]',
			'top'
		);
	}

	/**
	 * Добавляет кастомные query-переменные в WordPress.
	 *
	 * @param array $vars Существующие query-переменные
	 *
	 * @return array
	 */
	public function addQueryVars( array $vars ): array {
		// Добавление переменных для идентификации страницы и JOIN-кода
		$vars[] = 'fs_lms_page';
		$vars[] = 'fs_lms_join_code';

		return $vars;
	}

	/**
	 * Подменяет шаблон темы на кастомный для страниц /lms/apply и /lms/join/{code}.
	 *
	 * @param string $template Путь к текущему шаблону темы
	 *
	 * @return string
	 */
	public function loadTemplate( string $template ): string {
		// get_query_var() — получает значение кастомной query-переменной
		$page = get_query_var( 'fs_lms_page' );

		// Страница присоединения родителя (/lms/join/{code})
		if ( 'join' === $page ) {
			// Подготовка страницы (валидация кода, расшифровка данных ученика)
			// Возвращает false, если нужно отдать 404
			if ( ! $this->callbacks->prepareJoinPage() ) {
				global $wp_query;
				// Установка статуса 404
				$wp_query->set_404();
				status_header( 404 );
				// nocache_headers() — запрещает кеширование страницы 404
				nocache_headers();

				return get_404_template();
			}

			$path = $this->path( 'templates/frontend/join.php' );

			return file_exists( $path ) ? $path : $template;
		}

		return $template;
	}
}