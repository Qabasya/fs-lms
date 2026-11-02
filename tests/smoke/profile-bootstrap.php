<?php
/**
 * Read-only dev profiler of plugin bootstrap cost. Run: docker exec -i wp_app php < tests/smoke/profile-bootstrap.php
 * Attributes SQL fired during wp-load to the fs-lms plugin by the SAVEQUERIES caller stack.
 * Reports aggregate fingerprints only; never exports profile values or identities.
 */

declare( strict_types=1 );

define( 'SAVEQUERIES', true );
require $argv[1] ?? '/var/www/html/wp-load.php';

/** Analyzes the queries attributed to the plugin during the WordPress bootstrap. */
final class BootstrapProfileCheck {
	public function __construct( private readonly wpdb $database ) {}

	private function isPluginQuery( string $caller ): bool {
		return str_contains( $caller, 'plugins/fs-lms' ) || str_contains( $caller, 'fs-lms' );
	}

	/** Remove all literal values before grouping SQL; no SQL text leaves this process. */
	private function fingerprint( string $sql ): string {
		$sql = (string) preg_replace( "/'(?:[^'\\\\]|\\\\.)*'/s", '?', $sql );
		$sql = (string) preg_replace( '/\b\d+(?:\.\d+)?\b/', '?', $sql );
		return (string) preg_replace( '/\s+/', ' ', trim( $sql ) );
	}

	public function run(): array {
		$total   = count( $this->database->queries );
		$plugin  = array();
		$elapsed = 0.0;
		foreach ( $this->database->queries as $query ) {
			if ( ! $this->isPluginQuery( (string) ( $query[2] ?? '' ) ) ) {
				continue;
			}
			$plugin[] = $query;
			$elapsed += (float) $query[1];
		}
		$shapes = array();
		$options = array();
		foreach ( $plugin as $query ) {
			$key = hash( 'sha256', $this->fingerprint( $query[0] ) );
			if ( preg_match( '/option_name\s*=\s*\'([a-zA-Z0-9_]+)\'/i', $query[0], $m ) ) {
				$options[ $m[1] ] = ( $options[ $m[1] ] ?? 0 ) + 1;
			}
			if ( ! isset( $shapes[ $key ] ) ) {
				preg_match_all( '/\b(?:FROM|JOIN)\s+`?([a-zA-Z0-9_]+)/i', $query[0], $tables );
				// Keep only the fs-lms frames of the caller stack to describe the origin without leaking data.
				$frames = array_values(
					array_filter(
						array_map( 'trim', explode( ',', (string) $query[2] ) ),
						static fn( string $frame ): bool => str_contains( $frame, 'fs-lms' )
					)
				);
				$frames = array_map(
					static fn( string $frame ): string => (string) preg_replace( '/\/[^,\']*?\/([^\/\'()]+)$/', '\$1', $frame ),
					$frames
				);
				$shapes[ $key ] = array(
					'count'  => 0,
					'tables' => array_values( array_unique( $tables[1] ) ),
					'origin' => $frames ? ( string ) preg_replace( '/\s+/', ' ', end( $frames ) ) : '',
				);
			}
			$shapes[ $key ]['count']++;
		}
		uasort( $shapes, static fn( array $a, array $b ): int => $b['count'] <=> $a['count'] );
		return array(
			'total_queries'        => $total,
			'plugin_queries'       => count( $plugin ),
			'plugin_sql_ms'        => round( $elapsed * 1000, 3 ),
			'repeated_fingerprints'=> count( array_filter( $shapes, static fn( array $s ): bool => $s['count'] > 1 ) ),
			'option_reads'         => $options,
			'shapes'               => array_values( array_slice( $shapes, 0, 15, true ) ),
			'wp_debug'             => defined( 'WP_DEBUG' ) && WP_DEBUG,
		);
	}
}

try {
	$report = ( new BootstrapProfileCheck( $GLOBALS['wpdb'] ) )->run();
	echo wp_json_encode( $report, JSON_PRETTY_PRINT ) . PHP_EOL;
} catch ( Throwable $error ) {
	fwrite( STDERR, get_class( $error ) . ': ' . $error->getMessage() . PHP_EOL );
	exit( 1 );
}
