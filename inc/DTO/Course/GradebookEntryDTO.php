<?php

declare( strict_types=1 );

namespace Inc\DTO\Course;

use Inc\Enums\Course\GradeBadge;

/**
 * Class GradebookEntryDTO
 *
 * Одна ячейка журнала оценок.
 * displayType определяет способ отображения:
 *   'fraction' — работа: «5/8» (correctCount/totalCount)
 *   'score'    — экзамен: числовой балл (для ЕГЭ — вторичный)
 *   'pending'  — ожидает ручной проверки
 *
 * `groupLessonId`/`badge` (Эпик 10, T10.0b) — привязка к занятию + короткая метка
 * типа (СР/ПР/ДЗ/КР/ЭКЗ) для inline-результатов в ячейке журнала.
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
		public ?int    $groupLessonId = null,
		public ?GradeBadge $badge = null,
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
