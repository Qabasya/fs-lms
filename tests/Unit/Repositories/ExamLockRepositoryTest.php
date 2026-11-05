<?php

declare( strict_types=1 );

namespace Tests\Unit\Repositories;

use Inc\Repositories\WPDBRepositories\ExamLockRepository;
use PHPUnit\Framework\TestCase;

class ExamLockRepositoryTest extends TestCase {
	public function test_active_transaction_is_rejected_without_committing_it(): void {
		$db = $this->createMock( \wpdb::class );
		$db->expects( self::once() )->method( 'get_var' )->with( 'SELECT @@session.in_transaction' )->willReturn( '1' );
		$db->expects( self::never() )->method( 'query' );
		$this->expectExceptionMessage( 'внутри другой транзакции' );
		( new ExamLockRepository( $db ) )->assertNoActiveTransaction();
	}

	public function test_unknown_transaction_state_fails_closed(): void {
		$db = $this->createMock( \wpdb::class );
		$db->method( 'get_var' )->willReturn( null );
		$this->expectExceptionMessage( 'проверить состояние транзакции' );
		( new ExamLockRepository( $db ) )->assertNoActiveTransaction();
	}
}
