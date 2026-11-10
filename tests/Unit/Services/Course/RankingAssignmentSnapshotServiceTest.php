<?php

declare( strict_types=1 );

namespace Unit\Services\Course;

use Inc\Contracts\ClockInterface;
use Inc\DTO\Course\WorkDTO;
use Inc\Enums\Course\WorkType;
use Inc\Managers\Course\WorkManager;
use Inc\Repositories\OptionsRepositories\RankingAssignmentSnapshotRepository;
use Inc\Services\Course\RankingAssignmentSnapshotService;
use PHPUnit\Framework\TestCase;

class RankingAssignmentSnapshotServiceTest extends TestCase {
	public function test_pending_extra_work_saves_addition_time_without_premature_points(): void {
		$clock = $this->createMock( ClockInterface::class );
		$clock->method( 'now' )->willReturn( '2026-11-30 13:00:00' );
		$repository = $this->createMock( RankingAssignmentSnapshotRepository::class );
		$repository->expects( self::once() )->method( 'addMissing' )->with( 42, array( 17 => array( 'issued_at' => '2026-11-30 13:00:00', 'pending' => true ) ) );
		( new RankingAssignmentSnapshotService( $repository, $this->createMock( WorkManager::class ), $clock ) )->markPending( 42, array( 17 ) );
	}
	public function test_extra_work_issued_when_future_lesson_opens(): void {
		$service = new RankingAssignmentSnapshotService(
			$this->createMock( RankingAssignmentSnapshotRepository::class ),
			$this->createMock( WorkManager::class ),
			$this->createMock( ClockInterface::class )
		);
		self::assertSame(
			'2026-12-01 09:00:00',
			$service->issueTime( '2026-11-30 13:00:00', '2026-12-01 09:00:00' )
		);
		self::assertSame(
			'2026-12-02 09:00:00',
			$service->issueTime( '2026-12-02 09:00:00', '2026-12-01 09:00:00' )
		);
	}

	public function test_pending_work_is_issued_at_later_of_addition_and_opening(): void {
		$works = $this->createMock( WorkManager::class );
		$works->method( 'get' )->willReturn( new WorkDTO( 17, 'math', 'Test', WorkType::Homework, array( 3 ), '', 1, 'publish', 0, array( 3 => 2 ) ) );
		$repository = $this->createMock( RankingAssignmentSnapshotRepository::class );
		$repository->method( 'forLessonFresh' )->with( 42 )->willReturn( array( 17 => array( 'issued_at' => '2026-11-30 13:00:00', 'pending' => true ) ) );
		$repository->expects( self::once() )->method( 'addMissing' )->with( 42, array( 17 => array( 'issued_at' => '2026-12-01 09:00:00', 'source' => 'homework', 'task_points' => array( 3 => 2 ) ) ) );
		( new RankingAssignmentSnapshotService( $repository, $works, $this->createMock( ClockInterface::class ) ) )->capture( 42, array( 17 ), '2026-12-01 09:00:00' );
	}

	public function test_extra_added_on_december_second_uses_addition_date_not_november_lesson(): void {
		$works = $this->createStub( WorkManager::class );
		$works->method( 'get' )->willReturn( new WorkDTO( 17, 'math', 'Test', WorkType::Homework, array( 3 ), '', 1, 'publish', 0, array( 3 => 2 ) ) );
		$clock = $this->createStub( ClockInterface::class );
		$clock->method( 'now' )->willReturn( '2026-12-02 09:00:00' );
		$repository = $this->createMock( RankingAssignmentSnapshotRepository::class );
		$repository->expects( self::once() )->method( 'addMissing' )->with( 42, array( 17 => array( 'issued_at' => '2026-12-02 09:00:00', 'source' => 'homework', 'task_points' => array( 3 => 2 ) ) ) );
		( new RankingAssignmentSnapshotService( $repository, $works, $clock ) )->capture( 42, array( 17 ) );
	}

	public function test_snapshots_new_work_with_its_points_and_issue_time(): void {
		$works = $this->createMock( WorkManager::class );
		$works->method( 'get' )->with( 17 )->willReturn( new WorkDTO( 17, 'math', 'Test', WorkType::Homework, array( 3, 4 ), '', 1, 'publish', 0, array( 3 => 2, 4 => 5 ) ) );
		$clock = $this->createMock( ClockInterface::class );
		$clock->method( 'now' )->willReturn( '2026-11-30 13:00:00' );
		$repository = $this->createMock( RankingAssignmentSnapshotRepository::class );
		$repository->expects( self::once() )->method( 'addMissing' )->with( 42, array( 17 => array( 'issued_at' => '2026-11-30 13:00:00', 'source' => 'homework', 'task_points' => array( 3 => 2, 4 => 5 ) ) ) );
		( new RankingAssignmentSnapshotService( $repository, $works, $clock ) )->capture( 42, array( 17 ) );
	}
}
