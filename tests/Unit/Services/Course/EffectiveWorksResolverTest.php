<?php

declare( strict_types=1 );

namespace Unit\Services\Course;

use Inc\Contracts\LogEventDispatcherInterface;
use Inc\DTO\Course\GroupLessonDTO;
use Inc\DTO\Course\LessonDTO;
use Inc\DTO\Course\StepDTO;
use Inc\DTO\Course\WorkDTO;
use Inc\Enums\Course\StepType;
use Inc\Enums\Course\WorkType;
use Inc\Managers\Course\LessonManager;
use Inc\Managers\Course\WorkManager;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Services\Course\EffectiveWorksResolver;
use PHPUnit\Framework\TestCase;

class EffectiveWorksResolverTest extends TestCase {

	private GroupLessonRepository&\PHPUnit\Framework\MockObject\MockObject $groupLessons;
	private LessonManager&\PHPUnit\Framework\MockObject\MockObject $lessonManager;
	private WorkManager&\PHPUnit\Framework\MockObject\MockObject $workManager;
	private LogEventDispatcherInterface&\PHPUnit\Framework\MockObject\MockObject $dispatcher;
	private EffectiveWorksResolver $resolver;

	protected function setUp(): void {
		parent::setUp();
		$this->groupLessons  = $this->createMock( GroupLessonRepository::class );
		$this->lessonManager = $this->createMock( LessonManager::class );
		$this->workManager   = $this->createMock( WorkManager::class );
		$this->dispatcher    = $this->createMock( LogEventDispatcherInterface::class );
		$this->resolver      = new EffectiveWorksResolver(
			$this->groupLessons,
			$this->lessonManager,
			$this->workManager,
			$this->dispatcher,
		);
	}

	public function test_published_row_unions_snapshot_with_live_lesson(): void {
		// Bug 3: работа, добавленная в урок ПОСЛЕ публикации занятия, должна
		// попасть в эффективный набор — иначе плеер её рендерит (живой урок), а
		// сдача отклоняется. Снапшот [10,20] + живой урок [10,20,30] → [10,20,30].
		$row    = $this->makeRow( workIdsSnapshot: [ 10, 20 ], extraWorkIds: [] );
		$lesson = $this->makeLesson( workIds: [ 10, 20, 30 ] );
		$this->lessonManager->method( 'get' )->willReturn( $lesson );
		$this->workManager->method( 'get' )->willReturnCallback( fn( $id ) => $this->makeWork( $id ) );

		$works = $this->resolver->resolve( $row );

		$ids = array_map( static fn( $w ) => $w->id, $works );
		self::assertEqualsCanonicalizing( [ 10, 20, 30 ], $ids );
	}

	public function test_unpublished_row_uses_live_lesson_work_ids(): void {
		$row    = $this->makeRow( workIdsSnapshot: null, extraWorkIds: [] );
		$lesson = $this->makeLesson( workIds: [ 30, 40 ] );
		$this->lessonManager->method( 'get' )->willReturn( $lesson );
		$this->workManager->method( 'get' )->willReturnCallback( fn( $id ) => $this->makeWork( $id ) );

		$works = $this->resolver->resolve( $row );

		self::assertCount( 2, $works );
		self::assertSame( 30, $works[0]->id );
	}

	public function test_extra_work_ids_merged_and_deduplicated(): void {
		// snapshot has 10,20; extra has 20,30 → unique = 10,20,30
		$row = $this->makeRow( workIdsSnapshot: [ 10, 20 ], extraWorkIds: [ 20, 30 ] );
		$this->workManager->method( 'get' )->willReturnCallback( fn( $id ) => $this->makeWork( $id ) );

		$works = $this->resolver->resolve( $row );

		self::assertCount( 3, $works );
	}

	public function test_published_row_keeps_snapshot_work_removed_from_lesson(): void {
		// Обратная гарантия: работа, УДАЛЁННАЯ из урока после публикации, остаётся
		// сдаваемой — снапшот это надмножество, назначенное не теряем. Снапшот
		// [10,20], живой урок теперь только [10] → результат всё ещё [10,20].
		$row    = $this->makeRow( workIdsSnapshot: [ 10, 20 ], extraWorkIds: [] );
		$lesson = $this->makeLesson( workIds: [ 10 ] );
		$this->lessonManager->method( 'get' )->willReturn( $lesson );
		$this->workManager->method( 'get' )->willReturnCallback( fn( $id ) => $this->makeWork( $id ) );

		$works = $this->resolver->resolve( $row );

		$ids = array_map( static fn( $w ) => $w->id, $works );
		self::assertEqualsCanonicalizing( [ 10, 20 ], $ids );
	}

	public function test_missing_work_id_is_skipped(): void {
		$row = $this->makeRow( workIdsSnapshot: [ 1, 999 ], extraWorkIds: [] );
		$this->workManager->method( 'get' )->willReturnCallback(
			fn( $id ) => $id === 1 ? $this->makeWork( 1 ) : null
		);

		$works = $this->resolver->resolve( $row );

		self::assertCount( 1, $works );
	}

	public function test_set_extra_works_calls_repository_and_dispatches_event(): void {
		$row = $this->makeRow( workIdsSnapshot: null, extraWorkIds: [] );
		$this->groupLessons->method( 'find' )->with( 42 )->willReturn( $row );
		$this->groupLessons->expects( self::once() )->method( 'setExtraWorkIds' )->with( 42, [ 10, 20 ] );
		$this->dispatcher->expects( self::once() )->method( 'dispatch' );

		$this->resolver->setExtraWorks( 42, [ 10, 20 ], 1 );
	}

	public function test_set_extra_works_throws_when_not_found(): void {
		$this->groupLessons->method( 'find' )->willReturn( null );

		$this->expectException( \InvalidArgumentException::class );
		$this->resolver->setExtraWorks( 99, [], 1 );
	}

	// --- helpers ---

	private function makeRow( ?array $workIdsSnapshot, array $extraWorkIds ): GroupLessonDTO {
		return new GroupLessonDTO(
			id              : 42,
			groupId         : 5,
			lessonId        : 1,
			position        : 0,
			workIdsSnapshot : $workIdsSnapshot,
			extraWorkIds    : $extraWorkIds,
			scheduledAt     : null,
			endsAt          : null,
			isPinned        : false,
			teacherUserId   : null,
			visibility      : 'hidden',
			openedAt        : null,
			homeworkDueAt   : null,
			allowLate       : true,
			recordingUrl    : null,
			createdByUserId : null,
			updatedByUserId : null,
		);
	}

	private function makeLesson( array $workIds ): LessonDTO {
		$steps = array_map(
			static fn( int $id ): StepDTO => new StepDTO( 'w' . $id, StepType::Work, array( 'ref' => $id ) ),
			$workIds
		);

		return new LessonDTO(
			id        : 1,
			subjectKey: 'inf',
			topic     : 'Test',
			steps     : $steps,
			authorId  : 1,
			status    : 'publish',
		);
	}

	private function makeWork( int $id ): WorkDTO {
		return new WorkDTO(
			id           : $id,
			subjectKey   : 'inf',
			title        : "Work $id",
			workType     : WorkType::Practice,
			itemIds      : [],
			instructions : '',
			authorId     : 1,
			status       : 'publish',
		);
	}
}
