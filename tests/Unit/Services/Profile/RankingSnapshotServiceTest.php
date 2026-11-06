<?php

declare( strict_types=1 );

namespace Tests\Unit\Services\Profile;

use Inc\Contracts\ClockInterface;
use Inc\DTO\Subject\SubjectDTO;
use Inc\Repositories\OptionsRepositories\RankingSnapshotRepository;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Repositories\WPDBRepositories\LearnerRankingRepository;
use Inc\Repositories\WPDBRepositories\RankingSnapshotTransaction;
use Inc\Services\Profile\RankingCalculationService;
use Inc\Services\Profile\RankingSnapshotBuilder;
use Inc\Services\Profile\RankingSnapshotPeriod;
use Inc\Services\Profile\RankingSnapshotService;
use PHPUnit\Framework\TestCase;

class RankingSnapshotServiceTest extends TestCase {
	private function makeService( RankingSnapshotRepository $store, array $times ): RankingSnapshotService {
		$subjects = $this->createStub( SubjectRepository::class );
		$subjects->method( 'readActive' )->willReturn( array( new SubjectDTO( 'math', 'Math' ) ) );
		$repo = $this->createStub( LearnerRankingRepository::class );
		$repo->method( 'roster' )->willReturn( array( array( 'student_person_id' => 1, 'group_id' => 5 ) ) );
		$ranking = $this->createStub( RankingCalculationService::class );
		$ranking->method( 'period' )->willReturn( array( 'from' => '2026-09-01 00:00:00' ) );
		$ranking->method( 'scoresForSubject' )->willReturn( array( 1 => array( 'score' => 12.0, 'rank' => 1 ) ) );
		$builder = $this->createStub( RankingSnapshotBuilder::class );
		$builder->method( 'build' )->willReturn( array( 0 => array( 1 => 1 ), 5 => array( 1 => 1 ) ) );
		$clock = $this->createStub( ClockInterface::class );
		$clock->method( 'now' )->willReturnOnConsecutiveCalls( ...$times );
		$tx = $this->createStub( RankingSnapshotTransaction::class );
		$tx->method( 'read' )->willReturnCallback( static fn( callable $fn ): array => $fn() );
		return new RankingSnapshotService( $store, $subjects, $repo, $ranking, $builder, new RankingSnapshotPeriod(), $clock, $tx );
	}

	public function test_capture_inside_window_records_real_time(): void {
		$store = $this->createMock( RankingSnapshotRepository::class );
		$store->method( 'read' )->willReturn( array() );
		$store->expects( self::once() )->method( 'save' )->with( self::callback( static fn( array $data ): bool =>
			$data['captured_at'] === '2026-11-06 00:14:59' && $data['boundary'] === '2026-11-06 00:00:00'
			&& $data['subjects']['math'][5][1] === 1
		) );
		$this->makeService( $store, array( '2026-11-06 00:14:59', '2026-11-06 00:16:02' ) )->capture();
	}

	public function test_boundary_change_during_capture_never_writes(): void {
		$store = $this->createMock( RankingSnapshotRepository::class );
		$store->method( 'read' )->willReturn( array() );
		$store->expects( self::never() )->method( 'save' );
		$this->makeService( $store, array( '2026-12-31 00:00:01', '2027-01-01 00:00:01' ) )->capture();
	}

	public function test_existing_snapshot_for_same_day_is_not_replaced(): void {
		$store = $this->createMock( RankingSnapshotRepository::class );
		$store->method( 'read' )->willReturn( array( 'interval' => 'day', 'boundary' => '2026-11-06 00:00:00', 'captured_at' => '2026-11-06 00:03:00' ) );
		$store->expects( self::never() )->method( 'save' );
		$this->makeService( $store, array( '2026-11-06 00:14:00' ) )->capture();
	}

	public function test_missed_window_does_not_create_or_advertise_late_snapshot(): void {
		$store = $this->createMock( RankingSnapshotRepository::class );
		$store->method( 'read' )->willReturn( array(
			'interval' => 'day', 'boundary' => '2026-11-06 00:00:00', 'captured_at' => '2026-11-06 16:00:00',
			'season' => '2026-09-01 00:00:00', 'subjects' => array( 'math' => array( 0 => array( 1 => 2 ) ) ),
		) );
		$store->expects( self::never() )->method( 'save' );
		$service = $this->makeService( $store, array( '2026-11-06 16:00:00' ) );
		$service->capture();
		self::assertNull( $service->trend( 'math', 0, 1, 1, '2026-09-01 00:00:00', '2026-11-06 16:01:00' ) );
	}

