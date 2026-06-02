<?php

declare( strict_types=1 );

namespace Inc\Controllers;

use Inc\Callbacks\PiiCallbacks;
use Inc\Enums\AjaxHook;
use Inc\Enums\Capability;

/**
 * Class PiiController
 *
 * Контроллер для работы с персональными данными (PII): раскрытие, удаление,
 * управление представителями, страница "Люди" в админ-панели, эндпоинт скачивания файлов.
 *
 * @package Inc\Controllers
 *
 * ### Основные обязанности:
 *
 * 1. **Управление PII** — раскрытие полей, запрос на удаление.
 * 2. **Управление представителями** — добавление и замена законных представителей учеников.
 * 3. **Управление лицами (Persons)** — обновление данных.
 * 4. **Административные страницы** — карточка лица (person) в админ-панели.
 * 5. **Эндпоинт скачивания файлов** — одноразовая ссылка для скачивания CSV-экспортов.
 *
 * ### Архитектурная роль:
 *
 * Наследует AjaxController для регистрации AJAX-хуков.
 * Делегирует бизнес-логику PiiCallbacks.
 *
 * ### Маршруты:
 *
 * - `/lms/export/{token}` — одноразовая ссылка для скачивания файла экспорта
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

		add_action( 'init',             array( $this, 'addExportRewriteRule' ) );
		add_filter( 'query_vars',       array( $this, 'addExportQueryVar' ) );
		add_filter( 'template_include', array( $this, 'handleExportDownload' ) );

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
			array( AjaxHook::RevealPiiField,       $this->callbacks ),
			array( AjaxHook::RequestPiiDeletion,   $this->callbacks ),
			array( AjaxHook::AddRepresentative,    $this->callbacks ),
			array( AjaxHook::ReplaceRepresentative, $this->callbacks ),
			array( AjaxHook::UpdatePerson,         $this->callbacks ),
			array( AjaxHook::GetPersonData,        $this->callbacks ),
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
	 * Добавляет rewrite правило для эндпоинта скачивания файлов экспорта.
	 *
	 * @return void
	 */
	public function addExportRewriteRule(): void {
		add_rewrite_rule(
			'^lms/export/([a-zA-Z0-9]+)/?$',
			'index.php?fs_lms_page=lms_export&fs_lms_token=$matches[1]',
			'top'
		);
	}

	/**
	 * @param array $vars
	 * @return array
	 */
	public function addExportQueryVar( array $vars ): array {
		$vars[] = 'fs_lms_token';
		return $vars;
	}

	/**
	 * Отдаёт файл экспорта по одноразовому токену и удаляет его.
	 * Transient хранит массив: ['file' => path, 'filename' => name, 'content_type' => mime].
	 *
	 * @param string $template
	 * @return string
	 */
	public function handleExportDownload( string $template ): string {
		if ( 'lms_export' !== get_query_var( 'fs_lms_page' ) ) {
			return $template;
		}

		$token = sanitize_key( get_query_var( 'fs_lms_token' ) );
		$meta  = get_transient( 'fs_lms_export_' . $token );

		if ( ! is_array( $meta ) || empty( $meta['file'] ) || ! file_exists( (string) $meta['file'] ) ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
			return get_404_template();
		}

		delete_transient( 'fs_lms_export_' . $token );

		$filename     = (string) ( $meta['filename']     ?? 'export' );
		$contentType  = (string) ( $meta['content_type'] ?? 'application/octet-stream' );

		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Type: ' . $contentType );
		nocache_headers();

		readfile( (string) $meta['file'] );
		unlink( (string) $meta['file'] );

		exit;
	}
}