<?php

declare( strict_types=1 );

namespace Inc\Migrations;

use Inc\Enums\Settings\TableName;

/**
 * Class SubmissionScoreBackfillMigration
 *
 * Первая сдача работы писала per-task строки без `score`/`max_score`
 * ({@see \Inc\Services\Course\SubmissionService::submitBatch()}), из-за чего
 * в карточках работ автопроверенные задания рисовались крестиками. Здесь баллы
 * доезжают из JSON-снапшота агрегатной строки (`task_id IS NULL`).
 *
 * Data-миграция, version-gated собственной опцией (паттерн
 * {@see TaskFileSchemeMigration}); затрагиваются только `graded`-строки без балла.
 *
 * @package Inc\Migrations
 */
class SubmissionScoreBackfillMigration {

	private const VERSION_OPTION = 'fs_lms_submission_score_backfill_version';
	private const VERSION        = '1';

	public function run(): void {
		if ( get_option( self::VERSION_OPTION ) === self::VERSION ) {
			return;
		}

		$this->backfill();

		update_option( self::VERSION_OPTION, self::VERSION, false );
	}

	private function backfill(): void {
		global $wpdb;

		$table = TableName::Submissions->prefixed();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT t.id, t.task_id, a.answer_text AS snapshot
				 FROM %i t
				 JOIN %i a ON a.student_person_id = t.student_person_id
				   AND a.group_lesson_id = t.group_lesson_id
				   AND a.work_id = t.work_id
				   AND a.task_id IS NULL
				 WHERE t.task_id IS NOT NULL AND t.status = 'graded' AND t.score IS NULL",
				$table,
				$table
			),
			ARRAY_A
		);

		foreach ( $rows ?: array() as $row ) {
			$snapshot = json_decode( (string) $row['snapshot'], true );
			$entry    = is_array( $snapshot ) ? ( $snapshot[ (int) $row['task_id'] ] ?? null ) : null;
			if ( ! is_array( $entry ) || ! isset( $entry['score'], $entry['maxScore'] ) ) {
				continue;
			}

			$wpdb->update(
				$table,
				array(
					'score'     => (float) $entry['score'],
					'max_score' => (float) $entry['maxScore'],
				),
				array( 'id' => (int) $row['id'] )
			);
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
	}
}
