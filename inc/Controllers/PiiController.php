<?php

declare( strict_types=1 );

namespace Inc\Controllers;

use Inc\Callbacks\PiiCallbacks;
use Inc\Enums\AjaxHook;
use Inc\Enums\Capability;

/**
 * Class PiiController
 *
 * Контроллер для работы с персональными данными (PII): раскрытие, экспорт, удаление,
 * управление представителями, страница "Люди" в админ-панели, эндпоинт скачивания экспорта.
 *
 * @package Inc\Controllers
 *
 * ### Основные обязанности:
 *
 * 1. **Управление PII** — раскрытие полей, экспорт данных, запрос на удаление.
 * 2. **Управление представителями** — добавление и замена законных представителей учеников.
 * 3. **Управление лицами (Persons)** — обновление данных.
 * 4. **Административные страницы** — карточка лица (person) в админ-панели.
 * 5. **Эндпоинт скачивания экспорта** — обработка одноразовой ссылки на скачивание PII.
 *
 * ### Архитектурная роль:
 *
 * Наследует AjaxController для регистрации AJAX-хуков.
 * Делегирует бизнес-логику PiiCallbacks.
 *
 * ### Маршруты:
 *
 * - `/lms/pii-export/{token}` — одноразовая ссылка для скачивания экспорта PII
 */
class PiiController extends AjaxController {

	/**
	 * Конструктор контроллера.
	 *
	 * @param PiiCallbacks $callbacks Коллбеки для работы с PII
	 */
	public function __construct(
		private readonly PiiCallbacks $callbacks,
	) {
		parent::__construct();
	}

	/**
	 * Регистрирует все компоненты контроллера.
	 *
	 * @return void
	 */
	public function register(): void {
		// 'admin_menu' — хук для регистрации страниц админ-панели
		add_action( 'admin_menu', array( $this, 'registerPersonDetailPage' ) );

		// 'init' — хук для добавления rewrite правил
		add_action( 'init', array( $this, 'addPiiExportRewriteRule' ) );

		// 'query_vars' — фильтр для добавления кастомных query-переменных
		add_filter( 'query_vars', array( $this, 'addPiiExportQueryVar' ) );

		// 'template_include' — фильтр для перехвата запроса на скачивание экспорта
		add_filter( 'template_include', array( $this, 'handlePiiExportDownload' ) );

		// Регистрация AJAX-обработчиков (унаследовано из AjaxController)
		parent::register();
	}

	/**
	 * Возвращает список AJAX-действий для регистрации.
	 *
	 * @return array
	 */
	protected function ajaxActions(): array {
		return array(
			// Раскрытие одного PII-поля (временно, на 30 секунд)
			array( AjaxHook::RevealPiiField, $this->callbacks ),
			// Запрос на удаление персональных данных (soft delete)
			array( AjaxHook::RequestPiiDeletion, $this->callbacks ),
			// Экспорт персональных данных в JSON
			array( AjaxHook::ExportPii, $this->callbacks ),
			// Добавление законного представителя к ученику
			array( AjaxHook::AddRepresentative, $this->callbacks ),
			// Замена законного представителя
			array( AjaxHook::ReplaceRepresentative, $this->callbacks ),
			// Обновление данных лица (person)
			array( AjaxHook::UpdatePerson, $this->callbacks ),
			// Данные вкладок модального окна (представители, зачисления)
			array( AjaxHook::GetPersonData, $this->callbacks ),
		);
	}

	/**
	 * Регистрирует скрытую страницу карточки лица (person) в админ-панели.
	 *
	 * @return void
	 */
	public function registerPersonDetailPage(): void {
		// add_submenu_page() с parent_slug = null создаёт скрытую страницу
		add_submenu_page(
			null,                                   // Не показывать в меню
			'Карточка',                             // Заголовок страницы
			'',                                     // Название пункта меню
			Capability::ManagePersons->value,       // Необходимое право доступа
			'fs-lms-person-detail',                 // Уникальный идентификатор
			array( $this->callbacks, 'renderPersonDetailPage' )  // Коллбек отрисовки
		);
	}

	/**
	 * Добавляет rewrite правило для эндпоинта скачивания экспорта PII.
	 *
	 * @return void
	 */
	public function addPiiExportRewriteRule(): void {
		// Маршрут: /lms/pii-export/{token} → index.php?fs_lms_page=pii_export&fs_lms_token={token}
		add_rewrite_rule(
			'^lms/pii-export/([a-zA-Z0-9]+)/?$',
			'index.php?fs_lms_page=pii_export&fs_lms_token=$matches[1]',
			'top'
		);
	}

	/**
	 * Добавляет кастомную query-переменную для токена экспорта.
	 *
	 * @param array $vars Существующие query-переменные
	 *
	 * @return array
	 */
	public function addPiiExportQueryVar( array $vars ): array {
		$vars[] = 'fs_lms_token';
		return $vars;
	}

	/**
	 * Отдаёт файл экспорта по одноразовому токену.
	 * Перехватывает запрос, не позволяя WordPress загрузить шаблон темы.
	 *
	 * @param string $template Путь к текущему шаблону темы
	 *
	 * @return string
	 */
	public function handlePiiExportDownload( string $template ): string {
		// Проверка, что запрошена страница экспорта PII
		if ( 'pii_export' !== get_query_var( 'fs_lms_page' ) ) {
			return $template;
		}

		// Получение токена из URL
		$token = sanitize_key( get_query_var( 'fs_lms_token' ) );
		// get_transient() — получение временных данных (путь к файлу)
		$file = get_transient( 'fs_lms_export_' . $token );

		// Проверка существования файла
		if ( ! $file || ! file_exists( (string) $file ) ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();

			return get_404_template();
		}

		// Удаление токена (одноразовая ссылка)
		delete_transient( 'fs_lms_export_' . $token );

		// Отправка заголовков для скачивания файла
		header( 'Content-Disposition: attachment; filename="pii-export.json"' );
		header( 'Content-Type: application/json; charset=utf-8' );
		nocache_headers();  // Запрет кеширования

		// readfile() — вывод содержимого файла в буфер вывода
		readfile( (string) $file );
		// unlink() — удаление временного файла после скачивания
		unlink( (string) $file );

		// Завершение выполнения скрипта (не передаём управление WordPress)
		exit;
	}
}