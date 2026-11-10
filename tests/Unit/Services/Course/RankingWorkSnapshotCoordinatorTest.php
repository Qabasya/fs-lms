<?php

declare( strict_types=1 );

namespace Unit\Services\Course;

use Inc\Contracts\ClockInterface;
use Inc\DTO\Course\GroupLessonDTO;
use Inc\DTO\Course\WorkDTO;
use Inc\Enums\Course\WorkType;
use Inc\Managers\Course\LessonManager;
use Inc\Repositories\OptionsRepositories\RankingAssignmentSnapshotRepository;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Services\Course\RankingWorkSnapshotCoordinator;
use PHPUnit\Framework\TestCase;

class RankingWorkSnapshotCoordinatorTest extends TestCase {
	public function test_only_open_assignments_keep_previous_points(): void {
		$rows = $this->createMock( GroupLessonRepository::class );
		$rows->method( 'listBySubject' )->willReturn( array( $this->row( 1, '2026-11-30 09:00:00' ), $this->row( 2, '2026-12-02 09:00:00' ) ) );
		$clock = $this->createMock( ClockInterface::class );
		$clock->method( 'now' )->willReturn( '2026-12-01 10:00:00' );
		$repository = $this->createMock( RankingAssignmentSnapshotRepository::class );
		$repository->method( 'forLessonFresh' )->willReturn( array() );
		$repository->expects( self::once() )->method( 'addMissing' )->with( 1, array( 17 => array( 'issued_at' => '2026-11-30 09:00:00', 'source' => 'homework', 'task_points' => array( 3 => 2 ) ) ) );
		$work = new WorkDTO( 17, 'math', 'Test', WorkType::Homework, array( 3 ), '', 1, 'publish', 0, array( 3 => 2 ) );
		( new RankingWorkSnapshotCoordinator( $rows, $this->createMock( LessonManager::class ), $repository, $clock ) )->captureBeforeChange( $work );
	}

	public function test_pending_extra_keeps_its_own_issue_date(): void {
		$rows = $this->createMock( GroupLessonRepository::class );
		$rows->method( 'listBySubject' )->willReturn( array( $this->row( 1, '2026-11-30 09:00:00' ) ) );
		$clock = $this->createMock( ClockInterface::class );
		$clock->method( 'now' )->willReturn( '2026-12-02 10:00:00' );
		$repository = $this->createMock( RankingAssignmentSnapshotRepository::class );
		$repository->method( 'forLessonFresh' )->willReturn( array( 17 => array( 'pending' => true, 'issued_at' => '2026-12-01 12:00:00' ) ) );
		$repository->expects( self::once() )->method( 'addMissing' )->with( 1, self::callback(
			static fn( array $snapshots ): bool => $snapshots[17]['issued_at'] === '2026-12-01 12:00:00'
		) );
		$work = new WorkDTO( 17, 'math', 'Test', WorkType::Homework, array( 3 ), '', 1, 'publish', 0, array( 3 => 2 ) );
		( new RankingWorkSnapshotCoordinator( $rows, $this->createMock( LessonManager::class ), $repository, $clock ) )->captureBeforeChange( $work );
	}

	public function test_archived_lesson_that_never_opened_is_not_frozen(): void {
		$rows = $this->createMock( GroupLessonRepository::class );
		$rows->method( 'listBySubject' )->willReturn( array( GroupLessonDTO::fromArray( array(
			'id' => 1, 'group_id' => 1, 'position' => 1, 'scheduled_at' => '2026-12-02 09:00:00',
			'visibility' => 'archived', 'work_ids_snapshot' => '[17]',
		) ) ) );
		$clock = $this->createMock( ClockInterface::class );
		$clock->method( 'now' )->willReturn( '2026-12-01 10:00:00' );
		$repository = $this->createMock( RankingAssignmentSnapshotRepository::class );
		$repository->expects( self::never() )->method( 'addMissing' );
		$work = new WorkDTO( 17, 'math', 'Test', WorkType::Homework, array( 3 ), '', 1, 'publish', 0, array( 3 => 2 ) );
		( new RankingWorkSnapshotCoordinator( $rows, $this->createMock( LessonManager::class ), $repository, $clock ) )->captureBeforeChange( $work );
	}

	private function row( int $id, string $date ): GroupLessonDTO {
		return GroupLessonDTO::fromArray( array( 'id' => $id, 'group_id' => 1, 'position' => 1, 'scheduled_at' => $date, 'visibility' => 'hidden', 'work_ids_snapshot' => '[17]' ) );
	}
}
