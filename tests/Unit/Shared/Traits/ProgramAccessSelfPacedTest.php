<?php

declare( strict_types=1 );

namespace Unit\Shared\Traits;

use Inc\Services\Course\GroupAccessGuard;
use Inc\Services\Group\ProgramCompositionService;
use Inc\Shared\Traits\ProgramAccess;
use PHPUnit\Framework\TestCase;

class ProgramAccessSelfPacedTest extends TestCase {
	public function test_crafted_service_group_request_is_denied_before_staff_fast_allow(): void {
		$guard = $this->createMock( GroupAccessGuard::class );
		$guard->method( 'isSelfPacedGroup' )->with( 7 )->willReturn( true );
		$guard->expects( self::never() )->method( 'canManage' );
		$program = $this->createMock( ProgramCompositionService::class );
		$callback = new class( $guard, $program ) {
			use ProgramAccess;
			public function __construct( private GroupAccessGuard $guard, private ProgramCompositionService $program ) {}
			protected function accessGuard(): GroupAccessGuard { return $this->guard; }
			protected function programService(): ProgramCompositionService { return $this->program; }
			public function run( int $groupId ): void { $this->requireGroupAccess( $groupId ); }
			protected function error( string $message ): never { throw new \RuntimeException( $message ); }
		};
		$this->expectException( \RuntimeException::class );
		$callback->run( 7 );
	}
}
