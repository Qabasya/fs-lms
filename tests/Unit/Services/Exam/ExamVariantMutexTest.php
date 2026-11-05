<?php

declare( strict_types=1 );

namespace Tests\Unit\Services\Exam;

use Inc\Repositories\WPDBRepositories\ExamLockRepository;
use Inc\Services\Exam\ExamVariantMutex;
use PHPUnit\Framework\TestCase;

class ExamVariantMutexTest extends TestCase {
	public function test_releases_lock_when_operation_fails(): void {
		$locks = $this->createMock( ExamLockRepository::class );
		$locks->expects( self::once() )->method( 'acquire' )->with( 'exam_variant_snapshot', 10 )->willReturn( true );
		$locks->expects( self::once() )->method( 'release' )->with( 'exam_variant_snapshot' );
		$this->expectExceptionMessage( 'failed' );
		( new ExamVariantMutex( $locks ) )->run( static function (): void { throw new \RuntimeException( 'failed' ); } );
	}

	public function test_nested_operation_uses_same_lock(): void {
		$locks = $this->createMock( ExamLockRepository::class );
		$locks->expects( self::once() )->method( 'acquire' )->willReturn( true );
		$locks->expects( self::once() )->method( 'release' );
		$mutex = new ExamVariantMutex( $locks );
		self::assertSame( 42, $mutex->run( fn(): mixed => $mutex->run( static fn(): int => 42 ) ) );
	}
}
