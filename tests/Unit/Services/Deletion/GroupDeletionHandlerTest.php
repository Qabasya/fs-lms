<?php

declare( strict_types=1 );

namespace Unit\Services\Deletion;

use Inc\Contracts\LogEventDispatcherInterface;
use Inc\Enums\Log\LogEvent;
use Inc\Repositories\WPDBRepositories\AssessmentAnswerRepository;
use Inc\Repositories\WPDBRepositories\AssessmentAttemptRepository;
use Inc\Repositories\WPDBRepositories\AttendanceRepository;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\LessonProgressRepository;
use Inc\Repositories\WPDBRepositories\Log\LearningEventRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Repositories\WPDBRepositories\SubmissionRepository;
use Inc\Repositories\WPDBRepositories\SubstitutionRepository;
use Inc\Repositories\WPDBRepositories\TaskAttemptRepository;
use Inc\Repositories\WPDBRepositories\WorkTaskCheckRepository;
use Inc\Services\Course\ContentCloneService;
use Inc\Services\Deletion\DeleteGroupEvent;
use Inc\Services\Deletion\DeletionEventDispatcher;
use Inc\Services\Deletion\GroupDeletionHandler;
use Inc\Services\Shared\AvatarService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/** Удаление группы: шов `fs_lms_group_deleting` зовётся первым и не может сломать удаление. */
class GroupDeletionHandlerTest extends TestCase {

	private GroupsRepository&MockObject $groups;
	private GroupLessonRepository&MockObject $groupLessons;
	private StudentRecordRepository&MockObject $studentRecords;
	private LogEventDispatcherInterface&MockObject $logEvents;
	private GroupDeletionHandler $handler;

	/** @var list<string> Что и в каком порядке произошло: экшен шва и первое чтение данных группы. */
	private array $trace = array();

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_fs_test_actions'] = array();
		unset( $GLOBALS['_fs_test_action_throws'] );

		$this->groups         = $this->createMock( GroupsRepository::class );
		$this->groupLessons   = $this->createMock( GroupLessonRepository::class );
		$this->studentRecords = $this->createMock( StudentRecordRepository::class );
		$this->logEvents      = $this->createMock( LogEventDispatcherInterface::class );

		$this->groupLessons->method( 'listByGroup' )->willReturnCallback( function (): array {
			$this->trace[] = 'read: ' . implode( ',', array_column( $GLOBALS['_fs_test_actions'], 'hook' ) );

			return array();
		} );
		$this->studentRecords->method( 'deleteAllByGroupAndCollect' )->willReturn( array( 'students' => array(), 'parents' => array() ) );

		$this->handler = new GroupDeletionHandler(
			$this->studentRecords,
			$this->groups,
			$this->groupLessons,
			$this->createStub( AttendanceRepository::class ),
			$this->createStub( SubmissionRepository::class ),
			$this->createStub( LessonProgressRepository::class ),
			$this->createStub( TaskAttemptRepository::class ),
			$this->createStub( WorkTaskCheckRepository::class ),
			$this->createStub( LearningEventRepository::class ),
			$this->createStub( AssessmentAttemptRepository::class ),
			$this->createStub( AssessmentAnswerRepository::class ),
			$this->createStub( SubstitutionRepository::class ),
			$this->createStub( DeletionEventDispatcher::class ),
			$this->logEvents,
			$this->createStub( ContentCloneService::class ),
			$this->createStub( AvatarService::class ),
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['_fs_test_action_throws'] );
		$GLOBALS['_fs_test_actions'] = array();
		parent::tearDown();
	}

	public function test_without_subscribers_the_group_is_deleted_as_before(): void {
		$this->groups->expects( $this->once() )->method( 'hardDelete' )->with( 12 );
		$this->logEvents->expects( $this->once() )->method( 'dispatch' )->with( LogEvent::EntityHardDeleted, $this->anything() );

		$this->handler->handle( new DeleteGroupEvent( 12, 3 ) );
	}

	public function test_the_seam_fires_with_group_and_actor_before_any_data_is_read(): void {
		$this->handler->handle( new DeleteGroupEvent( 12, 3 ) );

		self::assertSame( 'fs_lms_group_deleting', $GLOBALS['_fs_test_actions'][0]['hook'] );
		self::assertSame( array( 12, 3 ), $GLOBALS['_fs_test_actions'][0]['args'] );
		self::assertSame( array( 'read: fs_lms_group_deleting' ), $this->trace, 'экшен — раньше первого чтения данных группы' );
	}

	public function test_a_failing_subscriber_does_not_stop_the_deletion(): void {
		$GLOBALS['_fs_test_action_throws']['fs_lms_group_deleting'] = new \RuntimeException( 'подписчик упал' );
		$this->groups->expects( $this->once() )->method( 'hardDelete' )->with( 12 );
		$this->logEvents->expects( $this->once() )->method( 'dispatch' )->with( LogEvent::EntityHardDeleted, $this->anything() );

		$this->handler->handle( new DeleteGroupEvent( 12, 3 ) );
	}
}
