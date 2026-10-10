<?php
/**
 * Шкала официального экзамена: число единиц, первичные баллы и перевод результата.
 *
 * @package FS_LMS
 */

declare( strict_types=1 );

namespace Inc\DTO\Exam;

use Inc\Enums\Assessment\AssessmentKind;
use Inc\Enums\Exam\ExamDirection;

/** Шкала официального экзамена: число единиц, первичные баллы и перевод результата. */
readonly class ExamFormatDTO {
	/** Принимает типизированные поля без обращения к хранилищу. */
	public function __construct(
		public AssessmentKind $kind,
		public ExamDirection $direction,
		public int $unitCount,
		public int $primaryMax,
		public ?int $secondaryMax,
		public int $gradeMax,
		public int $durationMinutes,
		public array $scale,
		public array $unitMaxScores,
	) {}

	/** Максимум первичных баллов единицы; без отдельного правила — один балл. */
	public function unitMax( int $number ): int {
		return $this->unitMaxScores[ $number ] ?? 1;
	}

	/** Переводит первичный балл по шкале; без соответствия возвращает null. */
	public function translate( int $primary ): ?int {
		return $this->scale[ $primary ] ?? null;
	}
}
