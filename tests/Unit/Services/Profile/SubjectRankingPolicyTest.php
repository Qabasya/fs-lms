<?php

declare( strict_types=1 );

namespace Tests\Unit\Services\Profile;

use Inc\Repositories\OptionsRepositories\PluginConfigRepository;
use Inc\Repositories\WPDBRepositories\LearnerRankingRepository;
use Inc\Services\Profile\Learner\RankingPointsPolicy;
use Inc\Services\Profile\RankingCalculationService;
use Inc\Services\Shared\PluginConfig;
use PHPUnit\Framework\TestCase;

class SubjectRankingPolicyTest extends TestCase {
	private mixed $previousDb;

	protected function setUp(): void {
		$this->previousDb = $GLOBALS['wpdb'];
		$GLOBALS['wpdb'] = new \wpdb();
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->previousDb;
	}

	public function test_assigned_tasks_determine_score_and_maximum_including_unsolved_tasks(): void {
		$repo = $this->createStub( LearnerRankingRepository::class );
		$repo->method( 'studentIds' )->willReturn( array( 1 ) );
		$assignments = $this->createStub( \Inc\Services\Profile\RankingAssignmentsService::class );
		$task = array( 'student_person_id' => 1, 'group_lesson_id' => 2, 'work_id' => 3, 'source' => 'practice' );
		$assignments->method( 'tasks' )->willReturn( array( $task + array( 'task_id' => 4, 'task_points' => 2, 'credited' => true ), $task + array( 'task_id' => 5, 'task_points' => 1, 'credited' => false ) ) );
		$calculator = new RankingCalculationService( $repo, new RankingPointsPolicy( new PluginConfig( new PluginConfigRepository() ) ), $assignments, true );
		$policy = array( 'excluded_students' => array(), 'practice' => 2, 'attendance_bonus' => 0, 'attendance_streak_length' => 3 );
		$row = $calculator->scoresForSubject( 'math', '2026-11-01 12:00:00', null, true, $policy )[1];
		self::assertSame( 4.0, $row['score'] );
		self::assertSame( 6.0, $row['max_score'] );
		self::assertSame( 3.0, $row['breakdown'][0]['max_amount'] );
	}
	public function test_unknown_maximum_preserves_known_earned_points(): void {
		$repo = $this->createStub( LearnerRankingRepository::class );
		$repo->method( 'studentIds' )->willReturn( array( 1 ) );
		$assignments = $this->createStub( \Inc\Services\Profile\RankingAssignmentsService::class );
		$task = array( 'student_person_id' => 1, 'group_lesson_id' => 2, 'work_id' => 3, 'source' => 'homework' );
		$assignments->method( 'tasks' )->willReturn( array( $task + array( 'task_id' => 4, 'task_points' => 2, 'credited' => true ), array( 'student_person_id' => 1, 'group_lesson_id' => 5, 'work_id' => 6, 'task_id' => 0, 'source' => 'homework', 'task_points' => 0, 'credited' => false, 'max_unknown' => true, 'score_unknown' => true ) ) );
		$calculator = new RankingCalculationService( $repo, new RankingPointsPolicy( new PluginConfig( new PluginConfigRepository() ) ), $assignments, true );
		$policy = array( 'excluded_students' => array(), 'homework' => 2, 'attendance_bonus' => 0, 'attendance_streak_length' => 3 );
		$row = $calculator->scoresForSubject( 'math', '2026-11-01 12:00:00', null, true, $policy )[1];
		self::assertSame( 4.0, $row['score'] );
		self::assertNull( $row['max_score'] );
		self::assertTrue( $row['max_unknown'] );
		self::assertTrue( $row['score_unknown'] );
		self::assertTrue( $row['breakdown'][0]['score_unknown'] );
		self::assertTrue( $row['breakdown'][0]['max_unknown'] );
	}

	public function test_injected_unfinished_assignment_path_is_off_without_explicit_opt_in(): void {
		$repo = $this->createStub( LearnerRankingRepository::class );
		$repo->method( 'studentIds' )->willReturn( array( 1 ) );
		$repo->method( 'workEvents' )->willReturn( array( array( 'student_person_id' => 1, 'source' => 'practice', 'task_points' => 2 ) ) );
		$assignments = $this->createMock( \Inc\Services\Profile\RankingAssignmentsService::class );
		$assignments->expects( self::never() )->method( 'tasks' );
		$calculator = new RankingCalculationService( $repo, new RankingPointsPolicy( new PluginConfig( new PluginConfigRepository() ) ), $assignments );
		$policy = array( 'excluded_students' => array(), 'practice' => 2, 'attendance_bonus' => 0, 'attendance_streak_length' => 3 );
		$row = $calculator->scoresForSubject( 'math', '2026-11-01 12:00:00', null, true, $policy )[1];
		self::assertSame( 4.0, $row['score'] );
		self::assertNull( $row['max_score'] );
		self::assertNull( $row['breakdown'][0]['max_amount'] );
	}

	public function test_each_subject_uses_its_own_weights_and_global_exclusions(): void {
		$before = $GLOBALS['_test_options'] ?? array();
		try {
			$configRepository = new PluginConfigRepository();
			$configRepository->save( array( 'ranking_subjects_version' => 2, 'ranking_excluded_students' => array( 2 ), 'ranking_subjects' => array(
				'math' => array( 'practice' => 2.0 ),
				'physics' => array( 'practice' => 3.0 ),
			) ) );
			$events = $this->createStub( LearnerRankingRepository::class );
			$events->method( 'studentIds' )->willReturn( array( 1, 2 ) );
			$events->method( 'workEvents' )->willReturn( array( array( 'student_person_id' => 1, 'source' => 'practice', 'task_points' => 2 ) ) );
			$calculator = new RankingCalculationService( $events, new RankingPointsPolicy( new PluginConfig( $configRepository ) ) );
			$math = $calculator->scoresForSubject( 'math', '2026-11-01 12:00:00' );
			$physics = $calculator->scoresForSubject( 'physics', '2026-11-01 12:00:00' );
			self::assertSame( 4.0, $math[1]['score'] );
			self::assertArrayNotHasKey( 2, $math );
			self::assertSame( 6.0, $physics[1]['score'] );
			self::assertArrayNotHasKey( 2, $physics );
		} finally {
			$GLOBALS['_test_options'] = $before;
		}
	}

	public function test_legacy_fraction_keeps_its_previous_effective_weight(): void {
		$before = $GLOBALS['_test_options'] ?? array();
		try {
			$repo = new PluginConfigRepository();
			$repo->save( array( 'ranking_practice' => 1.257 ) );
			$events = $this->createStub( LearnerRankingRepository::class );
			$events->method( 'studentIds' )->willReturn( array( 1 ) );
			$events->method( 'workEvents' )->willReturn( array( array( 'student_person_id' => 1, 'source' => 'practice', 'task_points' => 2 ) ) );
			$calculator = new RankingCalculationService( $events, new RankingPointsPolicy( new PluginConfig( $repo ) ) );
			$legacy = $calculator->scoresForSubject( 'math', '2026-11-01 12:00:00' );
			$repo->save( array( 'ranking_subjects_version' => 1, 'ranking_subjects' => array( 'math' => array( 'practice' => 1.257 ) ) ) );
			$migrated = $calculator->scoresForSubject( 'math', '2026-11-01 12:00:00' );
			self::assertSame( 2.52, $legacy[1]['score'] );
			self::assertSame( $legacy[1]['score'], $migrated[1]['score'] );
		} finally {
			$GLOBALS['_test_options'] = $before;
		}
	}
}
