<?php

declare( strict_types=1 );

namespace Tests\Unit\Services\Profile;

use Inc\Repositories\WPDBRepositories\RankingSnapshotTransaction;
use PHPUnit\Framework\TestCase;

/** Эмуляция сервера, не поддерживающего @@session.in_transaction (MySQL). */
class RankingSnapshotTransactionTest extends TestCase {
	public function test_unknown_transaction_state_never_starts_transaction(): void {
		$db = $this->createMock( \wpdb::class );
		$db->expects( self::once() )->method( 'get_var' )->with( 'SELECT @@session.in_transaction' )->willReturn( null );
		$db->expects( self::never() )->method( 'query' );
		$called = false;
		try {
			( new RankingSnapshotTransaction( $db ) )->read( static function () use ( &$called ): array {
				$called = true;
				return array();
			} );
			self::fail( 'Неизвестное состояние не должно разрешать транзакцию.' );
		} catch ( \RuntimeException $e ) {
			self::assertFalse( $called );
			self::assertStringContainsString( 'сервер БД не позволяет проверить активную транзакцию', $e->getMessage() );
		}
	}

	public function test_active_transaction_is_reported_separately(): void {
		$db = $this->createMock( \wpdb::class );
		$db->method( 'get_var' )->willReturn( '1' );
		$db->expects( self::never() )->method( 'query' );
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'внутри другой транзакции' );
		( new RankingSnapshotTransaction( $db ) )->read( static fn(): array => array() );
	}
}
