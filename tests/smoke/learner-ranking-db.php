<?php

declare( strict_types=1 );

/**
 * Real MySQL regression check using connection-local temporary tables only.
 *
 * Run: docker exec -i wp_app php < tests/smoke/learner-ranking-db.php
 * Optional first CLI argument: path to wp-load.php.
 */
require $argv[1] ?? '/var/www/html/wp-load.php';

use Inc\Enums\Wp\PostMetaName;
use Inc\Repositories\WPDBRepositories\LearnerRankingRepository;

/** Exercises the real ranking SQL without writing to persistent application tables. */
final class LearnerRankingDatabaseCheck {

	private const PREFIX = 'ranking_probe_';

	private int $attemptNumber = 0;

	public function __construct( private readonly wpdb $database ) {}

	/** Replaces table references only in this connection; temporary tables disappear on disconnect. */
	private function createTemporarySchema(): void {
		$tables = array(
			'fs_lms_student_records',
			'fs_lms_groups',
			'fs_lms_group_lessons',
			'fs_lms_attendance',
			'fs_lms_task_attempts',
			'fs_lms_submissions',
			'fs_lms_assessment_attempts',
			'fs_lms_assessment_answers',
			'posts',
			'postmeta',
		);
		foreach ( $tables as $table ) {
			$query = $this->database->prepare(
				'CREATE TEMPORARY TABLE %i LIKE %i',
				self::PREFIX . $table,
				$this->database->prefix . $table
			);
			if ( false === $this->database->query( $query ) ) {
				throw new RuntimeException( $this->database->last_error );
			}
		}
		$this->database->prefix   = self::PREFIX;
		$this->database->posts    = self::PREFIX . 'posts';
		$this->database->postmeta = self::PREFIX . 'postmeta';
	}

	/** Inserts a fixture into the session-local schema. */
	private function insert( string $table, array $data ): int {
		if ( false === $this->database->insert( self::PREFIX . $table, $data ) ) {
			throw new RuntimeException( $this->database->last_error );
		}
		return (int) $this->database->insert_id;
	}

	/** Creates only the post fields needed by real schema constraints and routing. */
	private function post( int $id, string $type ): void {
		$this->insert( 'posts', array(
			'ID'                    => $id,
			'post_type'             => $type,
			'post_title'            => 'Ranking regression fixture',
			'post_content'          => '',
			'post_excerpt'          => '',
			'to_ping'               => '',
			'pinged'                => '',
			'post_content_filtered' => '',
		) );
	}

	/** Latest per-task submission; previous rounds remain in task_attempts. */
	private function submission( int $taskId, string $type, string $submittedAt, ?string $reviewedAt = null, float $points = 1.0 ): void {
		$this->insert( 'fs_lms_submissions', array(
			'student_person_id' => 1,
			'group_lesson_id'   => 1,
			'work_id'           => 101,
			'work_type'         => $type,
			'task_id'           => $taskId,
			'status'            => 'graded',
			'score'             => $points,
			'max_score'         => $points,
			'submitted_at'      => $submittedAt,
			'graded_at'         => $reviewedAt,
			'graded_by_user_id' => null === $reviewedAt ? null : 1,
		) );
	}

	/** Records one actual round of submission, with the database's UTC creation timestamp. */
	private function workAttempt( int $taskId, string $submittedAt, bool $correct, int $round = 1, float $points = 1.0 ): void {
		$this->insert( 'fs_lms_task_attempts', array(
			'student_person_id' => 1,
			'group_lesson_id'   => 1,
			'step_key'          => 'work:101',
			'task_id'           => $taskId,
			'attempt_number'    => $round,
			'is_correct'        => $correct ? 1 : 0,
			'score'             => $correct ? $points : 0.0,
			'max_score'         => $points,
			'created_at'        => get_gmt_from_date( $submittedAt ),
		) );
	}

	/** Includes one full task, one incorrect task and one superficially correct partial task. */
	private function assessment( int $assessmentId, string $status, ?string $approval, ?int $participation = null ): void {
		$id = $this->insert( 'fs_lms_assessment_attempts', array(
			'assessment_id'         => $assessmentId,
			'student_person_id'     => 1,
			'attempt_number'        => ++$this->attemptNumber,
			'started_at'            => '2026-10-01 10:00:00',
			'deadline_at'           => '2026-10-01 12:00:00',
			'submitted_at'          => '2026-10-01 11:00:00',
			'status'                => $status,
			'approved_at'           => $approval,
			'exam_participation_id' => $participation,
		) );
		foreach ( array( array( 1, 3, 3 ), array( 0, 1, 3 ), array( 1, 1, 3 ) ) as $index => $score ) {
			$this->insert( 'fs_lms_assessment_answers', array(
				'attempt_id' => $id,
				'task_id'    => 100 + $index,
				'is_correct' => $score[0],
				'score'      => $score[1],
				'max_score'  => $score[2],
			) );
		}
	}

	/** Fails the process on a regression; otherwise prints the verified behavior. */
	private function expect( string $name, int $expected, int $actual ): void {
		if ( $expected !== $actual ) {
			throw new RuntimeException( "$name: expected $expected, got $actual. " . $this->database->last_error );
		}
		echo 'PASS: ' . $name . PHP_EOL;
	}

