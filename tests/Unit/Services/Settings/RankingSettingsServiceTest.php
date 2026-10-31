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
			$settings->save( array( 'practice' => '0.25', 'attendance_bonus' => '1.20', 'attendance_streak_length' => '4' ) );
			self::assertSame( 0.25, $policy->forSolvedTask( 'practice' ) );
			self::assertSame( 5.0, $policy->forSolvedTask( 'survey' ) );
			self::assertSame( 1.2, $policy->attendanceBonus() );
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
			array( 'practice' => 0.001 ), array( 'attendance_streak_length' => 1 ),
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
		( new RankingSettingsService( $repository ) )->save( array( 'practice' => '0', 'assessment' => '1000.00', 'attendance_streak_length' => '100' ) );
	}
}
