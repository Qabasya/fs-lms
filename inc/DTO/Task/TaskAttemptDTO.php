<?php

declare( strict_types=1 );

namespace Inc\DTO\Task;

/**
 * Class TaskAttemptDTO
 *
 * Одна попытка ответа ученика на задание в шаге урока (Этап 6).
 * Хранится в `fs_lms_task_attempts`.
 *
 * @package Inc\DTO\Task
 */
readonly class TaskAttemptDTO {

	/**
	 * @param int        $id
	 * @param int        $studentPersonId
	 * @param int        $groupLessonId
	 * @param string     $stepKey
	 * @param int        $taskId
	 * @param int        $attemptNumber
	 * @param mixed      $answer          Декодированный ответ (строка, массив) или null.
	 * @param bool|null  $isCorrect       null = не проверено (ручная проверка).
	 * @param float|null $score
	 * @param float|null $maxScore
	 * @param array|null $itemFeedback    Подробная обратная связь по позициям/пунктам.
	 * @param string     $createdAt
	 */
	public function __construct(
		public int     $id,
		public int     $studentPersonId,
		public int     $groupLessonId,
		public string  $stepKey,
		public int     $taskId,
		public int     $attemptNumber,
		public mixed   $answer,
		public ?bool   $isCorrect,
		public ?float  $score,
		public ?float  $maxScore,
		public ?array  $itemFeedback,
		public string  $createdAt,
	) {}

	public static function fromArray( array $row ): self {
		return new self(
			id             : (int) $row['id'],
			studentPersonId: (int) $row['student_person_id'],
			groupLessonId  : (int) $row['group_lesson_id'],
			stepKey        : (string) $row['step_key'],
			taskId         : (int) $row['task_id'],
			attemptNumber  : (int) $row['attempt_number'],
			answer         : isset( $row['answer'] ) ? json_decode( (string) $row['answer'], true ) : null,
			isCorrect      : isset( $row['is_correct'] ) ? (bool) (int) $row['is_correct'] : null,
			score          : isset( $row['score'] ) ? (float) $row['score'] : null,
			maxScore       : isset( $row['max_score'] ) ? (float) $row['max_score'] : null,
			itemFeedback   : isset( $row['item_feedback'] ) ? json_decode( (string) $row['item_feedback'], true ) : null,
			createdAt      : (string) $row['created_at'],
		);
	}
}
