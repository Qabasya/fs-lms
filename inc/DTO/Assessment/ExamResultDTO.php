<?php

declare( strict_types=1 );

namespace Inc\DTO\Assessment;

use Inc\Enums\Assessment\AssessmentKind;

/**
 * Class ExamResultDTO
 *
 * Результат экзамена для отображения ученику.
 * Никогда не содержит эталонных ответов, кода решений или объяснений.
 *
 * @package Inc\DTO\Assessment
 */
readonly class ExamResultDTO {

	public function __construct(
		public int            $attemptId,
		public AssessmentKind $kind,
		public int            $correctCount,
		public int            $totalCount,
		public float          $primaryScore,
		public float          $maxPrimaryScore,
		public ?int           $secondaryScore,
		public bool           $passed,
		public ?int           $actualDurationSeconds,
		/**
		 * Per-task verdicts without correct answers.
		 * task_id => ['verdict' => 'correct'|'incorrect'|'pending']
		 *
		 * @var array<int, array{verdict: string}>
		 */
		public array          $perTask,
	) {}

	/** Итог для работы/контрольной: «5/8». */
	public function tally(): string {
		return "{$this->correctCount}/{$this->totalCount}";
	}
}
