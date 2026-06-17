<?php

declare( strict_types=1 );

namespace Inc\DTO\Assessment;

readonly class AttemptAnswerDTO {

	public function __construct(
		public int     $id,
		public int     $attemptId,
		public int     $taskId,
		public ?string $answerText,
		public ?bool   $isCorrect,
		public ?float  $score,
		public ?float  $maxScore,
		public ?int    $gradedByUserId,
		public ?string $gradedAt,
	) {}

	public static function fromArray( array $row ): self {
		return new self(
			id              : (int) $row['id'],
			attemptId       : (int) $row['attempt_id'],
			taskId          : (int) $row['task_id'],
			answerText      : $row['answer_text'] ?? null,
			isCorrect       : isset( $row['is_correct'] ) ? (bool) $row['is_correct'] : null,
			score           : isset( $row['score'] ) ? (float) $row['score'] : null,
			maxScore        : isset( $row['max_score'] ) ? (float) $row['max_score'] : null,
			gradedByUserId  : isset( $row['graded_by_user_id'] ) ? (int) $row['graded_by_user_id'] : null,
			gradedAt        : $row['graded_at'] ?? null,
		);
	}
}