	/** Includes missing marks, duplicates, a future held lesson and an unrelated subject. */
	private function attendanceFixtures(): void {
		$this->insert( 'fs_lms_groups', array( 'id' => 1, 'subject_key' => 'probe', 'academic_period_id' => '2026', 'name' => 'Probe' ) );
		$this->insert( 'fs_lms_groups', array( 'id' => 2, 'subject_key' => 'other', 'academic_period_id' => '2026', 'name' => 'Other' ) );
		foreach ( array( 1, 1, 2 ) as $groupId ) {
			$this->insert( 'fs_lms_student_records', array(
				'student_person_id' => 1,
				'parent_person_id' => 2,
				'group_id' => $groupId,
				'status' => 'active',
				'enrolled_at' => '2026-09-01 00:00:00',
			) );
		}
		foreach ( array(
			array( 1, '2026-10-01 10:00:00', 'held', 1, 1 ),
			array( 2, '2026-10-02 10:00:00', 'held', 1, null ),
			array( 3, '2026-10-03 10:00:00', 'held', 1, 0 ),
			array( 4, '2026-11-20 10:00:00', 'held', 1, 1 ),
			array( 5, '2026-10-04 10:00:00', 'scheduled', 1, 1 ),
			array( 6, '2026-10-05 10:00:00', 'cancelled', 1, 1 ),
			array( 7, '2026-10-06 10:00:00', 'held', 2, 1 ),
			array( 8, '2026-08-31 10:00:00', 'held', 1, 1 ),
		) as $row ) {
			$this->insert( 'fs_lms_group_lessons', array( 'id' => $row[0], 'scheduled_at' => $row[1], 'status' => $row[2], 'group_id' => $row[3] ) );
			if ( null !== $row[4] ) {
				$this->insert( 'fs_lms_attendance', array( 'group_lesson_id' => $row[0], 'student_person_id' => 1, 'is_present' => $row[4] ) );
			}
		}
	}

	/** Builds isolated fixtures and exercises approval, counting and local date boundaries. */
	public function run(): void {
		$originalPrefix   = $this->database->prefix;
		$originalPosts    = $this->database->posts;
		$originalPostmeta = $this->database->postmeta;
		try {
			$this->createTemporarySchema();
			$this->post( 101, 'probe_works' );
			$this->post( 201, 'probe_assessments' );
			$this->post( 202, 'probe_assessments' );
			$this->insert( 'postmeta', array(
				'post_id'    => 202,
				'meta_key'   => PostMetaName::AssessmentKind->value,
				'meta_value' => 'ege_computer',
			) );

			// Three successful repeats must be three events, without multiplying joined history rows.
			$this->submission( 11, 'homework', '2026-10-31 12:00:00', null, 2.0 );
			for ( $round = 1; $round <= 3; ++$round ) {
				$this->workAttempt( 11, '2026-10-31 12:00:00', true, $round, 2.0 );
			}

			// A UTC instant in November can already be December in the site's timezone.
			$this->submission( 12, 'survey', '2026-12-01 00:30:00' );
			$this->workAttempt( 12, '2026-12-01 00:30:00', true );

			// Manual review credits the result in winter even though submission happened in autumn.
			$this->submission( 13, 'independent', '2026-11-30 10:00:00', '2026-12-02 10:00:00' );
			$this->workAttempt( 13, '2026-11-30 10:00:00', false );

			$this->assessment( 201, 'graded', null );
			$this->assessment( 201, 'in_progress', null );
			$this->assessment( 202, 'graded', null );
			$this->assessment( 202, 'graded', '2026-10-02 12:00:00' );
			$this->assessment( 201, 'graded', null, 501 );
			$this->assessment( 201, 'graded', '2026-12-02 12:00:00', 502 );

			$repository = new LearnerRankingRepository();
			$this->attendanceFixtures();
			$attendance = $repository->attendanceEvents( 'probe', '2026-09-01 00:00:00', '2026-12-01 00:00:00', '2026-10-31 12:00:00' );
			$this->expect( 'attendance excludes future / scheduled / cancelled / foreign / previous-trimester lessons', 3, count( $attendance ) );
			$this->expect( 'missing attendance mark breaks the series', 0, (int) $attendance[1]['present'] );
			$this->expect( 'active roster deduplicates enrollment records', 1, count( $repository->studentIds( 'probe' ) ) );
			$this->expect( 'three repeats / no join multiplication', 3, count( $repository->workEvents( 'probe', '2026-09-01 00:00:00', '2026-12-01 00:00:00' ) ) );
			$this->expect( 'winter local boundary and manual review', 2, count( $repository->workEvents( 'probe', '2026-12-01 00:00:00', '2027-03-01 00:00:00' ) ) );
			$this->expect( 'full tasks and approval gates', 2, count( $repository->assessmentEvents( 'probe', '2026-09-01 00:00:00', '2026-12-01 00:00:00' ) ) );
			$this->expect( 'exam approval controls trimester', 1, count( $repository->assessmentEvents( 'probe', '2026-12-01 00:00:00', '2027-03-01 00:00:00' ) ) );
			$workEvents = $repository->workEvents( 'probe', '2026-09-01 00:00:00', '2026-12-01 00:00:00' );
			$this->expect( 'successful repeats retain each snapshotted task price', 6, (int) array_sum( array_column( $workEvents, 'task_points' ) ) );
			$this->submission( 14, 'independent', '2026-10-31 12:00:00', '2026-10-31 12:00:00', 4.0 );
			$withCredit = $repository->workEvents( 'probe', '2026-09-01 00:00:00', '2026-12-01 00:00:00' );
			$this->expect( 'teacher credit without attempt history contributes stored task price', 10, (int) array_sum( array_column( $withCredit, 'task_points' ) ) );
		} finally {
			// Shutdown hooks must see the real application's table references again.
			$this->database->prefix   = $originalPrefix;
			$this->database->posts    = $originalPosts;
			$this->database->postmeta = $originalPostmeta;
		}
	}
}

( new LearnerRankingDatabaseCheck( $GLOBALS['wpdb'] ) )->run();
