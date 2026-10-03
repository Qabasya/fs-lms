<?php declare( strict_types=1 );

namespace Inc\DTO\Exam;

use Inc\Enums\Assessment\AssessmentKind;
use Inc\Enums\Exam\ExamDirection;

readonly class ExamFormatDTO {
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

	public function unitMax( int $number ): int {
		return $this->unitMaxScores[ $number ] ?? 1;
	}

	public function translate( int $primary ): ?int {
		return $this->scale[ $primary ] ?? null;
	}
}
