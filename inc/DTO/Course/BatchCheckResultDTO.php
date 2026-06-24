<?php

declare( strict_types=1 );

namespace Inc\DTO\Course;

/**
 * Class BatchCheckResultDTO
 *
 * Результат пакетной авто-проверки работы.
 *
 * @package Inc\DTO\Course
 */
readonly class BatchCheckResultDTO {

	public function __construct(
		/**
		 * Per-task results.
		 * Формат: task_id => ['verdict' => 'correct'|'incorrect'|'pending', 'score' => float, 'maxScore' => float]
		 *
		 * @var array<int, array{verdict: string, score: float, maxScore: float}>
		 */
		public array $perTask,
		public int   $correctCount,
		public int   $totalCount,
		public float $weightedScore,
		public float $maxWeightedScore,
		public bool  $hasManual,
	) {}

	/** Итог для отображения: «5/8». */
	public function tally(): string {
		return "{$this->correctCount}/{$this->totalCount}";
	}
}
