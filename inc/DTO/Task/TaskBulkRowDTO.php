<?php

declare( strict_types=1 );

namespace Inc\DTO\Task;

/**
 * Строка CSV массовой правки заданий (после разбора, до проверки).
 *
 * Пустое поле означает «не менять». В `$terms` ключ — слаг таксономии, значение —
 * названия термов; пустой массив (ячейка `-`) снимает все термы таксономии.
 */
readonly class TaskBulkRowDTO {

	/**
	 * @param int                      $line       Номер строки в файле (с учётом заголовка), для отчёта
	 * @param string                   $oldNumber  Текущий номер (слаг) задания
	 * @param string                   $newNumber  Новый номер; '' — не менять
	 * @param string                   $title      Название без префикса «№ N. »; '' — не менять
	 * @param string                   $date       Дата публикации «Y-m-d H:i»; '' — не менять
	 * @param string                   $taskNumber Номер задания (терм номера) для смены серии; '' — оставить текущий
	 * @param array<string, string[]>  $terms      Таксономия → названия термов
	 */
	public function __construct(
		public int $line,
		public string $oldNumber,
		public string $newNumber = '',
		public string $title = '',
		public string $date = '',
		public string $taskNumber = '',
		public array $terms = array(),
	) {}
}
