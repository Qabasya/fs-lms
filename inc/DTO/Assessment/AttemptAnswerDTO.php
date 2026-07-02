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
		public ?string $graderNote = null,
		/** Эпик 13 (D17): {индекс критерия => начисленные баллы}; null — критериев нет / не оценивалось по ним. */
		public ?array  $criteriaScores = null,
	) {}

	public static function fromArray( array $row ): self {
		$criteriaRaw = $row['criteria_scores'] ?? null;
		$criteria    = null;
		if ( is_string( $criteriaRaw ) && '' !== $criteriaRaw ) {
			$decoded  = json_decode( $criteriaRaw, true );
			$criteria = is_array( $decoded ) ? $decoded : null;
		} elseif ( is_array( $criteriaRaw ) ) {
			$criteria = $criteriaRaw;
		}

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
			graderNote      : $row['grader_note'] ?? null,
			criteriaScores  : $criteria,
		);
	}
}
