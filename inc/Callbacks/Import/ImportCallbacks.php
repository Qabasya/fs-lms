<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Import;

use DomainException;
use Inc\Core\BaseController;
use Inc\Enums\Access\Capability;
use Inc\Enums\Import\ImportMode;
use Inc\Enums\Wp\Nonce;
use Inc\Services\Import\ImportService;
use Inc\Services\Import\RowImporterRegistry;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;
use InvalidArgumentException;

/**
 * Class ImportCallbacks
 *
 * AJAX-обработчик импорта учеников из CSV.
 *
 * @package Inc\Callbacks
 *
 * ### Обязанности
 *
 * Только транспорт: авторизация, валидация выбранных предмета/периода и файла,
 * делегирование в {@see ImportService} и отправка отчёта. Бизнес-логики нет.
 */
class ImportCallbacks extends BaseController {

	use Authorizer;
	use Sanitizer;

	/** Максимальный размер файла импорта (5 МБ). */
	private const MAX_FILE_SIZE = 5 * 1024 * 1024;

	/**
	 * @param RowImporterRegistry $importers     Импортёр строки по режиму
	 * @param ImportService       $importService Оркестратор импорта
	 */
	public function __construct(
		private readonly RowImporterRegistry $importers,
		private readonly ImportService       $importService,
	) {
		parent::__construct();
	}

	/**
	 * Импортирует учеников из CSV в выбранные предмет и период.
	 *
	 * @return void
	 */
	public function ajaxImportStudentsCsv(): void {
		$this->authorize( Nonce::Manager, Capability::Admin );

		$subjectKey = $this->requireKey( 'subject_key', error: 'Не выбран предмет.' );
		$periodId   = $this->requireKey( 'period_id', error: 'Не выбран учебный период.' );

		$mode       = ImportMode::fromRequest( $this->sanitizeKey( 'mode' ) );
		$sendEmails = $this->sanitizeBool( 'send_emails' );

		$tmpPath = $this->validateUploadedFile();
		$dryRun  = $this->sanitizeBool( 'dry_run' );

		$importer = $this->importers->for( $mode );

		try {
			$report = $this->importService->run( $importer, $mode, $sendEmails, $subjectKey, $periodId, $tmpPath, $dryRun );
		} catch ( InvalidArgumentException | DomainException $e ) {
			$this->error( $e->getMessage() );
			return;
		}

		$this->success( $report->toArray() );
	}

	/**
	 * Валидирует загруженный CSV и возвращает путь к временному файлу.
	 *
	 * @return string Путь к tmp-файлу
	 */
	private function validateUploadedFile(): string {
		$file = $this->uploadedFile( 'file' );

		if ( null === $file || ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) !== UPLOAD_ERR_OK ) {
			$this->error( 'Файл не загружен или загружен с ошибкой.' );
		}

		$extension = strtolower( pathinfo( (string) ( $file['name'] ?? '' ), PATHINFO_EXTENSION ) );
		if ( 'csv' !== $extension ) {
			$this->error( 'Допустим только файл формата .csv.' );
		}

		if ( (int) ( $file['size'] ?? 0 ) > self::MAX_FILE_SIZE ) {
			$this->error( 'Файл слишком большой (максимум 5 МБ).' );
		}

		$tmpPath = (string) ( $file['tmp_name'] ?? '' );
		if ( '' === $tmpPath || ! is_uploaded_file( $tmpPath ) ) {
			$this->error( 'Некорректный загруженный файл.' );
		}

		return $tmpPath;
	}
}
