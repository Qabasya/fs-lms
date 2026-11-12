<?php

declare( strict_types=1 );

namespace Tests\Unit\Services\Settings;

use Inc\Repositories\OptionsRepositories\PluginConfigRepository;
use PHPUnit\Framework\TestCase;

class PluginConfigConcurrencyTest extends TestCase {
	public function test_failed_option_write_is_reported(): void {
		$db = $this->createMock( \wpdb::class );
		$db->method( 'prepare' )->willReturnCallback( static fn( string $sql, ...$args ): string => $sql );
		$db->method( 'get_var' )->willReturnCallback( static fn( string $sql ): mixed => str_contains( $sql, 'DATABASE()' ) ? 'wordpress' : '1' );
		$GLOBALS['_test_fail_update_option'] = true;
		try {
			$this->expectException( \RuntimeException::class );
			( new PluginConfigRepository() )->mutate( static fn( array $current ): array => array_merge( $current, array( 'ranking_practice' => 987.0 ) ), $db );
		} finally {
			unset( $GLOBALS['_test_fail_update_option'] );
		}
	}
	public function test_failed_lock_does_not_write_configuration(): void {
		$db = $this->createMock( \wpdb::class );
		$db->method( 'prepare' )->willReturnCallback( static fn( string $sql, ...$args ): string => $sql );
		$db->method( 'get_var' )->willReturnCallback( static fn( string $sql ): mixed => str_contains( $sql, 'DATABASE()' ) ? 'wordpress' : '0' );
		$repository = new PluginConfigRepository();
		$before = $repository->get();
		try {
			$repository->mutate( static fn( array $current ): array => array_merge( $current, array( 'ranking_practice' => 99.0 ) ), $db );
			self::fail( 'The lock must be required.' );
		} catch ( \RuntimeException $error ) {
			self::assertSame( $before, $repository->get() );
		}
	}
	public function test_save_acquires_and_releases_lock_and_preserves_current_configuration(): void {
		$before = $GLOBALS['_test_options'] ?? array();
		try {
			$db = $this->createMock( \wpdb::class );
			$db->method( 'prepare' )->willReturnCallback( static fn( string $sql, ...$args ): string => $sql );
			$db->expects( self::exactly( 3 ) )->method( 'get_var' )->willReturnCallback( static function ( string $sql ): mixed {
				return str_contains( $sql, 'DATABASE()' ) ? 'wordpress' : '1';
			} );
			$repository = new PluginConfigRepository();
			$repository->mutate( static fn( array $current ): array => array_merge( $current, array( 'exam_hold_minutes' => 35, 'ranking_practice' => 2.0 ) ), $db );
			self::assertSame( 35, $repository->get()['exam_hold_minutes'] );
			self::assertSame( 2.0, $repository->get()['ranking_practice'] );
		} finally {
			$GLOBALS['_test_options'] = $before;
		}
	}
}
