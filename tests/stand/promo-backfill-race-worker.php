<?php
/** Worker for the isolated parallel backfill stand check. */
declare( strict_types=1 );
require dirname( __DIR__, 5 ) . '/wp-load.php';
global $wpdb;
$wpdb->prefix = $argv[1];
$gate = $argv[2];
$deadline = microtime( true ) + 15;
while ( ! file_exists( $gate ) && microtime( true ) < $deadline ) {
	usleep( 10000 );
}
if ( ! file_exists( $gate ) ) {
	exit( 2 );
}
try {
	( new \Inc\Modules\Masterclass\Repositories\PromoBackfillRepository( $wpdb ) )->copyBatch( (int) $argv[3], 1 );
} catch ( Throwable $e ) {
	fwrite( STDERR, $e->getMessage() . "\n" );
	exit( 1 );
}
