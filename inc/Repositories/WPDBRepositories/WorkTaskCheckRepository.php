<?php

declare( strict_types=1 );

namespace Inc\Repositories\WPDBRepositories;

use Inc\DTO\Course\WorkTaskCheckDTO;
use Inc\Enums\Settings\TableName;

/**
 * Class WorkTaskCheckRepository
 *
 * CRUD для `fs_lms_work_task_checks` — проверки ответа кнопкой внутри работы.
 * Отдельно от `task_attempts`: те считают сдачи работы (лимит пересдач), а
 * проверка — не сдача.
 *
 * @package Inc\Repositories\WPDBRepositories
 */
class WorkTaskCheckRepository {

	private \wpdb  $wpdb;
	private string $table;

	public function __construct( ?\wpdb $wpdb = null ) {
		$this->wpdb  = $wpdb ?? $GLOBALS['wpdb'];
		$this->table = TableName::WorkTaskChecks->prefixed();
	}

	/**
	 * Записывает проверку и возвращает её ID.
	 */
	public function create(
		int   $studentPersonId,
		int   $groupLessonId,
		int   $workId,
		int   $taskId,
		int   $round,
		mixed $answer,
		bool  $isCorrect,
	): int {
		$this->wpdb->insert( $this->table, array(
			'student_person_id' => $studentPersonId,
			'group_lesson_id'   => $groupLessonId,
			'work_id'           => $workId,
			'task_id'           => $taskId,
			'round'             => $round,
			'answer'            => wp_json_encode( $answer ),
			'is_correct'        => $isCorrect ? 1 : 0,
		) );

		return (int) $this->wpdb->insert_id;
	}

	/**
	 * Проверки ученика по работе в одном раунде сдачи, по возрастанию.
	 *
	 * @return WorkTaskCheckDTO[]
	 */
	public function listByRound( int $studentPersonId, int $groupLessonId, int $workId, int $round ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE student_person_id = %d AND group_lesson_id = %d AND work_id = %d AND round = %d ORDER BY id ASC',
				$this->table,
				$studentPersonId,
				$groupLessonId,
				$workId,
				$round,
			),
			ARRAY_A
		);

		return array_map( array( WorkTaskCheckDTO::class, 'fromArray' ), $rows ?: array() );
	}

	/** Сброс работы преподавателем: проверки уходят вместе со сдачей и историей попыток. */
	public function deleteByStudentWork( int $studentPersonId, int $groupLessonId, int $workId ): int {
		return (int) $this->wpdb->delete(
			$this->table,
			array(
				'student_person_id' => $studentPersonId,
				'group_lesson_id'   => $groupLessonId,
				'work_id'           => $workId,
			)
		);
	}

	/** Каскадная очистка при удалении занятия (GroupDeletionHandler). */
	public function deleteAllByGroupLesson( int $groupLessonId ): int {
		return (int) $this->wpdb->delete( $this->table, array( 'group_lesson_id' => $groupLessonId ) );
	}
}
