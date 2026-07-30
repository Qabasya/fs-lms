<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Log;

use Inc\Core\BaseController;
use Inc\Enums\Access\Capability;
use Inc\Enums\Export\ExportTarget;
use Inc\Enums\Wp\Nonce;
use Inc\Services\Export\ExportService;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;

/**
 * Class LogsCallbacks
 *
 * AJAX-обработчики для экспорта данных и журналов.
 *
 * @package Inc\Callbacks
 *
 * ### Основные обязанности:
 *
 * 1. **Экспорт справочных данных** — групп, студентов, родителей, архива.
 * 2. **Экспорт системных журналов** — аудит, зачисления, PII-доступ, email, аутентификация и др.
 *
 * ### Архитектурная роль:
 *
 * Делегирует генерацию файлов ExportService.
 * Использует enum ExportTarget для указания типа экспорта.
 * Все методы доступны только администраторам (Capability::ManageLmsPlatform).
 *
 * ### Права на PII-экспорт (A1)
 *
 * Датасеты `students` / `parents` / `archive` содержат персональные данные
 * (ФИО, email, телефон, у первых двух — ещё и учётные данные), поэтому
 * требуют **двух** прав сразу: `ManageLmsPlatform` (доступ к разделу) +
 * `ExportPII` (право выгружать ПД) — {@see Authorizer::authorizeAll()}.
 * Тот же стандарт действует в `ExpulsionCallbacks::ajaxExportExpelledRecord`.
 */
class LogsCallbacks extends BaseController {

	use Authorizer;  // Трейт с методами authorize()
	use Sanitizer;   // Трейт с методами sanitizeInt(), sanitizeKey(), sanitizeText()

	/**
	 * Права, необходимые для выгрузки персональных данных.
	 *
	 * @var Capability[]
	 */
	private const PII_EXPORT_CAPS = array( Capability::ManageLmsPlatform, Capability::ExportPII );

	/**
	 * Конструктор коллбеков.
	 *
	 * @param ExportService $exportService Сервис экспорта данных
	 */
	public function __construct(
		private readonly ExportService $exportService,
	) {
		parent::__construct();
	}

	// ==================== Экспорт справочных данных ====================

	/**
	 * Экспорт групп (CSV).
	 */
	public function ajaxExportGroups(): void {
		$this->authorize( Nonce::Manager, Capability::ManageLmsPlatform );
		$ids = array_filter( array_map( 'intval', (array) ( $_POST['ids'] ?? array() ) ) );
		$url = $this->exportService->run( ExportTarget::Groups, $ids ? array( 'ids' => $ids ) : array() );
		$this->success( array( 'url' => $url ) );
	}

	/**
	 * Экспорт студентов (CSV). Поддерживает единичный и массовый режим.
	 *
	 * Файл содержит ПД и (если не снят чекбокс) пароль в открытом виде —
	 * отсюда двойная проверка прав и флаг `include_passwords` (A2).
	 */
	public function ajaxExportStudents(): void {
		$this->authorizeAll( Nonce::Manager, self::PII_EXPORT_CAPS );
		$ids  = array_filter( array_map( 'intval', (array) ( $_POST['ids'] ?? array() ) ) );
		$mode = $ids ? 'single' : 'bulk';
		$url  = $this->exportService->run( ExportTarget::Students, $this->piiContext( $ids ), $mode );
		$this->success( array( 'url' => $url ) );
	}

	/**
	 * Экспорт родителей (CSV). Поддерживает единичный и массовый режим.
	 */
	public function ajaxExportParents(): void {
		$this->authorizeAll( Nonce::Manager, self::PII_EXPORT_CAPS );
		$ids  = array_filter( array_map( 'intval', (array) ( $_POST['ids'] ?? array() ) ) );
		$mode = $ids ? 'single' : 'bulk';
		$url  = $this->exportService->run( ExportTarget::Parents, $this->piiContext( $ids ), $mode );
		$this->success( array( 'url' => $url ) );
	}

	/**
	 * Экспорт архивных записей (отчисленные студенты, CSV).
	 */
	public function ajaxExportArchive(): void {
		$this->authorizeAll( Nonce::Manager, self::PII_EXPORT_CAPS );
		$ids = array_filter( array_map( 'intval', (array) ( $_POST['ids'] ?? array() ) ) );
		$url = $this->exportService->run( ExportTarget::Archive, $ids ? array( 'ids' => $ids ) : array() );
		$this->success( array( 'url' => $url ) );
	}

	/**
	 * Контекст доменного PII-экспорта: выбранные ID + режим выгрузки паролей.
	 *
	 * `include_passwords` по умолчанию **выключен**: пароли попадают в файл
	 * только по явному запросу из UI (A2), а не «за компанию» с контактами.
	 *
	 * @param int[] $ids Выбранные ID лиц (пусто — весь датасет)
	 *
	 * @return array<string, mixed>
	 */
	private function piiContext( array $ids ): array {
		$context = array( 'include_passwords' => $this->sanitizeBool( 'include_passwords' ) );

		if ( $ids ) {
			$context['ids'] = $ids;
		}

		return $context;
	}

	// ==================== Экспорт системных журналов ====================

