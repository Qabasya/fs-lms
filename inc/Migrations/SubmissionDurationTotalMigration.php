<?php

declare( strict_types=1 );

namespace Inc\Migrations;

use Inc\Enums\Settings\TableName;

/**
 * Class SubmissionDurationTotalMigration
 *
 * `submissions.duration_sec` хранил время только последней попытки работы. Теперь это
 * сумма по всем попыткам ({@see \Inc\Services\Course\SubmissionService::submitBatch()}),
 * а у уже существующих сдач сумма пересчитывается из раундов `task_attempts`
 * (время раунда одинаково во всех его строках — берём MAX на раунд, затем SUM по раундам).
 *
 * Data-миграция, version-gated собственной опцией (паттерн
 * {@see SubmissionScoreBackfillMigration}); значение только растёт, поэтому повторный
 * прогон безопасен.
 *
 * @package Inc\Migrations
 */
class SubmissionDurationTotalMigration {

	private const VERSION_OPTION = 'fs_lms_submission_duration_total_version';
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

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		// step_key работы — 'work:{id}' (AttemptSource::workStepKey()).
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE %i s
				 JOIN (
				   SELECT student_person_id, group_lesson_id, step_key, SUM(round_sec) AS total
				   FROM (
				     SELECT student_person_id, group_lesson_id, step_key, attempt_number, MAX(duration_sec) AS round_sec
				     FROM %i
				     WHERE step_key LIKE %s
				     GROUP BY student_person_id, group_lesson_id, step_key, attempt_number
				   ) r
				   GROUP BY student_person_id, group_lesson_id, step_key
				 ) t ON t.student_person_id = s.student_person_id
				   AND t.group_lesson_id = s.group_lesson_id
				   AND t.step_key = CONCAT('work:', s.work_id)
				 SET s.duration_sec = t.total
				 WHERE s.task_id IS NULL AND t.total > COALESCE(s.duration_sec, 0)",
				TableName::Submissions->prefixed(),
				TableName::TaskAttempts->prefixed(),
				'work:%'
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
	}
}
