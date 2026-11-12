<?php

declare( strict_types=1 );

namespace Unit\Services\Course;

use Inc\Services\Course\RankingAssignmentSwapPlan;
use PHPUnit\Framework\TestCase;

class RankingAssignmentSwapPlanTest extends TestCase {
	public function test_lesson_snapshots_follow_the_topic_while_extra_snapshots_stay_on_date(): void {
		$one = array( 1 => array( 'origin' => 'lesson', 'task_points' => array( 3 => 5 ) ), 2 => array( 'origin' => 'extra', 'task_points' => array( 4 => 2 ) ) );
		$two = array( 3 => array( 'origin' => 'lesson', 'task_points' => array( 5 => 7 ) ), 4 => array( 'origin' => 'extra', 'task_points' => array( 6 => 3 ) ) );
		$swapped = ( new RankingAssignmentSwapPlan() )->build( $one, $two );
		self::assertEquals( array( 2 => $one[2], 3 => $two[3] ), $swapped[0] );
		self::assertEquals( array( 4 => $two[4], 1 => $one[1] ), $swapped[1] );
	}

	public function test_missing_origin_cannot_be_guessed(): void {
		$this->expectException( \DomainException::class );
		( new RankingAssignmentSwapPlan() )->build( array( 1 => array( 'task_points' => array( 3 => 5 ) ) ), array() );
	}

	public function test_collision_cannot_erase_either_history(): void {
		$this->expectException( \DomainException::class );
		( new RankingAssignmentSwapPlan() )->build( array( 1 => array( 'origin' => 'extra' ) ), array( 1 => array( 'origin' => 'lesson' ) ) );
	}
}