	/**
	 * Экспорт журнала аудита изменений сущностей (entity_audit).
	 */
	public function ajaxExportEntityAuditLog(): void {
		$this->authorize( Nonce::Manager, Capability::ManageLmsPlatform );
		$filters = $this->logFilters( array( 'operation', 'entity_type', 'actor_user_id', 'date_from', 'date_to' ) );
		$url     = $this->exportService->run( ExportTarget::LogEntityAudit, $filters );
		$this->success( array( 'url' => $url ) );
	}

	/**
	 * Экспорт журнала зачислений (enrollment_log).
	 */
	public function ajaxExportEnrollmentLog(): void {
		$this->authorize( Nonce::Manager, Capability::ManageLmsPlatform );
		$filters = $this->logFilters( array( 'action_filter', 'actor_user_id', 'date_from', 'date_to' ) );
		$url     = $this->exportService->run( ExportTarget::LogEnrollment, $filters );
		$this->success( array( 'url' => $url ) );
	}

	/**
	 * Экспорт журнала доступа к персональным данным (pii_access_log).
	 */
	public function ajaxExportPiiLog(): void {
		$this->authorize( Nonce::Manager, Capability::ManageLmsPlatform );
		$filters = $this->logFilters( array( 'actor_user_id', 'person_id', 'date_from', 'date_to' ) );
		$url     = $this->exportService->run( ExportTarget::LogPiiAccess, $filters );
		$this->success( array( 'url' => $url ) );
	}

	/**
	 * Экспорт журнала экспорта данных (export_log).
	 */
	public function ajaxExportExportLog(): void {
		$this->authorize( Nonce::Manager, Capability::ManageLmsPlatform );
		$filters = $this->logFilters( array( 'actor_user_id', 'data_type', 'date_from', 'date_to' ) );
		$url     = $this->exportService->run( ExportTarget::LogExport, $filters );
		$this->success( array( 'url' => $url ) );
	}

	/**
	 * Экспорт журнала изменений данных (data_change_log).
	 */
	public function ajaxExportDataChangeLog(): void {
		$this->authorize( Nonce::Manager, Capability::ManageLmsPlatform );
		$filters = $this->logFilters( array( 'actor_user_id', 'person_id', 'date_from', 'date_to' ) );
		$url     = $this->exportService->run( ExportTarget::LogDataChange, $filters );
		$this->success( array( 'url' => $url ) );
	}

	/**
	 * Экспорт журнала изменений согласий (consent_change_log).
	 */
	public function ajaxExportConsentChangeLog(): void {
		$this->authorize( Nonce::Manager, Capability::ManageLmsPlatform );
		$filters = $this->logFilters( array( 'person_id', 'consent_type', 'date_from', 'date_to' ) );
		$url     = $this->exportService->run( ExportTarget::LogConsentChange, $filters );
		$this->success( array( 'url' => $url ) );
	}

	/**
	 * Экспорт журнала отправки email (email_log).
	 */
	public function ajaxExportEmailLog(): void {
		$this->authorize( Nonce::Manager, Capability::ManageLmsPlatform );
		$filters = $this->logFilters( array( 'email_type', 'status', 'person_id', 'date_from', 'date_to' ) );
		$url     = $this->exportService->run( ExportTarget::LogEmail, $filters );
		$this->success( array( 'url' => $url ) );
	}

	/**
	 * Экспорт журнала аутентификации (auth_log).
	 */
	public function ajaxExportAuthLog(): void {
		$this->authorize( Nonce::Manager, Capability::ManageLmsPlatform );
		$filters = $this->logFilters( array( 'action_filter', 'result', 'date_from', 'date_to' ) );
		$url     = $this->exportService->run( ExportTarget::LogAuth, $filters );
		$this->success( array( 'url' => $url ) );
	}

	/**
	 * Собирает ненулевые фильтры из $_POST по списку ключей.
	 *
	 * @param string[] $keys Список ключей для извлечения
	 *
	 * @return array<string, mixed>
	 */
	private function logFilters( array $keys ): array {
		// Маппинг ключей запроса к методам санитизации
		$map = array(
			'action_filter' => fn() => $this->sanitizeKey( 'action_filter' ),
			'operation'     => fn() => $this->sanitizeKey( 'operation' ),
			'entity_type'   => fn() => $this->sanitizeKey( 'entity_type' ),
			'actor_user_id' => fn() => $this->sanitizeInt( 'actor_id' ) ?: null,
			'person_id'     => fn() => $this->sanitizeInt( 'person_id' ) ?: null,
			'data_type'     => fn() => $this->sanitizeKey( 'data_type' ),
			'consent_type'  => fn() => $this->sanitizeKey( 'consent_type' ),
			'email_type'    => fn() => $this->sanitizeKey( 'email_type' ),
			'status'        => fn() => $this->sanitizeKey( 'status' ),
			'result'        => fn() => $this->sanitizeKey( 'result' ),
			'date_from'     => fn() => $this->sanitizeText( 'date_from' ),
			'date_to'       => fn() => $this->sanitizeText( 'date_to' ),
		);

		$filters = array();
		foreach ( $keys as $key ) {
			if ( isset( $map[ $key ] ) ) {
				$value = ( $map[ $key ] )();
				if ( $value !== null && $value !== '' ) {
					$filters[ $key ] = $value;
				}
			}
		}
		return $filters;
	}
}