	public function test_no_trend_on_first_day_of_season(): void {
		$store = $this->createStub( RankingSnapshotRepository::class );
		$store->method( 'read' )->willReturn( array(
			'interval' => 'day', 'boundary' => '2026-12-01 00:00:00', 'captured_at' => '2026-12-01 00:02:00',
			'season' => '2026-12-01 00:00:00', 'subjects' => array( 'math' => array( 0 => array( 1 => 2 ) ) ),
		) );
		$service = $this->makeService( $store, array( '2026-12-01 01:00:00' ) );
		self::assertNull( $service->trend( 'math', 0, 1, 1, '2026-12-01 00:00:00', '2026-12-01 01:00:00' ) );
	}

	public function test_failed_subject_calculation_does_not_overwrite_last_snapshot(): void {
		$store = $this->createMock( RankingSnapshotRepository::class );
		$store->method( 'read' )->willReturn( array() );
		$store->expects( self::never() )->method( 'save' );
		$subjects = $this->createStub( SubjectRepository::class );
		$subjects->method( 'readActive' )->willReturn( array( new SubjectDTO( 'math', 'Math' ) ) );
		$repo = $this->createStub( LearnerRankingRepository::class );
		$ranking = $this->createStub( RankingCalculationService::class );
		$ranking->method( 'period' )->willReturn( array( 'from' => '2026-09-01 00:00:00' ) );
		$ranking->method( 'scoresForSubject' )->willThrowException( new \RuntimeException( 'db error' ) );
		$clock = $this->createStub( ClockInterface::class );
		$clock->method( 'now' )->willReturn( '2026-11-06 00:10:00' );
		$tx = $this->createStub( RankingSnapshotTransaction::class );
		$tx->method( 'read' )->willReturnCallback( static fn( callable $fn ): array => $fn() );
		$service = new RankingSnapshotService( $store, $subjects, $repo, $ranking, $this->createStub( RankingSnapshotBuilder::class ), new RankingSnapshotPeriod(), $clock, $tx );
		$this->expectException( \RuntimeException::class );
		$service->capture();
	}

	public function test_trend_is_scoped_to_group_person_season_and_actual_day(): void {
		$store = $this->createStub( RankingSnapshotRepository::class );
		$store->method( 'read' )->willReturn( array(
			'interval' => 'day', 'boundary' => '2026-11-06 00:00:00', 'captured_at' => '2026-11-06 00:13:00',
			'season' => '2026-09-01 00:00:00', 'subjects' => array( 'math' => array( 0 => array( 1 => 2 ), 5 => array( 1 => 1 ) ) ),
		) );
		$service = $this->makeService( $store, array( '2026-11-06 00:14:00' ) );
		$season = '2026-09-01 00:00:00';
		$now = '2026-11-06 00:14:00';
		self::assertSame( array( 'direction' => 'up', 'baseline_at' => '2026-11-06 00:13:00' ), $service->trend( 'math', 0, 1, 1, $season, $now ) );
		self::assertSame( 'down', $service->trend( 'math', 5, 1, 2, $season, $now )['direction'] );
		self::assertNull( $service->trend( 'math', 5, 1, 1, $season, $now ) );
		self::assertNull( $service->trend( 'math', 7, 1, 1, $season, $now ) );
		self::assertNull( $service->trend( 'math', 0, 2, 1, $season, $now ) );
		self::assertNull( $service->trend( 'physics', 0, 1, 1, $season, $now ) );
		self::assertNull( $service->trend( 'math', 0, 1, 1, '2026-12-01 00:00:00', $now ) );
		self::assertNull( $service->trend( 'math', 0, 1, 1, $season, '2026-11-07 00:01:00' ) );
		self::assertNull( $service->trend( 'math', 0, 1, 1, $season, '2026-11-06 00:12:59' ) );
	}
}
