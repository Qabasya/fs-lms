<?php
/**
 * Subject ranking read model.
 *
 * @package FS_LMS
 */

declare( strict_types=1 );

namespace Inc\Repositories\WPDBRepositories;

use Inc\Enums\Assessment\AssessmentKind;
use Inc\Enums\Assessment\AttemptStatus;
use Inc\Enums\Course\AttemptSource;
use Inc\Enums\Course\SubmissionStatus;
use Inc\Enums\Settings\TableName;
use Inc\Enums\Enrollment\EnrollmentStatus;
use Inc\Enums\Wp\PostMetaName;
use Inc\Services\Subject\PostTypeResolver;

// All SQL below is prepared, including identifier placeholders from TableName.
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, Universal.Operators.DisallowShortTernary.Found
/** Reads individual solved-task events, never aggregate marks or inline answer checks. */
class LearnerRankingRepository {

	/**
	 * Database connection.
	 *
	 * @var \wpdb
	 */
	private \wpdb $wpdb;

	/** Uses the injected database connection or the WordPress connection. */
	public function __construct( ?\wpdb $wpdb = null ) {
		$this->wpdb = $wpdb ?? $GLOBALS['wpdb'];
	}

	/**
	 * Every currently enrolled student of the subject, including students with zero points.
	 *
	 * @return list<int>
	 */
	public function studentIds( string $subject ): array {
		return array_map(
			'intval',
			$this->wpdb->get_col(
				$this->wpdb->prepare(
					'SELECT DISTINCT r.student_person_id FROM %i r JOIN %i g ON g.id = r.group_id WHERE r.status = %s AND g.subject_key = %s',
					TableName::StudentRecords->prefixed(),
					TableName::Groups->prefixed(),
					EnrollmentStatus::Active->value,
					$subject
				)
			)
		);
	}

