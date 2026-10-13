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
}
