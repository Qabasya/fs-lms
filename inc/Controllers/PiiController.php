<?php

declare( strict_types=1 );

namespace Inc\Controllers;

use Inc\Callbacks\Person\PersonUpdateCallbacks;
use Inc\Callbacks\Person\PersonViewCallbacks;
use Inc\Callbacks\Person\PiiRevealCallbacks;
use Inc\Callbacks\Person\RepresentativeCallbacks;
use Inc\Enums\AjaxHook;

/**
 * Class PiiController
 *
 * Контроллер для работы с персональными данными (PII) и управления лицами (Persons).
 *
 * @package Inc\Controllers
 *
 * ### Основные обязанности:
 *
 * 1. **Управление PII** — раскрытие полей, запрос на удаление, экспорт данных.
 * 2. **Управление лицами** — просмотр, обновление данных, управление представителями.
 * 3. **Эндпоинт скачивания экспорта** — обработка одноразовой ссылки для скачивания файлов.
 *
 * ### Архитектурная роль:
 * 
 * Наследует AjaxController для регистрации AJAX-хуков.
 * Делегирует бизнес-логику специализированным коллбекам:
 * - PiiRevealCallbacks — раскрытие PII
 * - PersonViewCallbacks — просмотр данных
 * - PersonUpdateCallbacks — обновление и удаление
 * - RepresentativeCallbacks — управление представителями
 *
 * ### Маршруты:
 *
 * - `/lms/export/{token}` — одноразовая ссылка для скачивания экспорта данных
 */
class PiiController extends AjaxController {

	/**
	 * Конструктор контроллера.
	 *
	 * @param PiiRevealCallbacks      $revealCallbacks      Коллбеки раскрытия PII
	 * @param PersonViewCallbacks     $viewCallbacks        Коллбеки просмотра данных
	 * @param PersonUpdateCallbacks   $updateCallbacks      Коллбеки обновления и удаления
	 * @param RepresentativeCallbacks $representativeCallbacks Коллбеки управления представителями
	 */
	public function __construct(
		private readonly PiiRevealCallbacks      $revealCallbacks,
		private readonly PersonViewCallbacks     $viewCallbacks,
		private readonly PersonUpdateCallbacks   $updateCallbacks,
		private readonly RepresentativeCallbacks $representativeCallbacks,
	) {
		parent::__construct();
	}

	/**
	 * Регистрирует все компоненты контроллера.
	 *
	 * @return void
	 */
	public function register(): void {
		// Регистрация эндпоинта для скачивания экспортированных файлов
		add_action( 'init', array( $this, 'addExportRewriteRule' ) );
		add_filter( 'query_vars', array( $this, 'addExportQueryVar' ) );
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
			// Раскрытие одного PII-поля
			array( AjaxHook::RevealPiiField, $this->revealCallbacks ),
			// Раскрытие всех PII-полей лица
			array( AjaxHook::RevealAllPersonPii, $this->revealCallbacks ),
			// Запрос на удаление персональных данных (soft delete)
			array( AjaxHook::RequestPiiDeletion, $this->updateCallbacks ),
			// Добавление законного представителя
			array( AjaxHook::AddRepresentative, $this->representativeCallbacks ),
			// Замена законного представителя
			array( AjaxHook::ReplaceRepresentative, $this->representativeCallbacks ),
			// Обновление данных лица
			array( AjaxHook::UpdatePerson, $this->updateCallbacks ),
			// Получение данных лица для отображения
			array( AjaxHook::GetPersonData, $this->viewCallbacks ),
		);
	}

	/**
	 * Добавляет rewrite правило для эндпоинта скачивания экспортированных файлов.
	 *
	 * @return void
	 */
	public function addExportRewriteRule(): void {
		// Маршрут: /lms/export/{token} → index.php?fs_lms_page=lms_export&fs_lms_token={token}
		add_rewrite_rule(
			'^lms/export/([a-zA-Z0-9]+)/?$',
			'index.php?fs_lms_page=lms_export&fs_lms_token=$matches[1]',
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
	public function addExportQueryVar( array $vars ): array {
		$vars[] = 'fs_lms_token';
		return $vars;
	}

	/**
	 * Отдаёт файл экспорта по одноразовому токену.
	 * Transient хранит массив: ['file' => path, 'filename' => name, 'content_type' => mime].
	 *
	 * @param string $template Путь к текущему шаблону темы
	 *
	 * @return string
	 */
	public function handleExportDownload( string $template ): string {
		// Проверка, что запрошена страница экспорта
		if ( 'lms_export' !== get_query_var( 'fs_lms_page' ) ) {
			return $template;
		}

		// Получение токена из URL
		$token = sanitize_key( get_query_var( 'fs_lms_token' ) );
		// get_transient() — получение мета-информации о файле
		$meta = get_transient( 'fs_lms_export_' . $token );

		// Проверка существования файла
		if ( ! is_array( $meta ) || empty( $meta['file'] ) || ! file_exists( (string) $meta['file'] ) ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
			return get_404_template();
		}

		// Удаление токена (одноразовая ссылка)
		delete_transient( 'fs_lms_export_' . $token );

		// Отправка заголовков для скачивания файла
		$filename    = (string) ( $meta['filename']     ?? 'export' );
		$contentType = (string) ( $meta['content_type'] ?? 'application/octet-stream' );

		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Type: ' . $contentType );
		nocache_headers();  // Запрет кеширования

		// readfile() — вывод содержимого файла в буфер вывода
		readfile( (string) $meta['file'] );
		// unlink() — удаление временного файла после скачивания
		unlink( (string) $meta['file'] );

		exit;
	}
}