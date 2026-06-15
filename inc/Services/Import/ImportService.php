<?php

declare( strict_types=1 );

namespace Inc\Services\Import;

use DomainException;
use Inc\Contracts\LogEventDispatcherInterface;
use Inc\DTO\Import\ImportContextDTO;
use Inc\DTO\Import\ImportReportDTO;
use Inc\DTO\Log\Events\EntityChangedEvent;
use Inc\Enums\EntityType;
use Inc\Enums\LogEvent;
use Inc\Enums\OperationType;
use Inc\Shared\Traits\TransactionRunner;
use InvalidArgumentException;

/**
 * Class ImportService
 *
 * Оркестратор импорта CSV: фиксированный скелет, не знающий о доменных полях.
 *
 * ### Скелет run()
 *
 * 1. Разбор файла генератором ({@see CsvParseService::parse}).
 * 2. Валидация заголовков по {@see StudentRowImporter::requiredHeaders} —
 *    ошибка файла прерывает весь импорт (исключение наружу).
 * 3. Каждая строка обрабатывается в собственной транзакции: одна битая строка
 *    (`InvalidArgumentException`/`DomainException`) попадает в отчёт со своим
 *    номером и не валит файл.
 * 4. Возврат {@see ImportReportDTO} (created/skipped/errors).
 *
 * Предмет и период выбираются в UI и передаются в run() (в CSV их нет).
 */
readonly class ImportService {

	use TransactionRunner;

	/**
	 * @param CsvParseService             $parser    Чтение/нормализация CSV
	 * @param StudentRowImporter          $importer  Импорт одной строки
	 * @param LogEventDispatcherInterface $logEvents Шина событий (сводка импорта — Шаг 7)
	 */
	public function __construct(
		private CsvParseService             $parser,
		private StudentRowImporter          $importer,
		private LogEventDispatcherInterface $logEvents,
	) {}

	/**
	 * Запускает импорт файла в выбранные предмет и период.
	 *
	 * @param string $subjectKey Ключ предмета (выбран в UI)
	 * @param string $periodId   ID учебного периода (выбран в UI)
	 * @param string $filePath   Путь к загруженному CSV
	 * @param bool   $dryRun     true — только проверить, без записи
	 *
	 * @return ImportReportDTO
	 *
	 * @throws InvalidArgumentException Пустой файл или нехватка обязательных колонок
	 */
	public function run( string $subjectKey, string $periodId, string $filePath, bool $dryRun = false ): ImportReportDTO {
		$report = new ImportReportDTO( $dryRun );
		$ctx    = new ImportContextDTO( $subjectKey, $periodId, $dryRun, get_current_user_id() ?: 0 );

		$generator = $this->parser->parse( $filePath );

		if ( ! $generator->valid() ) {
			throw new InvalidArgumentException( 'Файл пуст или не содержит строк данных.' );
		}

		$this->parser->validateHeaders(
			$this->importer->requiredHeaders(),
			array_keys( $generator->current() )
		);

		$rowNumber = 0;
		while ( $generator->valid() ) {
			$row = $generator->current();
			++$rowNumber;

			try {
				$result = $this->inTransaction(
					fn() => $this->importer->import( $row, $ctx->withRow( $rowNumber ) )
				);
				$report->addResult( $result );
			} catch ( InvalidArgumentException | DomainException $e ) {
				$report->addError( $rowNumber, $e->getMessage() );
			}

			$generator->next();
		}

		// Сводное событие импорта (dry-run не логируется)
		if ( ! $dryRun ) {
			$this->logEvents->dispatch(
				LogEvent::CsvImported,
				new EntityChangedEvent(
					$ctx->actorId,
					OperationType::Import,
					EntityType::Student,
					$subjectKey,
					sprintf(
						'Импорт учеников: создано %d, пропущено %d, ошибок %d (предмет «%s», период «%s»)',
						$report->created,
						$report->skipped,
						count( $report->errors ),
						$subjectKey,
						$periodId
					)
				)
			);
		}

		return $report;
	}
}
