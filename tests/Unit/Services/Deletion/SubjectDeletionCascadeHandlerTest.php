<?php

declare( strict_types=1 );

namespace Unit\Services\Deletion;

use Inc\Contracts\LogEventDispatcherInterface;
use Inc\Enums\Settings\AvatarEntity;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\RoomRepository;
use Inc\Services\Deletion\DeleteSubjectEvent;
use Inc\Services\Deletion\DeletionEventDispatcher;
use Inc\Services\Deletion\SubjectDeletionCascadeHandler;
use Inc\Services\Shared\AvatarService;
use Inc\Services\Subject\SubjectDeletionService;
use Inc\Services\Subject\SubjectPagesService;
use PHPUnit\Framework\TestCase;

class SubjectDeletionCascadeHandlerTest extends TestCase {

	public function test_subject_avatar_is_removed_with_the_subject(): void {
		$groups = $this->createMock( GroupsRepository::class );
		$groups->method( 'withSelfPacedLifecycleLock' )->willReturnCallback( static fn( callable $fn ): mixed => $fn() );
		$groups->method( 'findBySubjectKey' )->willReturn( array() );

		$avatars = $this->createMock( AvatarService::class );
		$avatars->expects( $this->once() )->method( 'set' )->with( AvatarEntity::Subject, 'inf', 0 )->willReturn( true );

		$handler = new SubjectDeletionCascadeHandler(
			$groups,
			$this->createMock( SubjectDeletionService::class ),
			$this->createMock( SubjectRepository::class ),
			$this->createMock( RoomRepository::class ),
			$this->createMock( DeletionEventDispatcher::class ),
			$this->createMock( LogEventDispatcherInterface::class ),
			$this->createMock( SubjectPagesService::class ),
			$avatars,
		);

		$handler->handle( new DeleteSubjectEvent( 'inf', 1 ) );
	}
	public function test_service_group_second_in_inventory_blocks_entire_subject_cascade(): void {
		$groups = $this->createMock( GroupsRepository::class );
		$groups->method( 'withSelfPacedLifecycleLock' )->willReturnCallback( static fn( callable $fn ): mixed => $fn() );
		$groups->method( 'findBySubjectKey' )->willReturn( array( (object) array( 'id' => 1 ), (object) array( 'id' => 27, 'self_paced_course_id' => 15 ) ) );
		$dispatcher = $this->createMock( DeletionEventDispatcher::class );
		$dispatcher->expects( self::never() )->method( 'dispatch' );
		$deletion = $this->createMock( SubjectDeletionService::class );
		$deletion->expects( self::never() )->method( 'deleteWithCascade' );
		$subjects = $this->createMock( SubjectRepository::class );
		$subjects->expects( self::never() )->method( 'remove' );
		$pages = $this->createMock( SubjectPagesService::class );
		$pages->expects( self::never() )->method( 'trashForSubject' );
		$handler = new SubjectDeletionCascadeHandler( $groups, $deletion, $subjects, $this->createMock( RoomRepository::class ), $dispatcher, $this->createMock( LogEventDispatcherInterface::class ), $pages, $this->createMock( AvatarService::class ) );
		$this->expectException( \DomainException::class );
		$handler->handle( new DeleteSubjectEvent( 'inf', 7 ) );
	}

}
