<?php

declare( strict_types=1 );

namespace Inc\DTO\Import;

/**
 * Сводный отчёт об импорте файла.
 *
 * Накопитель: оркестратор (ImportService) инкрементирует счётчики
 * по результату каждой строки и собирает ошибки с их номерами строк.
 * Не readonly — изменяется в ходе цикла обработки. toArray() отдаётся
 * в AJAX-ответ для рендера на клиенте.
 */
class ImportReportDTO {

	/** @var int Количество созданных записей */
	public int $created = 0;

	/** @var int Количество пропущенных строк (дубли) */
	public int $skipped = 0;

	/** @var array<int, string> Ошибки в формате [номер строки => сообщение] */
	public array $errors = array();

	/**
	 * @param bool $dryRun Режим «только проверить» (без записи в БД)
	 */
	public function __construct(
		public readonly bool $dryRun = false,
	) {}

	/**
	 * Учитывает результат успешно обработанной строки.
	 *
	 * @param ImportRowResultDTO $result Результат строки
	 *
	 * @return void
	 */
	public function addResult( ImportRowResultDTO $result ): void {
		if ( $result->isCreated() ) {
			++$this->created;
		} else {
			++$this->skipped;
		}
	}

	/**
	 * Фиксирует ошибку строки.
	 *
	 * @param int    $rowNumber Номер строки данных
	 * @param string $message   Текст ошибки
	 *
	 * @return void
	 */
	public function addError( int $rowNumber, string $message ): void {
		$this->errors[ $rowNumber ] = $message;
	}

	/**
	 * Представление для AJAX-ответа.
	 *
	 * @return array{created:int, skipped:int, errors:array<int,string>, dry_run:bool}
	 */
	public function toArray(): array {
		return array(
			'created' => $this->created,
			'skipped' => $this->skipped,
			'errors'  => $this->errors,
			'dry_run' => $this->dryRun,
		);
	}
}
