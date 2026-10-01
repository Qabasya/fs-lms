<?php

declare( strict_types=1 );

namespace Unit\Services\Group;

use Inc\Contracts\LogEventDispatcherInterface;
use Inc\Enums\Log\LogEvent;
use Inc\Managers\Course\LessonManager;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Services\Course\GroupLessonUsageGuard;
use Inc\Services\Group\ProgramCompositionService;
use Inc\Services\Group\ScheduleEventPublisher;
use Inc\Services\Group\ScheduleReflowService;
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
	private ScheduleReflowService&\PHPUnit\Framework\MockObject\MockObject $schedule;
	private GroupLessonUsageGuard&\PHPUnit\Framework\MockObject\MockObject $usage;
	private ProgramCompositionService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->groupLessons  = $this->createMock( GroupLessonRepository::class );
		$this->lessonManager = $this->createMock( LessonManager::class );
		$this->groups        = $this->createMock( GroupsRepository::class );
		$this->dispatcher    = $this->createMock( LogEventDispatcherInterface::class );
		$this->schedule      = $this->createMock( ScheduleReflowService::class );
		$this->usage         = $this->createMock( GroupLessonUsageGuard::class );

		$this->service = new ProgramCompositionService(
			$this->groupLessons,
			$this->lessonManager,
			$this->groups,
			new ScheduleEventPublisher( $this->dispatcher ),
			$this->schedule,
			$this->usage,
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

	public function test_continue_lesson_inserts_unpinned_row_right_after_origin(): void {
		$this->groupLessons->method( 'find' )->willReturn( $this->makeRow( 42, 'group', null, null, 10 ) ); // position 0
		$this->lessonManager->method( 'get' )->willReturn( $this->makeLesson( 'inf' ) );

		$this->groupLessons->expects( self::once() )->method( 'shiftPositions' )->with( 5, 1 );
		$this->groupLessons->expects( self::once() )->method( 'add' )
			->with( self::callback(
				static fn( $dto ) => false === $dto->isPinned && 42 === $dto->continuedFromId
					&& 1 === $dto->position && null === $dto->scheduledAt
			) )
			->willReturn( 43 );
		// Исходная тема без даты — продолжение ждёт в пуле вместе с ней.
		$this->schedule->expects( self::never() )->method( 'placeInserted' );

		self::assertSame( 43, $this->service->continueLesson( 42, 99 ) );
	}

	public function test_continue_scheduled_lesson_takes_next_session(): void {
		$origin = new \Inc\DTO\Course\GroupLessonDTO(
			id: 42, groupId: 5, lessonId: 10, position: 6, workIdsSnapshot: null, extraWorkIds: array(),
			scheduledAt: '2026-10-08 10:00:00', endsAt: null, isPinned: false, teacherUserId: null,
			visibility: 'hidden', openedAt: null, homeworkDueAt: null, allowLate: true, recordingUrl: null,
			createdByUserId: null, updatedByUserId: null,
		);
		$this->groupLessons->method( 'find' )->willReturn( $origin );
		$this->groupLessons->method( 'add' )->willReturn( 43 );

		$this->schedule->expects( self::once() )->method( 'placeInserted' )->with( 43, 99 );

		self::assertSame( 43, $this->service->continueLesson( 42, 99 ) );
	}

	public function test_continue_lesson_rejects_continuing_a_continuation(): void {
		$this->groupLessons->method( 'find' )->willReturn( $this->makeRow( 11, 'group', null, 10 ) );
		$this->groupLessons->expects( self::never() )->method( 'add' );

		self::assertSame( 0, $this->service->continueLesson( 11, 99 ) );
	}

	/** Повторный клик не плодит вторую копию: у темы уже есть продолжение. */
	public function test_continue_lesson_rejects_second_continuation_of_same_origin(): void {
		$this->groupLessons->method( 'find' )->willReturn( $this->makeRow( 42, 'group' ) );
		$this->groupLessons->method( 'listByGroup' )->willReturn( array(
			$this->makeRow( 42, 'group' ),
			$this->makeRow( 43, 'group', null, 42 ),
		) );
		$this->groupLessons->expects( self::never() )->method( 'add' );
		$this->groupLessons->expects( self::never() )->method( 'shiftPositions' );

		self::assertSame( 0, $this->service->continueLesson( 42, 99 ) );
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

	/* ── Убрать продолжение ─────────────────────────────────────────────── */

	private function factRow( int $id, ?int $continuedFromId ): \Inc\DTO\Course\GroupLessonDTO {
		return new \Inc\DTO\Course\GroupLessonDTO(
			id: $id, groupId: 5, lessonId: 10, position: 1, workIdsSnapshot: null, extraWorkIds: array(),
			scheduledAt: '2026-10-08 10:00:00', endsAt: null, isPinned: false, teacherUserId: null,
			visibility: 'hidden', openedAt: null, homeworkDueAt: null, allowLate: true, recordingUrl: null,
			createdByUserId: null, updatedByUserId: null, continuedFromId: $continuedFromId, hasAttendance: true,
		);
	}

	public function test_remove_continuation_frees_the_slot_then_deletes_the_row(): void {
		$this->groupLessons->method( 'find' )->willReturn( $this->makeRow( 43, 'group', null, 42 ) );
		$this->lessonManager->method( 'get' )->willReturn( $this->makeLesson( 'inf' ) );
		$this->usage->method( 'isSafeToRemove' )->willReturn( true );

		// Окно освобождается и хвост подтягивается ДО удаления строки.
		$calls = array();
		$this->schedule->expects( self::once() )->method( 'returnToPool' )->with( 43, 99 )
			->willReturnCallback( function () use ( &$calls ): int { $calls[] = 'pool'; return 2; } );
		$this->groupLessons->expects( self::once() )->method( 'remove' )->with( 43 )
			->willReturnCallback( function () use ( &$calls ): bool { $calls[] = 'remove'; return true; } );
		$this->dispatcher->expects( self::once() )->method( 'dispatch' )->with( LogEvent::LessonRemovedFromProgram, self::anything() );

		$this->service->removeContinuation( 43, 99 );

		self::assertSame( array( 'pool', 'remove' ), $calls );
	}

	public function test_remove_continuation_refuses_an_origin_row(): void {
		$this->groupLessons->method( 'find' )->willReturn( $this->makeRow( 42, 'group' ) );
		$this->groupLessons->expects( self::never() )->method( 'remove' );

		$this->expectException( \InvalidArgumentException::class );
		$this->service->removeContinuation( 42, 99 );
	}

	public function test_remove_continuation_refuses_a_held_lesson(): void {
		$this->groupLessons->method( 'find' )->willReturn( $this->factRow( 43, 42 ) );
		$this->groupLessons->expects( self::never() )->method( 'remove' );
		$this->schedule->expects( self::never() )->method( 'returnToPool' );

		$this->expectException( \InvalidArgumentException::class );
		$this->service->removeContinuation( 43, 99 );
	}

	public function test_remove_continuation_refuses_when_students_already_have_data(): void {
		$this->groupLessons->method( 'find' )->willReturn( $this->makeRow( 43, 'group', null, 42 ) );
		$this->usage->method( 'isSafeToRemove' )->willReturn( false );
		$this->groupLessons->expects( self::never() )->method( 'remove' );

		$this->expectException( \InvalidArgumentException::class );
		$this->service->removeContinuation( 43, 99 );
	}

	public function test_remove_continuation_refuses_unknown_row(): void {
		$this->groupLessons->method( 'find' )->willReturn( null );

		$this->expectException( \InvalidArgumentException::class );
		$this->service->removeContinuation( 999, 99 );
	}

	/** Старые данные: у темы две продолжения — обе видны в программе, части нумеруются подряд. */
	public function test_number_themes_keeps_every_continuation_of_one_origin(): void {
		$entries = array(
			array( 'row' => $this->makeRow( 10, 'group', null, null ), 'topic' => '', 'subject' => '' ),
			array( 'row' => $this->makeRow( 11, 'group', null, 10 ), 'topic' => '', 'subject' => '' ),
			array( 'row' => $this->makeRow( 12, 'group', null, 10 ), 'topic' => '', 'subject' => '' ),
		);

		$numbered = $this->service->numberThemes( $entries );

		self::assertCount( 3, $numbered );
		self::assertSame( array( 10, 11, 12 ), array_map( static fn( $e ) => $e['row']->id, $numbered ) );
		self::assertSame( array( 1, 2, 3 ), array_column( $numbered, 'part' ) );
		self::assertSame( array( 3, 3, 3 ), array_column( $numbered, 'totalParts' ) );
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
