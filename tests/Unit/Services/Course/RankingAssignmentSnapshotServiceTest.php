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
use PHPUnit\Framework\Attributes\DataProvider;

class RankingAssignmentSnapshotServiceTest extends TestCase {
	public function test_publication_batch_uses_one_preparation_time(): void {
		$works = $this->createStub( WorkManager::class );
		$works->method( 'get' )->willReturnCallback( static fn( int $id ): WorkDTO => new WorkDTO( $id, 'math', 'Test', WorkType::Homework, array(), '', 1, 'publish' ) );
		$clock = $this->createMock( ClockInterface::class );
		$clock->expects( self::once() )->method( 'now' )->willReturn( '2026-11-30 23:59:59' );
		$repo = $this->createMock( RankingAssignmentSnapshotRepository::class );
		$repo->expects( self::once() )->method( 'addMissing' )->with( 42, array( 17 => array( 'issued_at' => '2026-11-30 23:59:59', 'pending' => true, 'publication_unconfirmed' => true, 'source' => 'homework', 'origin' => 'lesson' ), 18 => array( 'issued_at' => '2026-11-30 23:59:59', 'pending' => true, 'publication_unconfirmed' => true, 'source' => 'homework', 'origin' => 'lesson' ) ) );
		( new RankingAssignmentSnapshotService( $repo, $works, $clock ) )->markPublicationUnconfirmed( 42, array( 17, 18 ), \Inc\Enums\Course\RankingAssignmentOrigin::Lesson );
	}

	public function test_pending_origin_survives_issuance(): void {
		$works = $this->createStub( WorkManager::class );
		$works->method( 'get' )->willReturn( new WorkDTO( 17, 'math', 'Test', WorkType::Homework, array( 3 ), '', 1, 'publish', 0, array( 3 => 2 ) ) );
		$repo = $this->createMock( RankingAssignmentSnapshotRepository::class );
		$repo->method( 'forLessonFresh' )->willReturn( array( 17 => array( 'issued_at' => '2026-11-30 12:00:00', 'pending' => true, 'origin' => 'extra' ) ) );
		$repo->expects( self::once() )->method( 'addMissing' )->with( 42, array( 17 => array( 'issued_at' => '2026-12-01 12:00:00', 'source' => 'homework', 'task_points' => array( 3 => 2 ), 'origin' => 'extra' ) ) );
		( new RankingAssignmentSnapshotService( $repo, $works, $this->createStub( ClockInterface::class ) ) )->capture( 42, array( 17 ), '2026-12-01 12:00:00' );
	}

	public function test_pending_extra_work_saves_addition_time_without_premature_points(): void {
		$clock = $this->createMock( ClockInterface::class );
		$clock->method( 'now' )->willReturn( '2026-11-30 13:00:00' );
		$repository = $this->createMock( RankingAssignmentSnapshotRepository::class );
		$repository->expects( self::once() )->method( 'addMissing' )->with( 42, array( 17 => array( 'issued_at' => '2026-11-30 13:00:00', 'pending' => true ) ) );
		$works = $this->createStub( WorkManager::class );
		$works->method( 'get' )->willReturn( new WorkDTO( 17, 'math', 'Test', WorkType::Homework, array(), '', 1, 'publish' ) );
		( new RankingAssignmentSnapshotService( $repository, $works, $clock ) )->markPending( 42, array( 17 ) );
	}
	public function test_pending_work_must_exist_before_it_is_assigned(): void {
		$repository = $this->createMock( RankingAssignmentSnapshotRepository::class );
		$repository->expects( self::never() )->method( 'addMissing' );
		$works = $this->createStub( WorkManager::class );
		$works->method( 'get' )->willReturn( null );
		$clock = $this->createStub( ClockInterface::class );
		$clock->method( 'now' )->willReturn( '2026-11-30 13:00:00' );
		$this->expectException( \InvalidArgumentException::class );
		( new RankingAssignmentSnapshotService( $repository, $works, $clock ) )->markPending( 42, array( 17 ) );
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

	public static function additionAndOpeningAroundSeasonBoundary(): array {
		return array(
			'added before opening in winter' => array( '2026-11-30 23:59:59', '2026-12-01 00:00:00', '2026-12-01 00:00:00' ),
			'added after opening in winter' => array( '2026-12-01 00:00:00', '2026-11-30 23:59:59', '2026-12-01 00:00:00' ),
			'both before winter' => array( '2026-11-30 23:59:58', '2026-11-30 23:59:59', '2026-11-30 23:59:59' ),
			'opening on spring boundary' => array( '2027-02-28 23:59:59', '2027-03-01 00:00:00', '2027-03-01 00:00:00' ),
			'leap day opening remains winter' => array( '2028-02-28 23:59:59', '2028-02-29 23:59:59', '2028-02-29 23:59:59' ),
			'opening after leap february' => array( '2028-02-29 23:59:59', '2028-03-01 00:00:00', '2028-03-01 00:00:00' ),
			'opening on summer boundary' => array( '2027-05-31 23:59:59', '2027-06-01 00:00:00', '2027-06-01 00:00:00' ),
			'opening on autumn boundary' => array( '2027-08-31 23:59:59', '2027-09-01 00:00:00', '2027-09-01 00:00:00' ),
		);
	}

	#[DataProvider( 'additionAndOpeningAroundSeasonBoundary' )]
	public function test_pending_issuance_uses_later_timestamp_at_season_boundary( string $added, string $opened, string $issued ): void {
		$works = $this->createStub( WorkManager::class );
		$works->method( 'get' )->willReturn( new WorkDTO( 17, 'math', 'Test', WorkType::Homework, array( 3 ), '', 1, 'publish', 0, array( 3 => 2 ) ) );
		$repository = $this->createMock( RankingAssignmentSnapshotRepository::class );
		$repository->method( 'forLessonFresh' )->willReturn( array( 17 => array( 'issued_at' => $added, 'pending' => true ) ) );
		$repository->expects( self::once() )->method( 'addMissing' )->with( 42, array( 17 => array( 'issued_at' => $issued, 'source' => 'homework', 'task_points' => array( 3 => 2 ) ) ) );
		( new RankingAssignmentSnapshotService( $repository, $works, $this->createStub( ClockInterface::class ) ) )->capture( 42, array( 17 ), $opened );
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
