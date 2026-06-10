<?php

declare( strict_types=1 );

namespace Inc\Callbacks;

use Inc\Core\BaseController;
use Inc\DTO\CsvColumn;
use Inc\Enums\Capability;
use Inc\Enums\Nonce;
use Inc\Repositories\WPDBRepositories\AuditLogRepository;
use Inc\Repositories\WPDBRepositories\AuthLogRepository;
use Inc\Repositories\WPDBRepositories\ConsentChangeLogRepository;
use Inc\Repositories\WPDBRepositories\DataChangeLogRepository;
use Inc\Repositories\WPDBRepositories\DeletionLogRepository;
use Inc\Repositories\WPDBRepositories\EmailLogRepository;
use Inc\Repositories\WPDBRepositories\ExportLogRepository;
use Inc\Repositories\WPDBRepositories\PiiAccessLogRepository;
use Inc\Services\CsvExportService;
use Inc\Services\Log\ExportLogWriter;
use Inc\Services\PiiCryptoService;
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
		private readonly AuditLogRepository        $auditLog,
		private readonly PiiAccessLogRepository    $piiLog,
		private readonly ExportLogRepository       $exportLog,
		private readonly DataChangeLogRepository   $dataChangeLog,
		private readonly ConsentChangeLogRepository $consentChangeLog,
		private readonly EmailLogRepository        $emailLog,
		private readonly DeletionLogRepository     $deletionLog,
		private readonly AuthLogRepository         $authLog,
		private readonly CsvExportService          $csv,
		private readonly PiiCryptoService          $crypto,
		private readonly ExportLogWriter           $exportLogWriter,
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

		$this->exportLogWriter->record( 'audit_log', 'bulk' );
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

		$this->exportLogWriter->record( 'pii_log', 'bulk' );
		$this->success( array( 'url' => $url ) );
	}

	public function ajaxExportExportLog(): void {
		$this->authorize( Nonce::Manager, Capability::Admin );

		$filters = array_filter( array(
			'actor_user_id' => $this->sanitizeInt( 'actor_id' ) ?: null,
			'data_type'     => $this->sanitizeKey( 'data_type' ),
			'date_from'     => $this->sanitizeText( 'date_from' ),
			'date_to'       => $this->sanitizeText( 'date_to' ),
		) );

		$rows    = $this->exportLog->listAll( $filters );
		$columns = array(
			new CsvColumn( 'ID',           fn( $r ) => $r->id ),
			new CsvColumn( 'Дата',         fn( $r ) => $r->createdAt ),
			new CsvColumn( 'User ID',      fn( $r ) => $r->actorUserId ),
			new CsvColumn( 'Роль',         fn( $r ) => $r->actorRole ?? '' ),
			new CsvColumn( 'Тип данных',   fn( $r ) => $r->dataType ),
			new CsvColumn( 'Тип действия', fn( $r ) => $r->actionType ),
			new CsvColumn( 'ID целей',     fn( $r ) => $r->targetIdsJson ?? '' ),
		);

		$csv = $this->csv->export( $rows, $columns );
		$url = $this->csv->createDownloadLink( $csv, 'export-log-' . wp_date( 'Y-m-d' ) . '.csv' );
		$this->exportLogWriter->record( 'export_log', 'bulk' );
		$this->success( array( 'url' => $url ) );
	}

	public function ajaxExportDataChangeLog(): void {
		$this->authorize( Nonce::Manager, Capability::Admin );

		$filters = array_filter( array(
			'actor_user_id'    => $this->sanitizeInt( 'actor_id' ) ?: null,
			'target_person_id' => $this->sanitizeInt( 'person_id' ) ?: null,
			'date_from'        => $this->sanitizeText( 'date_from' ),
			'date_to'          => $this->sanitizeText( 'date_to' ),
		) );

		$rows    = $this->dataChangeLog->listAll( $filters );
		$columns = array(
			new CsvColumn( 'ID',              fn( $r ) => $r->id ),
			new CsvColumn( 'Дата',            fn( $r ) => $r->createdAt ),
			new CsvColumn( 'User ID',         fn( $r ) => $r->actorUserId ),
			new CsvColumn( 'Роль',            fn( $r ) => $r->actorRole ?? '' ),
			new CsvColumn( 'Person ID',       fn( $r ) => $r->targetPersonId ),
			new CsvColumn( 'Поле',            fn( $r ) => $r->fieldName ),
			new CsvColumn( 'Старое значение', fn( $r ) => $r->oldValueEnc ? $this->tryDecrypt( $r->oldValueEnc ) : '' ),
			new CsvColumn( 'Новое значение',  fn( $r ) => $r->newValueEnc ? $this->tryDecrypt( $r->newValueEnc ) : '' ),
		);

		$csv = $this->csv->export( $rows, $columns );
		$url = $this->csv->createDownloadLink( $csv, 'data-change-log-' . wp_date( 'Y-m-d' ) . '.csv' );
		$this->exportLogWriter->record( 'data_change_log', 'bulk' );
		$this->success( array( 'url' => $url ) );
	}

	public function ajaxExportConsentChangeLog(): void {
		$this->authorize( Nonce::Manager, Capability::Admin );

		$filters = array_filter( array(
			'person_id'    => $this->sanitizeInt( 'person_id' ) ?: null,
			'consent_type' => $this->sanitizeKey( 'consent_type' ),
			'date_from'    => $this->sanitizeText( 'date_from' ),
			'date_to'      => $this->sanitizeText( 'date_to' ),
		) );

		$rows    = $this->consentChangeLog->listAll( $filters );
		$columns = array(
			new CsvColumn( 'ID',           fn( $r ) => $r->id ),
			new CsvColumn( 'Дата',         fn( $r ) => $r->createdAt ),
			new CsvColumn( 'User ID',      fn( $r ) => $r->actorUserId ?? '' ),
			new CsvColumn( 'Person ID',    fn( $r ) => $r->personId ?? '' ),
			new CsvColumn( 'Тип согласия', fn( $r ) => $r->consentType ),
			new CsvColumn( 'Старый хеш',  fn( $r ) => $r->oldHash ?? '' ),
			new CsvColumn( 'Новый хеш',   fn( $r ) => $r->newHash ?? '' ),
		);

		$csv = $this->csv->export( $rows, $columns );
		$url = $this->csv->createDownloadLink( $csv, 'consent-change-log-' . wp_date( 'Y-m-d' ) . '.csv' );
		$this->exportLogWriter->record( 'consent_change_log', 'bulk' );
		$this->success( array( 'url' => $url ) );
	}

	public function ajaxExportEmailLog(): void {
		$this->authorize( Nonce::Manager, Capability::Admin );

		$filters = array_filter( array(
			'email_type'       => $this->sanitizeKey( 'email_type' ),
			'status'           => $this->sanitizeKey( 'status' ),
			'target_person_id' => $this->sanitizeInt( 'person_id' ) ?: null,
			'date_from'        => $this->sanitizeText( 'date_from' ),
			'date_to'          => $this->sanitizeText( 'date_to' ),
		) );

		$rows    = $this->emailLog->listAll( $filters );
		$columns = array(
			new CsvColumn( 'ID',         fn( $r ) => $r->id ),
			new CsvColumn( 'Дата',       fn( $r ) => $r->createdAt ),
			new CsvColumn( 'User ID',    fn( $r ) => $r->actorUserId ?? '' ),
			new CsvColumn( 'Тип письма', fn( $r ) => $r->emailType ),
			new CsvColumn( 'Person ID',  fn( $r ) => $r->targetPersonId ?? '' ),
			new CsvColumn( 'Статус',     fn( $r ) => $r->status ),
			new CsvColumn( 'Ошибка',     fn( $r ) => $r->errorMessage ?? '' ),
		);

		$csv = $this->csv->export( $rows, $columns );
		$url = $this->csv->createDownloadLink( $csv, 'email-log-' . wp_date( 'Y-m-d' ) . '.csv' );
		$this->exportLogWriter->record( 'email_log', 'bulk' );
		$this->success( array( 'url' => $url ) );
	}

	public function ajaxExportDeletionLog(): void {
		$this->authorize( Nonce::Manager, Capability::Admin );

		$filters = array_filter( array(
			'actor_user_id' => $this->sanitizeInt( 'actor_id' ) ?: null,
			'entity_type'   => $this->sanitizeKey( 'entity_type' ),
			'date_from'     => $this->sanitizeText( 'date_from' ),
			'date_to'       => $this->sanitizeText( 'date_to' ),
		) );

		$rows    = $this->deletionLog->listAll( $filters );
		$columns = array(
			new CsvColumn( 'ID',               fn( $r ) => $r->id ),
			new CsvColumn( 'Дата',             fn( $r ) => $r->createdAt ),
			new CsvColumn( 'User ID',          fn( $r ) => $r->actorUserId ),
			new CsvColumn( 'Роль',             fn( $r ) => $r->actorRole ?? '' ),
			new CsvColumn( 'Тип сущности',     fn( $r ) => $r->entityType ),
			new CsvColumn( 'ID сущности',      fn( $r ) => $r->entityId ),
			new CsvColumn( 'Каскадно удалено', fn( $r ) => $r->cascadedSummary ?? '' ),
			new CsvColumn( 'IP',               fn( $r ) => $r->actorIp ),
		);

		$csv = $this->csv->export( $rows, $columns );
		$url = $this->csv->createDownloadLink( $csv, 'deletion-log-' . wp_date( 'Y-m-d' ) . '.csv' );
		$this->exportLogWriter->record( 'deletion_log', 'bulk' );
		$this->success( array( 'url' => $url ) );
	}

	public function ajaxExportAuthLog(): void {
		$this->authorize( Nonce::Manager, Capability::Admin );

		$filters = array_filter( array(
			'action'    => $this->sanitizeKey( 'action_filter' ),
			'result'    => $this->sanitizeKey( 'result' ),
			'date_from' => $this->sanitizeText( 'date_from' ),
			'date_to'   => $this->sanitizeText( 'date_to' ),
		) );

		$rows    = $this->authLog->listAll( $filters );
		$columns = array(
			new CsvColumn( 'ID',      fn( $r ) => $r->id ),
			new CsvColumn( 'Дата',    fn( $r ) => $r->createdAt ),
			new CsvColumn( 'Логин',   fn( $r ) => $r->loginIdentifier ?? '' ),
			new CsvColumn( 'Действие', fn( $r ) => $r->action ),
			new CsvColumn( 'Результат', fn( $r ) => $r->result ),
			new CsvColumn( 'IP',      fn( $r ) => $r->actorIp ),
			new CsvColumn( 'Устройство', fn( $r ) => $r->actorUa ?? '' ),
		);

		$csv = $this->csv->export( $rows, $columns );
		$url = $this->csv->createDownloadLink( $csv, 'auth-log-' . wp_date( 'Y-m-d' ) . '.csv' );
		$this->exportLogWriter->record( 'auth_log', 'bulk' );
		$this->success( array( 'url' => $url ) );
	}

	private function tryDecrypt( string $blob ): string {
		try {
			return $this->crypto->decrypt( $blob );
		} catch ( \Throwable ) {
			return '';
		}
	}
}