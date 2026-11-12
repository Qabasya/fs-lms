<?php

declare( strict_types=1 );

namespace Unit\Services\Profile;

use Inc\Repositories\WPDBRepositories\LearnerRankingRepository;
use Inc\Services\Profile\Learner\RankingPointsPolicy;
use Inc\Services\Profile\RankingCalculationService;
use Inc\Services\Profile\RankingSnapshotBuilder;
use PHPUnit\Framework\TestCase;

class RankingSnapshotBuilderTest extends TestCase {
	public function test_snapshot_uses_independent_dense_ranks_and_ignores_unknown_students(): void {
		$ranking = new RankingCalculationService(
			$this->createMock( LearnerRankingRepository::class ),
			$this->createMock( RankingPointsPolicy::class )
		);
		$builder = new RankingSnapshotBuilder( $ranking );
		$scores = array(
			10 => array( 'score' => 100.0, 'rank' => 1 ),
			20 => array( 'score' => 80.0, 'rank' => 2 ),
			30 => array( 'score' => 80.0, 'rank' => 2 ),
		);
		$roster = array(
			array( 'student_person_id' => 10, 'group_id' => 1 ),
			array( 'student_person_id' => 20, 'group_id' => 1 ),
			array( 'student_person_id' => 20, 'group_id' => 2 ),
			array( 'student_person_id' => 30, 'group_id' => 2 ),
			array( 'student_person_id' => 99, 'group_id' => 2 ),
		);
		$snapshot = $builder->build( $scores, $roster );
		self::assertSame( array( 10 => 1, 20 => 2, 30 => 2 ), $snapshot[0] );
		self::assertSame( array( 10 => 1, 20 => 2 ), $snapshot[1] );
		self::assertSame( array( 20 => 1, 30 => 1 ), $snapshot[2] );
	}
	public function test_incomplete_scope_is_not_saved_as_a_ranking_baseline(): void {
		$ranking = new RankingCalculationService( $this->createStub( LearnerRankingRepository::class ), $this->createStub( RankingPointsPolicy::class ) );
		$builder = new RankingSnapshotBuilder( $ranking );
		$scores = array( 10 => array( 'score' => 5.0, 'score_unknown' => true ), 20 => array( 'score' => 8.0, 'score_unknown' => false ) );
		$roster = array( array( 'student_person_id' => 10, 'group_id' => 1 ), array( 'student_person_id' => 20, 'group_id' => 2 ) );
		self::assertSame( array( 2 => array( 20 => 1 ) ), $builder->build( $scores, $roster ) );
	}
}
