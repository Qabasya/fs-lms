<?php

declare( strict_types=1 );

namespace Tests\Unit\Services\Settings;

use Inc\Repositories\OptionsRepositories\PluginConfigRepository;
use Inc\Services\Settings\RankingSettingsService;
use Inc\Services\Shared\PluginConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RankingSettingsServiceTest extends TestCase {

	public function test_partial_patch_preserves_other_configuration_and_updates_policy_immediately(): void {
		$before = $GLOBALS['_test_options'] ?? array();
		try {
			$repository = new PluginConfigRepository();
			$repository->save( array( 'exam_hold_minutes' => 35, 'ranking_survey' => 5.0 ) );
			$settings = new RankingSettingsService( $repository );
			$config = new PluginConfig( $repository );
			$policy = new \Inc\Services\Profile\Learner\RankingPointsPolicy( $config );
			self::assertSame( 0.0, $policy->forSolvedTask( 'practice' ) );
			$settings->save( array( 'practice' => '2', 'attendance_bonus' => '1', 'attendance_streak_length' => '4' ) );
			self::assertSame( 2.0, $policy->forSolvedTask( 'practice' ) );
			self::assertSame( 5.0, $policy->forSolvedTask( 'survey' ) );
			self::assertSame( 1.0, $policy->attendanceBonus() );
			self::assertSame( 4, $policy->attendanceStreakLength() );
			self::assertSame( 35, $repository->get()['exam_hold_minutes'] );
			self::assertSame( $config->rankingSettings(), $config->viewState()['ranking'] );
		} finally {
			$GLOBALS['_test_options'] = $before;
		}
	}

	public static function invalidInputs(): array {
		return array_map( static fn( array $patch ): array => array( $patch ), array(
			array(), array( 'unknown' => 1 ), array( 'practice' => -1 ), array( 'practice' => NAN ),
			array( 'practice' => INF ), array( 'practice' => array( 1 ) ), array( 'practice' => true ),
			array( 'practice' => 'NaN' ), array( 'practice' => '1abc' ), array( 'practice' => 1000.01 ),
			array( 'practice' => 0.001 ), array( 'practice' => '1.25' ), array( 'attendance_streak_length' => 1 ),
			array( 'attendance_streak_length' => 101 ), array( 'attendance_streak_length' => 3.5 ),
			array( 'practice' => 2, 'homework' => 'bad' ),
		) );
	}

	#[DataProvider('invalidInputs')]
	public function test_invalid_input_is_rejected_without_any_partial_save( array $input ): void {
		$repository = $this->createMock( PluginConfigRepository::class );
		$repository->expects( self::never() )->method( 'save' );
		$service = new RankingSettingsService( $repository );
		$this->expectException( \InvalidArgumentException::class );
		$service->save( $input );
	}

	public function test_zero_and_upper_bounds_are_valid(): void {
		$repository = $this->createMock( PluginConfigRepository::class );
		$repository->expects( self::once() )->method( 'save' )->with( array( 'ranking_practice' => 0.0, 'ranking_assessment' => 1000.0, 'ranking_attendance_streak_length' => 100 ) );
		( new RankingSettingsService( $repository ) )->save( array( 'practice' => '0', 'assessment' => '1000', 'attendance_streak_length' => '100' ) );
	}
	public function test_visibility_lists_are_saved_and_can_be_cleared(): void {
		$before = $GLOBALS['_test_options'] ?? array();
		try {
			$repository = new PluginConfigRepository();
			$config = new PluginConfig( $repository );
			$service = new RankingSettingsService( $repository );
			self::assertTrue( $config->isLearnerRankingVisible( 'math' ) );
			$service->save( array( 'hidden_subjects' => 'math,python', 'excluded_students' => '7,8,7' ) );
			self::assertFalse( $config->isLearnerRankingVisible( 'math' ) );
			self::assertTrue( $config->isLearnerRankingVisible( 'new_subject' ) );
			self::assertSame( array( 7, 8 ), $config->rankingExcludedStudents() );
			$service->save( array( 'hidden_subjects' => '', 'excluded_students' => '' ) );
			self::assertTrue( $config->isLearnerRankingVisible( 'math' ) );
			self::assertSame( array(), $config->rankingExcludedStudents() );
		} finally {
			$GLOBALS['_test_options'] = $before;
		}
	}

	public function test_invalid_selection_is_rejected_before_saving_weights(): void {
		$repository = $this->createMock( PluginConfigRepository::class );
		$repository->expects( self::never() )->method( 'save' );
		$this->expectException( \InvalidArgumentException::class );
		( new RankingSettingsService( $repository ) )->save( array( 'practice' => '1', 'excluded_students' => '1,no' ) );
	}

	public function test_changing_ranking_policy_discards_old_snapshot(): void {
		$repository = $this->createStub( PluginConfigRepository::class );
		$repository->method( 'get' )->willReturn( array( 'ranking_excluded_students' => array() ) );
		$snapshots = $this->createMock( \Inc\Repositories\OptionsRepositories\RankingSnapshotRepository::class );
		$snapshots->expects( self::once() )->method( 'clear' );
		( new RankingSettingsService( $repository, $snapshots ) )->save( array( 'excluded_students' => '9' ) );
	}

}
