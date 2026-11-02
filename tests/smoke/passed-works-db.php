<?php
/**
 * Real MariaDB/MySQL pagination checks, using only connection-local temporary tables.
 * Run: docker exec -i wp_app php < tests/smoke/passed-works-db.php
 */

declare( strict_types=1 );

require $argv[1] ?? '/var/www/html/wp-load.php';

use Inc\Repositories\WPDBRepositories\SubmissionRepository;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;

/** Exercises the production query against disposable fixtures, never application rows. */
final class PassedWorksDatabaseCheck {
	private const PREFIX = 'works_probe_';

	public function __construct( private readonly wpdb $database ) {}

	private function expect( string $label, mixed $expected, mixed $actual ): void {
		if ( $expected !== $actual ) {
			throw new RuntimeException( $label . ': ' . var_export( $actual, true ) );
		}
		echo 'PASS: ' . $label . PHP_EOL;
	}

	private function insert( string $table, array $data ): void {
		if ( false === $this->database->insert( self::PREFIX . $table, $data ) ) {
			throw new RuntimeException( $this->database->last_error );
		}
	}

	private function lesson( int $id, array $overrides = array() ): void {
		$this->insert( 'fs_lms_group_lessons', array_merge( array(
			'id' => $id, 'group_id' => 1, 'scheduled_at' => '2026-10-19 09:00:00',
		), $overrides ) );
	}

	private function submission( int $lesson, int $work, string $at, ?int $task = null ): void {
		$this->insert( 'fs_lms_submissions', array(
			'student_person_id' => $lesson, 'group_lesson_id' => $lesson, 'work_id' => $work,
			'work_type' => 'homework', 'status' => 'submitted', 'submitted_at' => $at, 'task_id' => $task,
		) );
	}

	/** Reads every page with the exact cursor returned by the production query. */
	private function pages( SubmissionRepository $repository, int $size ): array {
		$result = array();
		$at = null;
		$lesson = 0;
		$work = 0;
		for ( $page = 0; $page < 1000; ++$page ) {
			$rows = $repository->listWorkLatestPage( array( 1 ), '2026-11-01 00:00:00', $at, $lesson, $work, $size );
			if ( '' !== $this->database->last_error ) {
				throw new RuntimeException( $this->database->last_error );
			}
			if ( array() === $rows ) {
				return $result;
			}
			array_push( $result, ...$rows );
			$last = $rows[ count( $rows ) - 1 ];
			$at = $last['latest_at'];
			$lesson = $last['group_lesson_id'];
			$work = $last['work_id'];
		}
		throw new RuntimeException( 'Cursor did not terminate.' );
	}

