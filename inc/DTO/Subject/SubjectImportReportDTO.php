<?php

declare( strict_types=1 );

namespace Inc\DTO\Subject;

/**
 * Class SubjectImportReportDTO
 *
 * Результат импорта предмета — как реального, так и dry-run.
 *
 * @package Inc\DTO\Subject
 *
 * ### Зачем один DTO на оба режима
 *
 * Предпросмотр обязан считать ровно то же, что потом создаст импорт. Общий
 * тип ответа не даёт двум веткам разъехаться: dry-run наполняет `counts`
 * и `collisions`, не трогая БД, реальный прогон — те же `counts` по факту.
 */
readonly class SubjectImportReportDTO {

	/**
	 * @param bool                 $dryRun     true — только предпросмотр, БД не изменялась
	 * @param string               $subjectKey Ключ импортируемого предмета
	 * @param string               $subjectName Название импортируемого предмета
	 * @param array<string, int>   $counts     Что будет/было создано: [раздел => количество]
	 * @param string[]             $collisions Блокирующие конфликты (импорт невозможен)
	 * @param string[]             $warnings   Некритичные замечания (импорт возможен)
	 */
	public function __construct(
		public bool   $dryRun,
		public string $subjectKey,
		public string $subjectName,
		public array  $counts = array(),
		public array  $collisions = array(),
		public array  $warnings = array(),
	) {}

	/**
	 * Импорт возможен (нет блокирующих конфликтов).
	 *
	 * @return bool
	 */
	public function isImportable(): bool {
		return array() === $this->collisions;
	}

	/**
	 * Представление для JSON-ответа AJAX.
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array(
			'dry_run'      => $this->dryRun,
			'subject_key'  => $this->subjectKey,
			'subject_name' => $this->subjectName,
			'counts'       => $this->counts,
			'collisions'   => $this->collisions,
			'warnings'     => $this->warnings,
			'importable'   => $this->isImportable(),
			'total'        => array_sum( $this->counts ),
		);
	}
}
