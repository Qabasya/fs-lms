<?php

declare( strict_types=1 );

namespace Unit\Services\Group;

use Inc\Contracts\LogEventDispatcherInterface;
use Inc\Enums\Log\LogEvent;
use Inc\Managers\Course\LessonManager;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Services\Group\ProgramCompositionService;
use Inc\Services\Group\ScheduleEventPublisher;
use PHPUnit\Framework\TestCase;
use Tests\Support\GroupLessonFixtures;

/**
 * Состав программы группы: темы, порядок, продолжения и события.
 */
class ProgramCompositionServiceTest extends TestCase {

	use GroupLessonFixtures;

	private GroupLessonRepository&\PHPUnit\Framework\MockObject\MockObject $groupLessons;
	private LessonManager&\PHPUnit\Framework\MockObject\MockObject $lessonManager;
	private GroupsRepository&\PHPUnit\Framework\MockObject\MockObject $groups;
	private LogEventDispatcherInterface&\PHPUnit\Framework\MockObject\MockObject $dispatcher;
	private ProgramCompositionService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->groupLessons  = $this->createMock( GroupLessonRepository::class );
		$this->lessonManager = $this->createMock( LessonManager::class );
		$this->groups        = $this->createMock( GroupsRepository::class );
		$this->dispatcher    = $this->createMock( LogEventDispatcherInterface::class );

		$this->service = new ProgramCompositionService(
			$this->groupLessons,
			$this->lessonManager,
			$this->groups,
			new ScheduleEventPublisher( $this->dispatcher ),
		);
	}

	public function test_get_program_excludes_individual_lessons(): void {
		$group      = $this->makeRow( 42, 'group' );
		$individual = $this->makeRow( 43, 'individual' );
		$this->groupLessons->method( 'listByGroup' )->with( 5 )->willReturn( array( $group, $individual ) );
		$this->lessonManager->method( 'get' )->willReturn( $this->makeLesson( 'inf' ) );

		$program = $this->service->getProgram( 5 );

		self::assertCount( 1, $program );
		self::assertSame( 42, $program[0]['row']->id );
	}

	public function test_get_program_returns_rows_with_topics(): void {
		$row = $this->makeRow();
		$this->groupLessons->method( 'listByGroup' )->with( 5 )->willReturn( array( $row ) );
		$this->lessonManager->method( 'get' )->willReturn( $this->makeLesson( 'inf' ) );

		$program = $this->service->getProgram( 5 );

		self::assertCount( 1, $program );
		self::assertSame( 'Test lesson', $program[0]['topic'] );
		self::assertSame( $row, $program[0]['row'] );
	}

	public function test_continue_lesson_creates_pinned_row_with_link(): void {
		$this->groupLessons->method( 'find' )->willReturn( $this->makeRow( 42, 'group', null, null, 10 ) );
		$this->groupLessons->method( 'nextPosition' )->willReturn( 3 );
		$this->lessonManager->method( 'get' )->willReturn( $this->makeLesson( 'inf' ) );
		$this->groupLessons->expects( self::once() )->method( 'add' )
			->with( self::callback(
				static fn( $dto ) => true === $dto->isPinned && 42 === $dto->continuedFromId && null === $dto->scheduledAt
			) )
			->willReturn( 43 );

		self::assertSame( 43, $this->service->continueLesson( 42, 99 ) );
	}

	public function test_continue_lesson_rejects_continuing_a_continuation(): void {
		$this->groupLessons->method( 'find' )->willReturn( $this->makeRow( 11, 'group', null, 10 ) );
		$this->groupLessons->expects( self::never() )->method( 'add' );

		self::assertSame( 0, $this->service->continueLesson( 11, 99 ) );
	}

	public function test_continue_lesson_returns_zero_when_not_found(): void {
		$this->groupLessons->method( 'find' )->willReturn( null );
		$this->groupLessons->expects( self::never() )->method( 'add' );

		self::assertSame( 0, $this->service->continueLesson( 999, 1 ) );
	}

	public function test_number_themes_pairs_continuation_with_origin(): void {
		$entries = array(
			array( 'row' => $this->makeRow( 10, 'group', null, null ), 'topic' => '', 'subject' => '' ),
			array( 'row' => $this->makeRow( 11, 'group', null, 10 ), 'topic' => '', 'subject' => '' ),
			array( 'row' => $this->makeRow( 12, 'group', null, null ), 'topic' => '', 'subject' => '' ),
		);

		$numbered = $this->service->numberThemes( $entries );

		self::assertSame( array( 1, 1, 2 ), array_column( $numbered, 'n' ) );
		self::assertSame( array( 1, 2, 1 ), array_column( $numbered, 'part' ) );
		self::assertSame( array( 2, 2, 1 ), array_column( $numbered, 'totalParts' ) );
	}

	public function test_publish_program_locks_and_dispatches(): void {
		$this->groups->expects( self::once() )->method( 'setProgramLocked' )->with( 5, self::anything() );
		$this->dispatcher->expects( self::once() )
			->method( 'dispatch' )
			->with( LogEvent::ScheduleChanged, self::anything() );

		$this->service->publishProgram( 5, 99 );
	}

	public function test_unpublish_program_clears_lock(): void {
		$this->groups->expects( self::once() )->method( 'setProgramLocked' )->with( 5, null );

		$this->service->unpublishProgram( 5, 99 );
	}

	public function test_is_program_locked_reads_group_flag(): void {
		$group                    = new \stdClass();
		$group->program_locked_at = '2026-05-20 10:00:00';
		$this->groups->method( 'findById' )->willReturn( $group );

		self::assertTrue( $this->service->isProgramLocked( 5 ) );
		self::assertSame( '2026-05-20 10:00:00', $this->service->programLockedAt( 5 ) );
	}

	private function setupGroupAndLesson( string $groupSubject, string $lessonSubject ): void {
		$group              = new \stdClass();
		$group->subject_key = $groupSubject;
		$this->groups->method( 'findById' )->willReturn( $group );
		$this->lessonManager->method( 'get' )->willReturn( $this->makeLesson( $lessonSubject ) );
	}
}
