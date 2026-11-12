<?php

declare( strict_types=1 );

namespace Unit\Repositories;

use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use PHPUnit\Framework\TestCase;

class GroupLessonRankingReadTest extends TestCase {
	public function test_query_error_is_not_treated_as_no_assignments(): void {
		$db = $this->createMock( \wpdb::class );
		$db->last_error = 'query failed';
		$db->method( 'prepare' )->willReturn( 'SELECT' );
		$db->method( 'get_results' )->willReturn( array() );
		$this->expectException( \RuntimeException::class );
		( new GroupLessonRepository( $db ) )->listBySubject( 'math' );
	}
}
