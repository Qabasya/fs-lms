<?php

declare( strict_types=1 );

namespace Inc\DTO\Import;

/**
 * Контекст запуска импорта одной строки CSV.
 *
 * Предмет и период выбираются администратором в UI перед импортом
 * и применяются ко всем строкам файла (в самом CSV их нет).
 * Создаётся оркестратором; для каждой строки порождается копия
 * с актуальным номером строки через withRow().
 */
readonly class ImportContextDTO {

	/**
	 * @param string $subjectKey Ключ выбранного предмета
	 * @param string $periodId   ID выбранного учебного периода
	 * @param bool   $dryRun     Режим «только проверить» — без записи в БД
	 * @param int    $actorId    WP-ID администратора, запустившего импорт
	 * @param int    $rowNumber  Номер обрабатываемой строки (1 — первая строка данных)
	 */
	public function __construct(
		public string $subjectKey,
		public string $periodId,
		public bool   $dryRun,
		public int    $actorId,
		public int    $rowNumber = 0,
	) {}

	/**
	 * Возвращает копию контекста с указанным номером строки.
	 *
	 * @param int $rowNumber Номер строки данных
	 *
	 * @return self
	 */
	public function withRow( int $rowNumber ): self {
		return new self(
			subjectKey: $this->subjectKey,
			periodId:   $this->periodId,
			dryRun:     $this->dryRun,
			actorId:    $this->actorId,
			rowNumber:  $rowNumber,
		);
	}
}
