<?php

declare( strict_types=1 );

namespace Inc\Repositories\WPDBRepositories;

use Inc\DTO\Task\TaskAttemptDTO;
use Inc\Enums\Settings\TableName;

/**
 * Class TaskAttemptRepository
 *
 * CRUD для `fs_lms_task_attempts` — история попыток ученика на задании шага урока (Этап 6).
 *
 * @package Inc\Repositories\WPDBRepositories
 */
class TaskAttemptRepository {

	private \wpdb  $wpdb;
	private string $table;

	public function __construct( ?\wpdb $wpdb = null ) {
		$this->wpdb  = $wpdb ?? $GLOBALS['wpdb'];
		$this->table = TableName::TaskAttempts->prefixed();
	}

	/**
	 * Записывает попытку и возвращает её ID.
	 */
	public function create(
		int    $studentPersonId,
		int    $groupLessonId,
		string $stepKey,
		int    $taskId,
		int    $attemptNumber,
		mixed  $answer,
		bool   $isCorrect,
		float  $score,
		float  $maxScore,
		array  $itemFeedback,
	): int {
		$this->wpdb->insert( $this->table, array(
			'student_person_id' => $studentPersonId,
			'group_lesson_id'   => $groupLessonId,
			'step_key'          => $stepKey,
			'task_id'           => $taskId,
			'attempt_number'    => $attemptNumber,
			'answer'            => wp_json_encode( $answer ),
			'is_correct'        => $isCorrect ? 1 : 0,
			'score'             => $score,
			'max_score'         => $maxScore,
			'item_feedback'     => wp_json_encode( $itemFeedback ),
		) );

		return (int) $this->wpdb->insert_id;
	}

	/**
	 * Кол-во попыток студента по конкретному шагу.
	 */
	public function countByStep( int $studentPersonId, int $groupLessonId, string $stepKey ): int {
		return (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE student_person_id = %d AND group_lesson_id = %d AND step_key = %s',
				$this->table,
				$studentPersonId,
				$groupLessonId,
				$stepKey,
			)
		);
	}

	/**
	 * Кол-во попыток студента по конкретной задаче внутри шага.
	 *
	 * У шага урока задача одна, поэтому там это то же, что {@see countByStep()};
	 * у работы задач несколько, и нумерация должна идти по каждой отдельно.
	 */
	public function countByStepTask( int $studentPersonId, int $groupLessonId, string $stepKey, int $taskId ): int {
		return (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE student_person_id = %d AND group_lesson_id = %d AND step_key = %s AND task_id = %d',
				$this->table,
				$studentPersonId,
				$groupLessonId,
				$stepKey,
				$taskId,
			)
		);
	}

	/**
	 * Все попытки студента по шагу в хронологическом порядке.
	 *
	 * @return TaskAttemptDTO[]
	 */
	public function listByStep( int $studentPersonId, int $groupLessonId, string $stepKey ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE student_person_id = %d AND group_lesson_id = %d AND step_key = %s ORDER BY attempt_number ASC',
				$this->table,
				$studentPersonId,
				$groupLessonId,
				$stepKey,
			),
			ARRAY_A
		);

		return array_map( array( TaskAttemptDTO::class, 'fromArray' ), $rows ?: array() );
	}

	/**
	 * Все попытки по шагу урока (все студенты).
	 * Используется в отчёте преподавателя (Этап 6, Фаза G).
	 *
	 * @return TaskAttemptDTO[]
	 */
	public function listByGroupAndStep( int $groupLessonId, string $stepKey ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE group_lesson_id = %d AND step_key = %s ORDER BY student_person_id ASC, attempt_number ASC',
				$this->table,
				$groupLessonId,
				$stepKey,
			),
			ARRAY_A
		);

		return array_map( array( TaskAttemptDTO::class, 'fromArray' ), $rows ?: array() );
	}

	/**
	 * Все попытки занятия — по всем шагам и всем ученикам.
	 *
	 * Порядок сразу пригоден для группировки на стороне вызывающего:
	 * шаг → ученик → номер попытки.
	 *
	 * @return TaskAttemptDTO[]
	 */
	public function listByGroupLesson( int $groupLessonId ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE group_lesson_id = %d ORDER BY step_key ASC, student_person_id ASC, attempt_number ASC',
				$this->table,
				$groupLessonId,
			),
			ARRAY_A
		);

		return array_map( array( TaskAttemptDTO::class, 'fromArray' ), $rows ?: array() );
	}

	/**
	 * Все попытки всех студентов по заданию в рамках группового занятия.
	 * Используется в отчёте преподавателя (Этап 6, Фаза G).
	 *
	 * @return TaskAttemptDTO[]
	 */
	public function listByTaskForGroup( int $taskId, int $groupLessonId ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE task_id = %d AND group_lesson_id = %d ORDER BY student_person_id ASC, attempt_number ASC',
				$this->table,
				$taskId,
				$groupLessonId,
			),
			ARRAY_A
		);

		return array_map( array( TaskAttemptDTO::class, 'fromArray' ), $rows ?: array() );
	}

	/**
	 * Удаляет историю попыток ученика по одному шагу занятия.
	 * Нужна сбросу работы: сдачи и их история должны исчезать вместе.
	 */
	public function deleteByStudentStep( int $studentPersonId, int $groupLessonId, string $stepKey ): int {
		return (int) $this->wpdb->delete(
			$this->table,
			array(
				'student_person_id' => $studentPersonId,
				'group_lesson_id'   => $groupLessonId,
				'step_key'          => $stepKey,
			)
		);
	}

	/** Каскадная очистка при удалении занятия (GroupDeletionHandler). */
	public function deleteAllByGroupLesson( int $groupLessonId ): int {
		return (int) $this->wpdb->delete( $this->table, array( 'group_lesson_id' => $groupLessonId ) );
	}

	/** Есть ли хотя бы одна попытка задания по строке доставки (D17.3: guard вовлечённости). */
	public function hasAnyByGroupLesson( int $groupLessonId ): bool {
		return (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				'SELECT EXISTS(SELECT 1 FROM %i WHERE group_lesson_id = %d)',
				$this->table,
				$groupLessonId
			)
		) > 0;
	}
}