	public function run(): void {
		$prefix = $this->database->prefix;
		try {
			foreach ( array( 'fs_lms_submissions', 'fs_lms_group_lessons', 'fs_lms_attendance' ) as $table ) {
				if ( false === $this->database->query( $this->database->prepare( 'CREATE TEMPORARY TABLE %i LIKE %i', self::PREFIX . $table, $prefix . $table ) ) ) {
					throw new RuntimeException( $this->database->last_error );
				}
			}
			$this->database->prefix = self::PREFIX;
			$repository = new SubmissionRepository( $this->database );
			for ( $id = 1; $id <= 125; ++$id ) {
				$this->lesson( $id );
				$this->submission( $id, $id, '2026-10-19 13:00:00' );
			}
			$this->expect( '125 equal timestamps across 10-row pages', range( 125, 1 ), array_column( $this->pages( $repository, 10 ), 'work_id' ) );
			$this->expect( 'scan boundary 40/41 preserves all pairs', range( 125, 1 ), array_column( $this->pages( $repository, 40 ), 'work_id' ) );
			$this->submission( 5, 201, '2026-10-19 12:00:00' );
			$this->submission( 6, 202, '2026-10-19 11:00:00' );
			$this->submission( 5, 203, '2026-10-19 10:00:00' );
			$this->expect( 'per-work ordering 12:00 / 11:00 / 10:00', array( 201, 202, 203 ), array_slice( array_column( $this->pages( $repository, 10 ), 'work_id' ), -3 ) );
			foreach ( array( 300, 301 ) as $id ) {
				$this->lesson( $id, array( 'extra_key' => 'extra', 'kind' => 'individual', 'student_person_id' => $id ) );
			}
			$this->submission( 300, 400, '2026-10-19 14:00:00' );
			$this->submission( 301, 400, '2026-10-19 12:00:00' );
			$rows = $this->pages( $repository, 1 );
			$this->expect( 'extra siblings merge before LIMIT even on one-row pages', 1, count( array_filter( $rows, static fn( array $row ): bool => 400 === $row['work_id'] ) ) );
			$this->expect( 'extra unit uses latest submission and greatest row ID', array( 'group_lesson_id' => 301, 'work_id' => 400, 'latest_at' => '2026-10-19 14:00:00' ), $rows[0] );
			$this->lesson( 302, array( 'extra_key' => 'extra', 'scheduled_at' => '2026-10-20 09:00:00' ) );
			$this->submission( 302, 400, '2026-10-20 14:00:00' );
			$this->lesson( 500, array( 'group_id' => 2 ) );
			$this->lesson( 501, array( 'scheduled_at' => '2026-12-01 00:00:00' ) );
			$this->submission( 500, 500, '2026-10-20 15:00:00' );
			$this->submission( 501, 501, '2026-10-20 15:00:00' );
			$this->submission( 1, 999, '2026-10-20 15:00:00', 10 );
			$rows = $this->pages( $repository, 40 );
			$this->expect( 'rescheduled sibling remains separate', 2, count( array_filter( $rows, static fn( array $row ): bool => 400 === $row['work_id'] ) ) );
			$this->expect( 'other groups, future lessons and per-task rows excluded', array(), array_values( array_intersect( array( 500, 501, 999 ), array_column( $rows, 'work_id' ) ) ) );
			// Compare the production batch queries with the old read paths on actual SQL.
			$expected = array();
			foreach ( array( 1, 5, 6, 300, 301 ) as $id ) {
				$expected[ $id ] = $repository->listAggregatesByGroupLesson( $id );
			}
			$start = $this->database->num_queries;
			$batch = $repository->listByGroupLessons( array( 1, 5, 6, 300, 301, 300 ), null, true );
			$this->expect( 'aggregate delivery batch uses one query', 1, $this->database->num_queries - $start );
			foreach ( $expected as $id => $submissions ) {
				$this->expect( 'aggregate batch matches individual delivery ' . $id, serialize( $submissions ), serialize( $batch[ $id ] ) );
			}
			$expected = $repository->listByStudentAndGroupLesson( 1, 1 );
			$this->expect( 'student scope and per-task rows preserved', serialize( $expected ), serialize( $repository->listByGroupLessons( array( 1, 5, 6 ), 1 )[1] ) );
			$this->expect( 'another learner has no batch entry', array(), $repository->listByGroupLessons( array( 1, 5, 6 ), 999 ) );
			$this->lesson( 600, array( 'continued_from_id' => 5 ) );
			$this->insert( 'fs_lms_attendance', array( 'group_lesson_id' => 600, 'student_person_id' => 1, 'is_present' => 1, 'marked_by' => 1, 'marked_at' => '2026-10-19 09:00:00' ) );
			$lessons = new GroupLessonRepository( $this->database );
			$expected = array( 5 => $lessons->find( 5 ), 600 => $lessons->find( 600 ) );
			$start = $this->database->num_queries;
			$batch = $lessons->findByIds( array( 5, 600, 5, 999 ) );
			$this->expect( 'delivery batch uses one query', 1, $this->database->num_queries - $start );
			$this->expect( 'delivery batch preserves attendance, continuation and missing rows', serialize( $expected ), serialize( $batch ) );

		} finally {
			$this->database->prefix = $prefix;
		}
	}
}

try {
	( new PassedWorksDatabaseCheck( $GLOBALS['wpdb'] ) )->run();
} catch ( Throwable $error ) {
	fwrite( STDERR, $error->getMessage() . PHP_EOL );
	exit( 1 );
}
