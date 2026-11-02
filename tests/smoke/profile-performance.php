<?php
/**
 * Read-only dev profiler. Run: docker exec -i wp_app php < tests/smoke/profile-performance.php
 * Reports aggregate costs and SQL fingerprints; never exports profile values or identities.
 */

declare( strict_types=1 );

define( 'SAVEQUERIES', true );
$bootStarted = hrtime( true );
require $argv[1] ?? '/var/www/html/wp-load.php';

use Inc\Contracts\ClockInterface;
use Inc\Contracts\LogEventDispatcherInterface;
use Inc\Core\Container;
use Inc\Enums\Access\UserRole;
use Inc\Services\Course\PassedWorksService;
use Inc\Services\Log\LogEventDispatcher;
use Inc\Services\Profile\DashboardService;
use Inc\Services\Profile\LearnerService;
use Inc\Services\Profile\ProfileViewResolver;
use Inc\Services\Profile\RankingAccessService;
use Inc\Services\Profile\SubjectRankingService;
use Inc\Services\Shared\WpClock;

/** Measures production read paths using a fixed, automatically selected dev cohort. */
final class ProfilePerformanceCheck {
	public function __construct( private readonly wpdb $database ) {}

	private function container(): Container {
		$container = new Container();
		$container->bind( ClockInterface::class, WpClock::class );
		$container->bind( LogEventDispatcherInterface::class, LogEventDispatcher::class );
		return $container;
	}

	/** Remove all literal values before grouping SQL; no SQL text leaves this process. */
	private function fingerprint( string $sql ): string {
		$sql = (string) preg_replace( "/'(?:[^'\\\\]|\\\\.)*'/s", '?', $sql );
		$sql = (string) preg_replace( '/\b\d+(?:\.\d+)?\b/', '?', $sql );
		return (string) preg_replace( '/\s+/', ' ', trim( $sql ) );
	}

	private function measure( string $scenario, string $cache, callable $operation ): array {
		$queryStart = count( $this->database->queries );
		$memory = memory_get_usage();
		memory_reset_peak_usage();
		$started = hrtime( true );
		$result = $operation();
		$elapsed = ( hrtime( true ) - $started ) / 1e6;
		$queries = array_slice( $this->database->queries, $queryStart );
		$duplicates = array();
		$shapes = array();
		foreach ( $queries as $query ) {
			$key = hash( 'sha256', $this->fingerprint( $query[0] ) );
			$duplicates[ $key ] = ( $duplicates[ $key ] ?? 0 ) + 1;
			preg_match_all( '/\b(?:FROM|JOIN)\s+`?([a-zA-Z0-9_]+)/i', $query[0], $tables );
			$shapes[ $key ] = array( 'tables' => array_values( array_unique( $tables[1] ) ), 'caller' => (string) ( $query[2] ?? '' ) );
		}
		arsort( $duplicates );
		$top = array();
		foreach ( array_slice( $duplicates, 0, 12, true ) as $key => $count ) {
			$top[] = array( 'count' => $count ) + $shapes[ $key ];
		}
		return array(
			'scenario' => $scenario, 'cache' => $cache,
			'ms' => round( $elapsed, 3 ), 'sql_count' => count( $queries ),
			'sql_ms' => round( array_sum( array_column( $queries, 1 ) ) * 1000, 3 ),
			'repeated_shapes' => array_values( array_filter( $duplicates, static fn( int $count ): bool => $count > 1 ) ),
			'query_shapes' => $top,
			'json_bytes' => strlen( (string) wp_json_encode( $result ) ),
			'peak_extra_bytes' => max( 0, memory_get_peak_usage() - $memory ),
		);
	}

