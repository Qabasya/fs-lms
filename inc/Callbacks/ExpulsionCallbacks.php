<?php

declare( strict_types=1 );

namespace Inc\Callbacks;

use Inc\Core\BaseController;
use Inc\DTO\CsvColumn;
use Inc\Enums\AuditAction;
use Inc\Enums\Capability;
use Inc\Enums\ExpulsionReasons;
use Inc\Enums\Nonce;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Services\AuditService;
use Inc\Services\CsvExportService;
use Inc\Services\ExpulsionService;
use Inc\Shared\Traits\AjaxResponse;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;
use RuntimeException;

/**
 * Class ExpulsionCallbacks
 *
 * AJAX-обработчики для отчисления студентов и экспорта записей об отчислении.
 *
 * @package Inc\Callbacks
 *
 * ### Основные обязанности:
 *
 * 1. **Отчисление студента** — проверка причины, вызов сервиса отчисления.
 * 2. **Экспорт записи об отчислении** — создание CSV-файла с данными зачисления и отчисления.
 *
 * ### Архитектурная роль:
 *
 * Делегирует бизнес-логику ExpulsionService, CsvExportService и AuditService.
 * Использует репозиторий StudentRecordRepository для чтения записей перед экспортом.
 * Логирует факт экспорта архива отчисления через AuditService.
 */
class ExpulsionCallbacks extends BaseController {

	use Authorizer;      // Трейт с методами authorize(), requireInt()
	use AjaxResponse;    // Трейт с методами success(), error()
	use Sanitizer;       // Трейт с методами sanitizeText()

	/**
	 * Конструктор коллбеков.
	 *
	 * @param ExpulsionService       $expulsionService       Сервис отчисления студентов
	 * @param StudentRecordRepository $studentRecordRepository Репозиторий записей студентов
	 * @param CsvExportService       $csvExportService       Сервис экспорта в CSV
	 * @param AuditService           $auditService           Сервис аудита
	 */
	public function __construct(
		private readonly ExpulsionService       $expulsionService,
		private readonly StudentRecordRepository $studentRecordRepository,
		private readonly CsvExportService       $csvExportService,
		private readonly AuditService           $auditService,
	) {
		parent::__construct();
	}

	/**
	 * Отчисляет студента (меняет статус зачисления на Expelled).
	 *
	 * @return void
	 */
	public function ajaxExpelStudent(): void {
		$this->authorize( Nonce::Expulsion, Capability::ManagePersons );

		$studentId = $this->requireInt( 'student_id', error: 'Не указан ID студента.' );

		$reason = $this->sanitizeText( 'reason' );

		if ( '' === $reason ) {
			$this->error( 'Не указана причина отчисления.' );
			return;
		}

		// str_starts_with() — проверяет начало строки (для "other: текст")
		$isOtherReason = str_starts_with(
			$reason,
			ExpulsionReasons::Other->value . ':'
		);

		// Проверка, что причина входит в список допустимых
		$isValidReason = in_array(
			$reason,
			ExpulsionReasons::values(),
			true
		);

		if ( ! $isValidReason && ! $isOtherReason ) {
			$this->error( 'Недопустимая причина отчисления.' );
			return;
		}

		try {
			$record = $this->expulsionService->expel( $studentId, $reason );
			$this->success( array( 'archive_id' => $record->id ) );
		} catch ( RuntimeException $e ) {
			$this->error( $e->getMessage() );
		}
	}

	/**
	 * Экспортирует запись об отчислении в CSV-файл.
	 * Содержит данные о зачислении и отчислении.
	 *
	 * @return void
	 */
	public function ajaxExportExpelledRecord(): void {
		$this->authorize( Nonce::Expulsion, Capability::ExportPII );

		$recordId = $this->requireInt( 'archive_id', error: 'Не указан ID записи.' );

		// Поиск записи студента
		$record = $this->studentRecordRepository->find( $recordId );
		if ( null === $record ) {
			$this->error( 'Запись не найдена.' );
			return;
		}

		// Формирование строки данных для CSV TODO: в DTO
		$row = array(
			'contract_no'   => $record->contractNo ?? '',
			'contract_date' => $record->contractDate ?? '',
			'order_no'      => $record->orderNo ?? '',
			'order_date'    => $record->orderDate ?? '',
			'group_id'      => $record->groupId ?? 0,
			'enrolled_at'   => $record->enrolledAt,
			'expelled_at'   => $record->expelledAt ?? '',
			'expel_reason'  => $record->expelReason ?? '',
		);

		// Определение колонок для CSV
		$columns = array(
			new CsvColumn( '№ договора',         fn( $r ) => $r['contract_no'] ),
			new CsvColumn( 'Дата договора',      fn( $r ) => $r['contract_date'] ),
			new CsvColumn( '№ приказа',          fn( $r ) => $r['order_no'] ),
			new CsvColumn( 'Дата приказа',       fn( $r ) => $r['order_date'] ),
			new CsvColumn( 'Группа',             fn( $r ) => $r['group_id'] ),
			new CsvColumn( 'Зачислен',           fn( $r ) => $r['enrolled_at'] ),
			new CsvColumn( 'Дата отчисления',    fn( $r ) => $r['expelled_at'] ),
			new CsvColumn( 'Причина отчисления', fn( $r ) => $r['expel_reason'] ),
		);

		// Генерация CSV-файла
		$csv      = $this->csvExportService->export( array( $row ), $columns );
		$filename = sprintf( 'expelled_%d_%s.csv', $recordId, date( 'Y-m-d' ) );
		$url      = $this->csvExportService->createDownloadLink( $csv, $filename );

		// Логирование экспорта в аудит
		$this->auditService->record(
			action:     AuditAction::ExpelledArchiveExported->value,
			targetType: 'student_record',
			targetId:   $recordId,
		);

		$this->success( array( 'download_url' => $url ) );
	}
}