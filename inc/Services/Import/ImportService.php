<?php

declare( strict_types=1 );

namespace Inc\Services\Import;

use DomainException;
use Inc\Contracts\LogEventDispatcherInterface;
use Inc\Contracts\RowImporterInterface;
use Inc\DTO\Import\ImportContextDTO;
use Inc\DTO\Import\ImportReportDTO;
use Inc\DTO\Log\Events\EntityChangedEvent;
use Inc\Enums\Import\ImportMode;
use Inc\Enums\Log\EntityType;
use Inc\Enums\Log\LogEvent;
use Inc\Enums\Log\OperationType;
use InvalidArgumentException;
use RuntimeException;

/**
 * Class ImportService
 *
 * Оркестратор импорта CSV: фиксированный скелет, не знающий о доменных полях.
 *
 * ### Скелет run()
 *
 * 1. Разбор файла генератором ({@see CsvParseService::parse}).
 * 2. Валидация заголовков по {@see RowImporterInterface::requiredHeaders} —
 *    ошибка файла прерывает весь импорт (исключение наружу).
 * 3. Каждая строка обрабатывается независимо: одна битая строка
 *    (`InvalidArgumentException`/`DomainException`/`RuntimeException` —
 *    последняя, например, коллизия логина при создании учётки) попадает
 *    в отчёт со своим номером и не валит файл. Транзакцией записи владеет
 *    сам импортёр — у enrolled-режима провизия WP-учёток идёт после COMMIT.
 * 4. Возврат {@see ImportReportDTO} (created/skipped/errors + креды
 *    созданных учёток в режиме Enrolled).
 *
 * Импортёр строки выбирается вызывающим кодом по режиму
 * ({@see \Inc\Callbacks\Import\ImportCallbacks}); предмет и период
 * выбираются в UI и передаются в run() (в CSV их нет).
 */
readonly class ImportService {

	/**
	 * @param CsvParseService             $parser    Чтение/нормализация CSV
	 * @param LogEventDispatcherInterface $logEvents Шина событий (сводка импорта)
	 */
	public function __construct(
		private CsvParseService             $parser,
		private LogEventDispatcherInterface $logEvents,
	) {}

	/**
	 * Запускает импорт файла в выбранные предмет и период.
	 *
	 * @param RowImporterInterface $importer   Импортёр строки (архив/зачисление)
	 * @param ImportMode           $mode       Режим импорта
	 * @param bool                 $sendEmails Отправлять письма с кредами (режим Enrolled)
	 * @param string               $subjectKey Ключ предмета (выбран в UI)
	 * @param string               $periodId   ID учебного периода (выбран в UI)
	 * @param string               $filePath   Путь к загруженному CSV
	 * @param bool                 $dryRun     true — только проверить, без записи
	 *
	 * @return ImportReportDTO
	 *
	 * @throws InvalidArgumentException Пустой файл или нехватка обязательных колонок
	 */
	public function run(
		RowImporterInterface $importer,
		ImportMode           $mode,
		bool                 $sendEmails,
		string               $subjectKey,
		string               $periodId,
		string               $filePath,
		bool                 $dryRun = false
	): ImportReportDTO {
		$report = new ImportReportDTO( $dryRun );
		$ctx    = new ImportContextDTO( $subjectKey, $periodId, $dryRun, get_current_user_id() ?: 0, 0, $mode, $sendEmails );

		$generator = $this->parser->parse( $filePath );

		if ( ! $generator->valid() ) {
			throw new InvalidArgumentException( 'Файл пуст или не содержит строк данных.' );
		}

		$this->parser->validateHeaders(
			$importer->requiredHeaders(),
			array_keys( $generator->current() )
		);

		$rowNumber = 0;
		while ( $generator->valid() ) {
			$row = $generator->current();
			++$rowNumber;

			try {
				$report->addResult( $importer->import( $row, $ctx->withRow( $rowNumber ) ) );
			} catch ( InvalidArgumentException | DomainException | RuntimeException $e ) {
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
						'%s: создано %d, пропущено %d, ошибок %d (предмет «%s», период «%s»)',
						ImportMode::Enrolled === $mode ? 'Зачисление учеников' : 'Импорт архивных записей',
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
