<?php
/**
 * Concurrent access-log checks against a disposable table (never application rows).
 * Run inside wp_app: php wp-content/plugins/fs-lms/tests/smoke/video-access-db.php
 */
declare( strict_types=1 );

require '/var/www/html/wp-load.php';

use Inc\Modules\VideoLibrary\Repositories\VideoAccessLogRepository;
use Inc\Modules\VideoLibrary\Schema\VideoSchema;

final class VideoAccessDatabaseCheck {
	private function expect( string $label, mixed $expected, mixed $actual ): void {
		if ( $expected !== $actual ) {
			throw new RuntimeException( $label . ': unexpected result' );
		}
		echo 'PASS: ' . $label . PHP_EOL;
	}

	public function run( array $args ): void {
		$db = $GLOBALS['wpdb'];
		if ( 'worker' === ( $args[1] ?? '' ) ) {
			if ( ! preg_match( '/^o6_video_[a-f0-9]{16}_$/', $args[2] ?? '' ) ) {
				throw new RuntimeException( 'Invalid fixture prefix' );
			}
			$db->prefix = $args[2];
			$repo = new VideoAccessLogRepository( $db );
			usleep( max( 0, (int) ( ( (float) $args[3] - microtime( true ) ) * 1e6 ) ) );
			for ( $i = 0; $i < 10; ++$i ) {
				$repo->touch( 1, 'student', 42, 'probe', 'same.webm', '', requests: 1, watchSeconds: 60 );
			}
			return;
		}

		$originalPrefix = $db->prefix;
		$originalTable = VideoSchema::accessTable();
		$prefix = 'o6_video_' . bin2hex( random_bytes( 8 ) ) . '_';
		$db->prefix = $prefix;
		$table = VideoSchema::accessTable();
		$workers = array();
		try {
			if ( false === $db->query( $db->prepare( 'CREATE TABLE %i LIKE %i', $table, $originalTable ) ) ) {
				throw new RuntimeException( 'Cannot create fixture table' );
			}
			$start = microtime( true ) + 3;
			for ( $i = 0; $i < 8; ++$i ) {
				$pipes = array();
				$process = proc_open( array( PHP_BINARY, __FILE__, 'worker', $prefix, (string) $start ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
				if ( ! is_resource( $process ) ) { throw new RuntimeException( 'Cannot start worker' ); }
				fclose( $pipes[0] );
				$workers[] = array( $process, $pipes );
			}
			foreach ( $workers as [ $process, $pipes ] ) {
				stream_get_contents( $pipes[1] );
				stream_get_contents( $pipes[2] );
				fclose( $pipes[1] ); fclose( $pipes[2] );
				if ( 0 !== proc_close( $process ) ) { throw new RuntimeException( 'Concurrent worker failed' ); }
			}
			$workers = array();
			$row = $db->get_row( $db->prepare( 'SELECT COUNT(*) AS sessions, SUM(requests_count) AS requests, SUM(watch_seconds) AS seconds FROM %i', $table ), ARRAY_A );
			$this->expect( '8 connections share one rolling session', 1, (int) $row['sessions'] );
			$this->expect( '80 concurrent request increments preserved', 80, (int) $row['requests'] );
			$this->expect( '4800 concurrent telemetry seconds preserved', 4800, (int) $row['seconds'] );
			$repo = new VideoAccessLogRepository( $db );
			$repo->touch( 1, 'student', null, 'other-bucket', 'same.webm', '', watchSeconds: 1 );
			$this->expect( 'same key in another bucket has its own session', 2, (int) $db->get_var( $db->prepare( 'SELECT COUNT(*) FROM %i', $table ) ) );
			$db->query( $db->prepare( "UPDATE %i SET last_at = '2000-01-01 00:00:00' WHERE s3_bucket = 'probe'", $table ) );
			$repo->touch( 1, 'student', 42, 'probe', 'same.webm', '', watchSeconds: 1 );
			$this->expect( 'expired rolling session starts another row', 3, (int) $db->get_var( $db->prepare( 'SELECT COUNT(*) FROM %i', $table ) ) );
			// Representative index plan on a larger, isolated history.
			$db->query( $db->prepare( "INSERT INTO %i (wp_user_id,user_role,s3_bucket,s3_key,requests_count,watch_seconds,ip,first_at,last_at) SELECT 1000 + a.n + 10*b.n + 100*c.n, '', 'probe', CONCAT('other-',a.n,b.n,c.n), 0,0,'',NOW(),NOW() FROM (SELECT 0 n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) a CROSS JOIN (SELECT 0 n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) b CROSS JOIN (SELECT 0 n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) c", $table ) );
			$db->query( $db->prepare( 'ANALYZE TABLE %i', $table ) );
			$plan = $db->get_row( $db->prepare( 'EXPLAIN SELECT id FROM %i WHERE wp_user_id = 1 AND s3_bucket = %s AND s3_key = %s AND last_at >= %s ORDER BY id DESC LIMIT 1', $table, 'probe', 'same.webm', '2026-01-01 00:00:00' ), ARRAY_A );
			$this->expect( 'existing user_key index bounds session lookup', 'user_key', $plan['key'] );
			echo 'PLAN: ' . json_encode( array_intersect_key( $plan, array_flip( array( 'type', 'key', 'rows', 'Extra' ) ) ) ) . PHP_EOL;
		} finally {
			foreach ( $workers as [ $process, $pipes ] ) {
				if ( is_resource( $process ) ) { proc_terminate( $process ); proc_close( $process ); }
			}
			$db->query( $db->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
			$db->prefix = $originalPrefix;
		}
	}
}

try { ( new VideoAccessDatabaseCheck() )->run( $argv ); }
catch ( Throwable $error ) { fwrite( STDERR, $error->getMessage() . PHP_EOL ); exit( 1 ); }
