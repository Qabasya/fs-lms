<?php
declare( strict_types=1 );
namespace Unit\Services\Deletion;
use Inc\Contracts\LogEventDispatcherInterface;
use Inc\Repositories\OptionsRepositories\AcademicPeriodRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Services\Deletion\DeletePeriodEvent;
use Inc\Services\Deletion\DeletionEventDispatcher;
use Inc\Services\Deletion\PeriodDeletionCascadeHandler;
use PHPUnit\Framework\TestCase;
class PeriodDeletionCascadeHandlerTest extends TestCase {
	public function test_service_group_second_blocks_all_changes_to_period_and_ordinary_groups(): void {
		$groups = $this->createMock( GroupsRepository::class );
		$groups->method( 'withSelfPacedLifecycleLock' )->willReturnCallback( static fn( callable $fn ): mixed => $fn() );
		$groups->method( 'findByPeriodId' )->willReturn( array( (object) array( 'id' => 1 ), (object) array( 'id' => 27, 'self_paced_course_id' => 15 ) ) );
		$periods = $this->createMock( AcademicPeriodRepository::class );
		$periods->expects( self::never() )->method( 'remove' );
		$dispatcher = $this->createMock( DeletionEventDispatcher::class );
		$dispatcher->expects( self::never() )->method( 'dispatch' );
		$logs = $this->createMock( LogEventDispatcherInterface::class );
		$logs->expects( self::never() )->method( 'dispatch' );
		$this->expectException( \DomainException::class );
		( new PeriodDeletionCascadeHandler( $groups, $periods, $dispatcher, $logs ) )->handle( new DeletePeriodEvent( '2026', 7 ) );
	}
}
