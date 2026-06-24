<?php

declare( strict_types=1 );

namespace Inc\DTO\Course;

/**
 * Class GradebookEntryDTO
 *
 * Одна ячейка журнала оценок.
 * displayType определяет способ отображения:
 *   'fraction' — работа: «5/8» (correctCount/totalCount)
 *   'score'    — экзамен: числовой балл (для ЕГЭ — вторичный)
 *   'pending'  — ожидает ручной проверки
 *
 * @package Inc\DTO\Course
 */
readonly class GradebookEntryDTO {

	public function __construct(
		public int     $studentPersonId,
		public int     $groupId,
		public string  $sourceType,
		public int     $sourceId,
		public string  $title,
		public string  $category,
		public ?float  $score,
		public ?float  $maxScore,
		public ?string $gradedAt,
		/** 'fraction' | 'score' | 'pending' */
		public string  $displayType = 'score',
	) {}

	/** Форматированное значение для отображения в журнале. */
	public function displayValue(): string {
		return match ( $this->displayType ) {
			'fraction' => (int) ( $this->score ?? 0 ) . '/' . (int) ( $this->maxScore ?? 0 ),
			'pending'  => 'На проверке',
			default    => null !== $this->score ? (string) (int) $this->score : '—',
		};
	}
}
