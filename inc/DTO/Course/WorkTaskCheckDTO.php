<?php

declare( strict_types=1 );

namespace Inc\DTO\Course;

/**
 * Class WorkTaskCheckDTO
 *
 * Одна проверка ответа кнопкой «Проверить ответ» внутри работы (до её сдачи).
 * Хранится в `fs_lms_work_task_checks`.
 *
 * @package Inc\DTO\Course
 */
readonly class WorkTaskCheckDTO {

	/**
	 * @param int   $id
	 * @param int   $taskId
	 * @param int   $round     Номер сдачи работы, в рамках которой шла проверка
	 * @param mixed $answer    Декодированный ответ, который проверяли
	 * @param bool  $isCorrect
	 */
	public function __construct(
		public int   $id,
		public int   $taskId,
		public int   $round,
		public mixed $answer,
		public bool  $isCorrect,
	) {}

	/**
	 * @param array<string, mixed> $row
	 */
	public static function fromArray( array $row ): self {
		return new self(
			id       : (int) $row['id'],
			taskId   : (int) $row['task_id'],
			round    : (int) $row['round'],
			answer   : isset( $row['answer'] ) ? json_decode( (string) $row['answer'], true ) : null,
			isCorrect: (bool) (int) $row['is_correct'],
		);
	}
}