	/**
	 * Each history row is one actual submission of one task. A teacher verdict overrides
	 * only the latest recorded answer; its credit date is the date of the review.
	 * Earlier successful resubmissions remain separate events worth a full point.
	 * History created_at is database UTC; submission/review timestamps are site-local.
	 *
	 * @return list<array{student_person_id: mixed, source: mixed, solved_at: mixed}>
	 */
	public function workEvents( string $subject, string $from, string $until ): array {
		$sql  = 'SELECT t.student_person_id, s.work_type AS source,
			CASE WHEN s.graded_by_user_id IS NOT NULL AND newer.id IS NULL THEN s.max_score ELSE COALESCE(t.max_score, 1) END AS task_points,
			(s.graded_by_user_id IS NOT NULL AND newer.id IS NULL) AS reviewed,
			CASE WHEN s.graded_by_user_id IS NOT NULL AND newer.id IS NULL THEN s.graded_at ELSE t.created_at END AS solved_at
			FROM %i t JOIN %i s ON s.student_person_id = t.student_person_id
			AND s.group_lesson_id = t.group_lesson_id AND s.task_id = t.task_id
			AND t.step_key = CONCAT(%s, s.work_id)
			JOIN %i p ON p.ID = s.work_id AND p.post_type = %s
			LEFT JOIN %i newer ON newer.id = (SELECT MAX(n.id) FROM %i n
			WHERE n.student_person_id = t.student_person_id AND n.group_lesson_id = t.group_lesson_id
			AND n.step_key = t.step_key AND n.task_id = t.task_id AND n.id > t.id)
			WHERE CASE WHEN s.graded_by_user_id IS NOT NULL AND newer.id IS NULL
			THEN s.score >= s.max_score AND s.max_score > 0 AND s.status = %s ELSE t.is_correct = 1 AND (t.score IS NULL OR t.score >= t.max_score) END
			HAVING (reviewed = 1 AND solved_at >= %s AND solved_at < %s)
			OR (reviewed = 0 AND solved_at >= %s AND solved_at < %s)';
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				$sql,
				TableName::TaskAttempts->prefixed(),
				TableName::Submissions->prefixed(),
				AttemptSource::WORK_PREFIX,
				$this->wpdb->posts,
				PostTypeResolver::works( $subject ),
				TableName::TaskAttempts->prefixed(),
				TableName::TaskAttempts->prefixed(),
				SubmissionStatus::Graded->value,
				$from,
				$until,
				get_gmt_from_date( $from ),
				get_gmt_from_date( $until )
			),
			ARRAY_A
		) ?: array();

		// Teacher credits and legacy per-task submissions may predate attempt history.
		$fallback = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT s.student_person_id, s.work_type AS source, s.max_score AS task_points,
			CASE WHEN s.graded_by_user_id IS NOT NULL THEN s.graded_at ELSE s.submitted_at END AS solved_at
			FROM %i s JOIN %i p ON p.ID = s.work_id AND p.post_type = %s
			WHERE s.task_id IS NOT NULL AND s.status = %s AND s.score >= s.max_score AND s.max_score > 0
			AND NOT EXISTS (SELECT 1 FROM %i t WHERE t.student_person_id = s.student_person_id
			AND t.group_lesson_id = s.group_lesson_id AND t.task_id = s.task_id AND t.step_key = CONCAT(%s, s.work_id))
			HAVING solved_at >= %s AND solved_at < %s',
				TableName::Submissions->prefixed(),
				$this->wpdb->posts,
				PostTypeResolver::works( $subject ),
				SubmissionStatus::Graded->value,
				TableName::TaskAttempts->prefixed(),
				AttemptSource::WORK_PREFIX,
				$from,
				$until
			),
			ARRAY_A
		) ?: array();

		foreach ( $rows as &$row ) {
			if ( empty( $row['reviewed'] ) ) {
				$row['solved_at'] = get_date_from_gmt( $row['solved_at'] );
			}
			unset( $row['reviewed'] );
		}
		unset( $row );

		return array_merge( $rows, $fallback );
	}

	/**
	 * Full task credit, independent of exam primary/secondary score weights.
	 * Exam/EGE results require approval. OGE course results require completed grading;
	 * the latest manual grading timestamp determines their trimester.
	 *
	 * @return list<array{student_person_id: mixed, source: mixed, solved_at: mixed}>
	 */
	public function assessmentEvents( string $subject, string $from, string $until ): array {
		return $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT a.student_person_id, %s AS source,
			COALESCE(a.approved_at, (SELECT MAX(review.graded_at) FROM %i review WHERE review.attempt_id = a.id), a.submitted_at) AS solved_at
			FROM %i a JOIN %i answer ON answer.attempt_id = a.id
			JOIN %i p ON p.ID = a.assessment_id AND p.post_type = %s
			WHERE a.student_person_id IS NOT NULL AND a.submitted_at IS NOT NULL AND a.status = %s
			AND answer.is_correct = 1 AND answer.max_score > 0 AND answer.score >= answer.max_score
			AND (a.approved_at IS NOT NULL OR (a.exam_participation_id IS NULL
			AND NOT EXISTS (SELECT 1 FROM %i kind WHERE kind.post_id = a.assessment_id AND kind.meta_key = %s AND kind.meta_value = %s)))
			HAVING solved_at >= %s AND solved_at < %s',
				'assessment',
				TableName::AssessmentAnswers->prefixed(),
				TableName::AssessmentAttempts->prefixed(),
				TableName::AssessmentAnswers->prefixed(),
				$this->wpdb->posts,
				PostTypeResolver::assessments( $subject ),
				AttemptStatus::Graded->value,
				$this->wpdb->postmeta,
				PostMetaName::AssessmentKind->value,
				AssessmentKind::EgeComputer->value,
				$from,
				$until
			),
			ARRAY_A
		) ?: array();
	}
	/** Held subject lessons in enrollment intervals; missing attendance breaks a series. */
	public function attendanceEvents( string $subject, string $from, string $until, string $nowLocal ): array {
		return $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT DISTINCT r.student_person_id, l.id AS group_lesson_id, l.scheduled_at, COALESCE(a.is_present, 0) AS present
				FROM %i r JOIN %i g ON g.id = r.group_id JOIN %i l ON l.group_id = g.id
				LEFT JOIN %i a ON a.group_lesson_id = l.id AND a.student_person_id = r.student_person_id
				WHERE g.subject_key = %s AND l.status = %s AND l.scheduled_at >= %s AND l.scheduled_at < %s AND l.scheduled_at <= %s
				AND r.enrolled_at <= l.scheduled_at AND (r.expelled_at IS NULL OR r.expelled_at > l.scheduled_at)
				AND (l.student_person_id IS NULL OR l.student_person_id = r.student_person_id)
				ORDER BY r.student_person_id ASC, l.scheduled_at ASC, l.id ASC',
				TableName::StudentRecords->prefixed(),
				TableName::Groups->prefixed(),
				TableName::GroupLessons->prefixed(),
				TableName::Attendance->prefixed(),
				$subject,
				\Inc\Enums\Course\LessonStatus::Held->value,
				$from,
				$until,
				$nowLocal
			),
			ARRAY_A
		) ?: array();
	}
	/** Active roster names and group labels only; no contact or identity-document data. */
	public function roster( string $subject ): array {
		return $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT r.student_person_id, r.group_id, r.snapshot_last_name, r.snapshot_first_name, r.snapshot_middle_name, g.name AS group_name
			FROM %i r JOIN %i g ON g.id = r.group_id WHERE r.status = %s AND g.subject_key = %s ORDER BY r.id DESC',
				TableName::StudentRecords->prefixed(),
				TableName::Groups->prefixed(),
				EnrollmentStatus::Active->value,
				$subject
			),
			ARRAY_A
		) ?: array();
	}
}
