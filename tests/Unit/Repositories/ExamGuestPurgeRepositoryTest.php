<?php

declare( strict_types=1 );

namespace Tests\Unit\Repositories;

use Inc\Enums\Exam\ManualResolutionKind;
use Inc\Enums\Settings\TableName;
use Inc\Repositories\WPDBRepositories\ExamGuestPurgeRepository;
use PHPUnit\Framework\TestCase;

class ExamGuestPurgeRepositoryTest extends TestCase {
	public function test_free_acceptance_is_excluded_from_payment_resolution_blocker(): void {
		$db = $this->createMock( \wpdb::class );
		$db->method( 'get_col' )->willReturn( array( 1 ) );
		$queries = array();
		$db->method( 'prepare' )->willReturnCallback( static function ( string $sql, ...$args ) use ( &$queries ): string {
			$queries[] = array( $sql, $args );
			return $sql;
		} );
		$db->method( 'get_var' )->willReturn( 0 );

		self::assertNull( ( new ExamGuestPurgeRepository( $db ) )->blocker( 1 ) );
		$resolutionQueries = array_filter( $queries, static fn( array $query ): bool => in_array( TableName::ExamManualResolutions->prefixed(), $query[1], true ) );
		self::assertCount( 2, $resolutionQueries );
		foreach ( $resolutionQueries as $query ) {
			self::assertStringContainsString( 'kind <> %s', $query[0] );
			self::assertContains( ManualResolutionKind::AcceptedManually->value, $query[1] );
		}
	}
}
