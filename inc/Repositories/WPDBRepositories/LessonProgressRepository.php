<?php

declare( strict_types=1 );

namespace Inc\Repositories\WPDBRepositories;

use Inc\DTO\Course\LessonProgressDTO;
use Inc\Enums\Course\ProgressStatus;
use Inc\Enums\Settings\TableName;

/**
 * Class LessonProgressRepository
 *
 * Доступ к `fs_lms_lesson_progress` (★) — прохождение шагов урока учеником.
 * Upsert по `UNIQUE(student_person_id, group_lesson_id, step_key)`; выборки для плеера и дашборда.
 *
 * @package Inc\Repositories\WPDBRepositories
 */
class LessonProgressRepository {

	private \wpdb  $wpdb;
	private string $table;

	public function __construct( ?\wpdb $wpdb = null ) {
		$this->wpdb  = $wpdb ?? $GLOBALS['wpdb'];
		$this->table = TableName::LessonProgress->prefixed();
	}

	/**
	 * Прогресс конкретного шага ученика в уроке программы.
	 */
	public function find( int $studentPersonId, int $groupLessonId, string $stepKey ): ?LessonProgressDTO {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE student_person_id = %d AND group_lesson_id = %d AND step_key = %s LIMIT 1',
				$this->table,
				$studentPersonId,
				$groupLessonId,
				$stepKey
			),
			ARRAY_A
		);

		return $row ? LessonProgressDTO::fromArray( $row ) : null;
	}

	/**
	 * Upsert по UNIQUE(student_person_id, group_lesson_id, step_key): обновляет статус
	 * существующей строки либо вставляет новую. Возвращает id строки.
	 */
	public function upsert(
		int $studentPersonId,
		int $groupLessonId,
		int $lessonId,
		string $stepKey,
		ProgressStatus $status,
		?string $completedAt = null
	): int {
		$existing = $this->find( $studentPersonId, $groupLessonId, $stepKey );

		if ( null !== $existing ) {
			$this->wpdb->update(
				$this->table,
				array(
					'lesson_id'    => $lessonId,
					'status'       => $status->value,
					'completed_at' => $completedAt,
				),
				array( 'id' => $existing->id )
			);

			return $existing->id;
		}

		$this->wpdb->insert(
			$this->table,
			array(
				'student_person_id' => $studentPersonId,
				'group_lesson_id'   => $groupLessonId,
				'lesson_id'         => $lessonId,
				'step_key'          => $stepKey,
				'status'            => $status->value,
				'completed_at'      => $completedAt,
			)
		);

		return (int) $this->wpdb->insert_id;
	}

	/**
	 * Прогресс ученика по всем шагам одного урока программы (для пошагового плеера).
	 *
	 * @return LessonProgressDTO[]
	 */
	public function listForStudent( int $studentPersonId, int $groupLessonId ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE student_person_id = %d AND group_lesson_id = %d',
				$this->table,
				$studentPersonId,
				$groupLessonId
			),
			ARRAY_A
		);

		return array_map( array( LessonProgressDTO::class, 'fromArray' ), $rows ?: array() );
	}

	/**
	 * Прогресс всех учеников по уроку программы (для дашборда группы).
	 *
	 * @return LessonProgressDTO[]
	 */
	public function listByGroupLesson( int $groupLessonId ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE group_lesson_id = %d',
				$this->table,
				$groupLessonId
			),
			ARRAY_A
		);

		return array_map( array( LessonProgressDTO::class, 'fromArray' ), $rows ?: array() );
	}

	/**
	 * Удаляет прогресс по уроку программы (например, при удалении строки из программы).
	 */
	public function deleteByGroupLesson( int $groupLessonId ): void {
		$this->wpdb->delete( $this->table, array( 'group_lesson_id' => $groupLessonId ) );
	}

	/**
	 * Время последней активности ученика по каждой группе (D17.1): MAX(updated_at)
	 * прогресса шагов, сгруппированный по группе через связь group_lesson_id → group_id.
	 * Используется для recency-сортировки «Мои курсы» (свежий курс — первым).
	 *
	 * @return array<int, string> group_id => 'Y-m-d H:i:s'
	 */
	public function latestActivityByStudent( int $studentPersonId ): array {
		$groupLessons = TableName::GroupLessons->prefixed();
		$rows         = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT gl.group_id AS group_id, MAX(lp.updated_at) AS last_activity
				 FROM %i lp
				 INNER JOIN %i gl ON gl.id = lp.group_lesson_id
				 WHERE lp.student_person_id = %d
				 GROUP BY gl.group_id',
				$this->table,
				$groupLessons,
				$studentPersonId
			),
			ARRAY_A
		);

		$map = array();
		foreach ( $rows ?: array() as $row ) {
			$map[ (int) $row['group_id'] ] = (string) $row['last_activity'];
		}

		return $map;
	}
}
