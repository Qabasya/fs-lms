<?php

declare( strict_types=1 );

namespace Inc\Callbacks;

use Inc\Core\BaseController;
use Inc\DTO\CsvColumn;
use Inc\Enums\Capability;
use Inc\Enums\Nonce;
use Inc\Repositories\WPDBRepositories\AuditLogRepository;
use Inc\Repositories\WPDBRepositories\PiiAccessLogRepository;
use Inc\Services\CsvExportService;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;

/**
 * Class LogsCallbacks
 *
 * AJAX-обработчики для экспорта логов (аудит и доступ к PII).
 *
 * @package Inc\Callbacks
 *
 * ### Основные обязанности:
 *
 * 1. **Экспорт логов аудита** — выгрузка записей из fs_lms_audit_log в CSV.
 * 2. **Экспорт логов доступа к PII** — выгрузка записей из fs_lms_pii_access_log в CSV.
 *
 * ### Архитектурная роль:
 *
 * Делегирует получение данных репозиториям (AuditLogRepository, PiiAccessLogRepository),
 * а формирование CSV — CsvExportService.
 * Используется в административной панели для выгрузки логов в целях аудита.
 *
 * ### Примечания:
 *
 * - Экспорт доступен только пользователям с правами Capability::Admin.
 * - Поддерживается фильтрация по датам, пользователям и действиям.
 * - CSV-файлы создаются временно и отдаются по одноразовой ссылке.
 */
class LogsCallbacks extends BaseController {

	use Authorizer;  // Трейт с методами authorize()
	use Sanitizer;   // Трейт с методами sanitizeKey(), sanitizeInt(), sanitizeText()

	/**
	 * Конструктор коллбеков.
	 *
	 * @param AuditLogRepository    $auditLog Репозиторий логов аудита
	 * @param PiiAccessLogRepository $piiLog  Репозиторий логов доступа к PII
	 * @param CsvExportService      $csv      Сервис экспорта в CSV
	 */
	public function __construct(
		private readonly AuditLogRepository    $auditLog,
		private readonly PiiAccessLogRepository $piiLog,
		private readonly CsvExportService       $csv,
	) {
		parent::__construct();
	}

	/**
	 * Экспортирует логи аудита в CSV-файл.
	 *
	 * @return void
	 */
	public function ajaxExportAuditLog(): void {
		$this->authorize( Nonce::Manager, Capability::Admin );

		// Сбор фильтров из запроса
		$filters = array_filter( array(
			'action'        => $this->sanitizeKey( 'action_filter' ),
			'actor_user_id' => $this->sanitizeInt( 'actor_id' ) ?: null,
			'date_from'     => $this->sanitizeText( 'date_from' ),
			'date_to'       => $this->sanitizeText( 'date_to' ),
		) );

		// listAll() — получение всех записей по фильтрам (без пагинации)
		$rows    = $this->auditLog->listAll( $filters );

		// Определение колонок для CSV
		$columns = array(
			new CsvColumn( 'ID',          fn( $r ) => $r->id ),
			new CsvColumn( 'Дата',        fn( $r ) => $r->createdAt ),
			new CsvColumn( 'User ID',     fn( $r ) => $r->actorUserId ?? '' ),
			new CsvColumn( 'Роль',        fn( $r ) => $r->actorRole ?? '' ),
			new CsvColumn( 'Действие',    fn( $r ) => $r->action ),
			new CsvColumn( 'Тип объекта', fn( $r ) => $r->targetType ?? '' ),
			new CsvColumn( 'ID объекта',  fn( $r ) => $r->targetId ?? '' ),
			new CsvColumn( 'IP',          fn( $r ) => $r->actorIp ),
			new CsvColumn( 'Детали',      fn( $r ) => $r->detailsJson ?? '' ),
		);

		// Генерация CSV-файла и получение ссылки для скачивания
		$csv = $this->csv->export( $rows, $columns );
		$url = $this->csv->createDownloadLink( $csv, 'audit-log-' . wp_date( 'Y-m-d' ) . '.csv' );

		$this->success( array( 'url' => $url ) );
	}

	/**
	 * Экспортирует логи доступа к персональным данным (PII) в CSV-файл.
	 *
	 * @return void
	 */
	public function ajaxExportPiiLog(): void {
		$this->authorize( Nonce::Manager, Capability::Admin );

		// Сбор фильтров из запроса
		$filters = array_filter( array(
			'actor_user_id' => $this->sanitizeInt( 'actor_id' ) ?: null,
			'person_id'     => $this->sanitizeInt( 'person_id' ) ?: null,
			'date_from'     => $this->sanitizeText( 'date_from' ),
			'date_to'       => $this->sanitizeText( 'date_to' ),
		) );

		$rows    = $this->piiLog->listAll( $filters );

		// Определение колонок для CSV
		$columns = array(
			new CsvColumn( 'ID',        fn( $r ) => $r->id ),
			new CsvColumn( 'Дата',      fn( $r ) => $r->createdAt ),
			new CsvColumn( 'User ID',   fn( $r ) => $r->actorUserId ),
			new CsvColumn( 'Роль',      fn( $r ) => $r->actorRole ?? '' ),
			new CsvColumn( 'Person ID', fn( $r ) => $r->personId ),
			new CsvColumn( 'Поля',      fn( $r ) => $r->fieldsAccessed ),
			new CsvColumn( 'Причина',   fn( $r ) => $r->accessReason ),
			new CsvColumn( 'IP',        fn( $r ) => $r->actorIp ),
		);

		$csv = $this->csv->export( $rows, $columns );
		$url = $this->csv->createDownloadLink( $csv, 'pii-log-' . wp_date( 'Y-m-d' ) . '.csv' );

		$this->success( array( 'url' => $url ) );
	}
}