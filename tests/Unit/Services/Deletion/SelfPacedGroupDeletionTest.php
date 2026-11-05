<?php
declare( strict_types=1 );
namespace Unit\Services\Deletion;
use Inc\Services\Deletion\DeleteGroupEvent;
use Inc\Services\Deletion\GroupDeletionHandler;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use PHPUnit\Framework\TestCase;
class SelfPacedGroupDeletionTest extends TestCase {
	public function test_direct_group_event_cannot_delete_service_group_or_any_history(): void {
		$groups = $this->createMock( GroupsRepository::class );
		$groups->method( 'findById' )->willReturn( (object) array( 'self_paced_course_id' => 15 ) );
		$groups->expects( self::never() )->method( 'hardDelete' );
		$records = $this->createMock( StudentRecordRepository::class );
		$records->expects( self::never() )->method( 'deleteAllByGroupAndCollect' );
		$lessons = $this->createMock( GroupLessonRepository::class );
		$lessons->expects( self::never() )->method( 'deleteAllByGroup' );
		$lessons->expects( self::never() )->method( 'listByGroup' );
		$dependencies = array();
		foreach ( ( new \ReflectionClass( GroupDeletionHandler::class ) )->getConstructor()->getParameters() as $parameter ) {
			$type = $parameter->getType()->getName();
			$dependencies[] = match ( $type ) {
				GroupsRepository::class => $groups,
				StudentRecordRepository::class => $records,
				GroupLessonRepository::class => $lessons,
				default => $this->createMock( $type ),
			};
		}
		$this->expectException( \DomainException::class );
		$this->expectExceptionMessage( 'историю прохождения' );
		( new GroupDeletionHandler( ...$dependencies ) )->handle( new DeleteGroupEvent( 27, 7 ) );
	}
}
