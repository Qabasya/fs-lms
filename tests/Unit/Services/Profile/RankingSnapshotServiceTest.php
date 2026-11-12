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
	public function test_trend_is_neutral_if_policy_changed_after_current_rank_was_calculated(): void {
		$old = array( 'practice' => 1.0 );
		$current = array( 'practice' => 2.0 );
		$store = $this->createStub( RankingSnapshotRepository::class );
		$store->method( 'read' )->willReturn( array(
			'interval' => 'day', 'boundary' => '2026-11-06 00:00:00', 'captured_at' => '2026-11-06 00:13:00',
			'season' => '2026-09-01 00:00:00', 'subjects' => array( 'math' => array( 0 => array( 1 => 2 ) ) ),
			'policies' => array( 'math' => hash( 'sha256', serialize( $old ) ) ),
		) );
		$ranking = $this->createStub( RankingCalculationService::class );
		$ranking->method( 'usesSubjectSettings' )->willReturn( true );
		$ranking->method( 'subjectPolicy' )->willReturn( $current );
		$service = new RankingSnapshotService( $store, $this->createStub( SubjectRepository::class ), $this->createStub( LearnerRankingRepository::class ), $ranking, $this->createStub( RankingSnapshotBuilder::class ), new RankingSnapshotPeriod(), $this->createStub( ClockInterface::class ), $this->createStub( RankingSnapshotTransaction::class ) );
		self::assertNull( $service->trend( 'math', 0, 1, 1, '2026-09-01 00:00:00', '2026-11-06 00:14:00', $old ) );
	}
	public function test_capture_loads_policies_before_opening_database_snapshot(): void {
		$insideTransaction = false;
		$subjects = $this->createStub( SubjectRepository::class );
		$subjects->method( 'readActive' )->willReturn( array( new SubjectDTO( 'math', 'Math' ) ) );
		$ranking = $this->createStub( RankingCalculationService::class );
		$ranking->method( 'period' )->willReturn( array( 'from' => '2026-09-01 00:00:00', 'until' => '2026-12-01 00:00:00' ) );
		$ranking->method( 'subjectPolicy' )->willReturnCallback( static function () use ( &$insideTransaction ): array {
			self::assertFalse( $insideTransaction );
			return array( 'practice' => 1.0 );
		} );
		$ranking->method( 'scoresForSubject' )->willReturn( array() );
		$tx = $this->createStub( RankingSnapshotTransaction::class );
		$tx->method( 'read' )->willReturnCallback( static function ( callable $read ) use ( &$insideTransaction ): array {
			$insideTransaction = true;
			return $read();
		} );
		$clock = $this->createStub( ClockInterface::class );
		$clock->method( 'now' )->willReturn( '2026-11-06 00:13:00' );
		$store = $this->createStub( RankingSnapshotRepository::class );
		$store->method( 'read' )->willReturn( array() );
		$service = new RankingSnapshotService( $store, $subjects, $this->createStub( LearnerRankingRepository::class ), $ranking, $this->createStub( RankingSnapshotBuilder::class ), new RankingSnapshotPeriod(), $clock, $tx );
		$service->capture();
	}
	public function test_trend_uses_the_policy_that_produced_the_current_rank(): void {
		$calculatedPolicy = array( 'practice' => 1.0 );
		$newPolicy = array( 'practice' => 2.0 );
		$store = $this->createStub( RankingSnapshotRepository::class );
		$store->method( 'read' )->willReturn( array(
			'interval' => 'day', 'boundary' => '2026-11-06 00:00:00', 'captured_at' => '2026-11-06 00:13:00',
			'season' => '2026-09-01 00:00:00', 'subjects' => array( 'math' => array( 0 => array( 1 => 2 ) ) ),
			'policies' => array( 'math' => hash( 'sha256', serialize( $newPolicy ) ) ),
		) );
		$ranking = $this->createStub( RankingCalculationService::class );
		$ranking->method( 'usesSubjectSettings' )->willReturn( true );
		$ranking->method( 'subjectPolicy' )->willReturn( $newPolicy );
		$service = new RankingSnapshotService( $store, $this->createStub( SubjectRepository::class ), $this->createStub( LearnerRankingRepository::class ), $ranking, $this->createStub( RankingSnapshotBuilder::class ), new RankingSnapshotPeriod(), $this->createStub( ClockInterface::class ), $this->createStub( RankingSnapshotTransaction::class ) );
		self::assertNull( $service->trend( 'math', 0, 1, 1, '2026-09-01 00:00:00', '2026-11-06 00:14:00', $calculatedPolicy ) );
	}
	public function test_recreated_subject_does_not_reuse_old_trend(): void {
		$old = array( 'enabled' => true, 'practice' => 1.0, 'generation' => 'old' );
		$current = array( 'enabled' => true, 'practice' => 1.0, 'generation' => 'new' );
		$store = $this->createStub( RankingSnapshotRepository::class );
		$store->method( 'read' )->willReturn( array(
			'interval' => 'day', 'boundary' => '2026-11-06 00:00:00', 'captured_at' => '2026-11-06 00:13:00',
			'season' => '2026-09-01 00:00:00', 'subjects' => array( 'math' => array( 0 => array( 1 => 2 ) ) ),
			'policies' => array( 'math' => hash( 'sha256', serialize( $old ) ) ),
		) );
		$ranking = $this->createStub( RankingCalculationService::class );
		$ranking->method( 'usesSubjectSettings' )->willReturn( true );
		$ranking->method( 'subjectPolicy' )->willReturn( $current );
		$service = new RankingSnapshotService( $store, $this->createStub( SubjectRepository::class ), $this->createStub( LearnerRankingRepository::class ), $ranking, $this->createStub( RankingSnapshotBuilder::class ), new RankingSnapshotPeriod(), $this->createStub( ClockInterface::class ), $this->createStub( RankingSnapshotTransaction::class ) );
		self::assertNull( $service->trend( 'math', 0, 1, 1, '2026-09-01 00:00:00', '2026-11-06 00:14:00' ) );
	}
	public function test_changed_subject_policy_does_not_reuse_stale_snapshot(): void {
		$old = array( 'enabled' => true, 'practice' => 1.0, 'revision' => 1 );
		$current = array( 'enabled' => true, 'practice' => 1.0, 'revision' => 3 );
		$store = $this->createStub( RankingSnapshotRepository::class );
		$store->method( 'read' )->willReturn( array(
			'interval' => 'day', 'boundary' => '2026-11-06 00:00:00', 'captured_at' => '2026-11-06 00:13:00',
			'season' => '2026-09-01 00:00:00', 'subjects' => array( 'math' => array( 0 => array( 1 => 2 ) ), 'physics' => array( 0 => array( 1 => 2 ) ) ),
			'policies' => array( 'math' => hash( 'sha256', serialize( array( 'assignments-v1', $old ) ) ), 'physics' => hash( 'sha256', serialize( array( 'assignments-v1', $old ) ) ) ),
		) );
		$ranking = $this->createStub( RankingCalculationService::class );
		$ranking->method( 'usesSubjectSettings' )->willReturn( true );
		$ranking->method( 'subjectPolicy' )->willReturnCallback( static fn( string $subject ): array => 'math' === $subject ? $current : $old );
		$service = new RankingSnapshotService( $store, $this->createStub( SubjectRepository::class ), $this->createStub( LearnerRankingRepository::class ), $ranking, $this->createStub( RankingSnapshotBuilder::class ), new RankingSnapshotPeriod(), $this->createStub( ClockInterface::class ), $this->createStub( RankingSnapshotTransaction::class ) );
		self::assertNull( $service->trend( 'math', 0, 1, 1, '2026-09-01 00:00:00', '2026-11-06 00:14:00' ) );
		self::assertSame( 'up', $service->trend( 'physics', 0, 1, 1, '2026-09-01 00:00:00', '2026-11-06 00:14:00' )['direction'] );
	}
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
