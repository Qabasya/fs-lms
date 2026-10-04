<?php

declare( strict_types=1 );

namespace Tests\Unit\Services\Exam;

use Inc\Repositories\WPDBRepositories\ExamLockRepository;
use Inc\Services\Exam\ExamTickLock;
use PHPUnit\Framework\TestCase;

class ExamTickLockTest extends TestCase {

	public function test_runs_callable_when_lock_acquired_and_releases_it(): void {
		$locks = $this->createMock( ExamLockRepository::class );
		$locks->expects( self::once() )->method( 'acquire' )->with( 'tick' )->willReturn( true );
		$locks->expects( self::once() )->method( 'release' )->with( 'tick' );
		$called = false;

		$ran = ( new ExamTickLock( $locks ) )->run( 'tick', static function () use ( &$called ): void {
			$called = true;
		} );

		self::assertTrue( $ran );
		self::assertTrue( $called );
	}

	public function test_skips_callable_when_lock_busy(): void {
		$locks = $this->createMock( ExamLockRepository::class );
		$locks->method( 'acquire' )->willReturn( false );
		$locks->expects( self::never() )->method( 'release' );
		$called = false;

		$ran = ( new ExamTickLock( $locks ) )->run( 'tick', static function () use ( &$called ): void {
			$called = true;
		} );

		self::assertFalse( $ran );
		self::assertFalse( $called, 'Параллельный запуск тела не выполняет.' );
	}

	public function test_releases_lock_when_callable_throws_and_does_not_rethrow(): void {
		$locks = $this->createMock( ExamLockRepository::class );
		$locks->method( 'acquire' )->willReturn( true );
		$locks->expects( self::once() )->method( 'release' )->with( 'tick' );

		$ran = ( new ExamTickLock( $locks ) )->run( 'tick', static function (): void {
			throw new \RuntimeException( 'сбой тика' );
		} );

		self::assertTrue( $ran, 'Исключение тела логируется, cron не падает.' );
	}

	public function test_exception_is_logged_not_thrown(): void {
		$locks = $this->createMock( ExamLockRepository::class );
		$locks->method( 'acquire' )->willReturn( true );
		$log   = tempnam( sys_get_temp_dir(), 'ticklog' );
		$old   = ini_set( 'error_log', $log );

		try {
			( new ExamTickLock( $locks ) )->run( 'tick', static function (): void {
				throw new \RuntimeException( 'сбой тика' );
			} );
			$logged = (string) file_get_contents( $log );
		} finally {
			ini_set( 'error_log', (string) $old );
			@unlink( $log );
		}

		self::assertStringContainsString( 'ExamTick', $logged );
		self::assertStringContainsString( 'сбой тика', $logged );
	}
}