	public function run(): array {
		// Only IDs and cohort sizes are read; names, logins and contact details are never selected.
		$cohort = $this->database->get_results(
			"SELECT p.wp_user_id AS user_id, p.id AS person_id, COUNT(DISTINCT gl.id) AS lessons
			 FROM {$this->database->prefix}fs_lms_persons p
			 JOIN {$this->database->prefix}fs_lms_student_records r ON r.student_person_id = p.id AND r.status = 'active'
			 LEFT JOIN {$this->database->prefix}fs_lms_group_lessons gl ON gl.group_id = r.group_id
			 WHERE p.wp_user_id IS NOT NULL GROUP BY p.id, p.wp_user_id ORDER BY lessons ASC, p.id ASC",
			ARRAY_A
		);
		$cases = array();
		if ( array() !== $cohort ) {
			$cases['learner_small'] = $cohort[0];
			$cases['learner_large'] = $cohort[ count( $cohort ) - 1 ];
		}
		foreach ( array( 'parent' => UserRole::FSParent->value, 'staff' => 'administrator' ) as $label => $role ) {
			$ids = get_users( array( 'role' => $role, 'fields' => 'ID', 'number' => 1, 'orderby' => 'ID' ) );
			if ( array() !== $ids ) {
				$cases[ $label ] = array( 'user_id' => (int) $ids[0] );
			}
		}
		$metrics = array();
		foreach ( $cases as $label => $case ) {
			wp_set_current_user( (int) $case['user_id'] );
			for ( $run = 0; $run < 4; ++$run ) {
				// Flush only the default, process-local cache. Never flush a persistent cache backend.
				if ( 0 === $run && ! wp_using_ext_object_cache() ) {
					wp_cache_flush();
				}
				$container = $this->container();
				$resolver = $container->get( ProfileViewResolver::class );
				$ctx = $resolver->context( (int) $case['user_id'] );
				$cache = 0 === $run ? ( wp_using_ext_object_cache() ? 'first_persistent_cache' : 'cold_object_cache' ) : 'warm_' . $run;
				$metrics[] = $this->measure( $label . ':shell_config', $cache, fn(): array => $resolver->jsConfig( $ctx->wpUserId ) );
				if ( null !== $ctx->subjectPersonId && in_array( $ctx->role, array( UserRole::FSStudent, UserRole::FSParent ), true ) ) {
					$metrics[] = $this->measure( $label . ':profile', $cache, fn(): array => $container->get( LearnerService::class )->build( $ctx->subjectPersonId )->toArray() );
					$subjects = $container->get( RankingAccessService::class )->learnerSubjectsFor( $ctx->subjectPersonId );
					if ( UserRole::FSStudent === $ctx->role && array() !== $subjects ) {
						$metrics[] = $this->measure( $label . ':ranking', $cache, fn(): array => $container->get( SubjectRankingService::class )->buildForLearner( $ctx->subjectPersonId, $subjects[0]['key'] ) );
					}
				} elseif ( UserRole::FSOffice === $ctx->role || UserRole::FSTeacher === $ctx->role ) {
					$metrics[] = $this->measure( $label . ':dashboard', $cache, fn(): array => $container->get( DashboardService::class )->build( $ctx->wpUserId, true ) );
					$metrics[] = $this->measure( $label . ':works', $cache, fn(): array => $container->get( PassedWorksService::class )->feed( $ctx->wpUserId, true, 0, '', '', '', PassedWorksService::SORT_SUBMISSIONS ) );
				}
			}
		}
		return array( 'cohort_lesson_counts' => array_column( array_intersect_key( $cases, array_flip( array( 'learner_small', 'learner_large' ) ) ), 'lessons' ), 'metrics' => $metrics );
	}
}

try {
	$report = array( 'bootstrap_ms' => round( ( hrtime( true ) - $bootStarted ) / 1e6, 3 ) ) + ( new ProfilePerformanceCheck( $GLOBALS['wpdb'] ) )->run();
	echo wp_json_encode( $report, JSON_PRETTY_PRINT ) . PHP_EOL;
} catch ( Throwable $error ) {
	fwrite( STDERR, get_class( $error ) . ': ' . $error->getMessage() . PHP_EOL );
	exit( 1 );
}
