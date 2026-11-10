<?php

declare( strict_types=1 );

namespace Tests\Unit\Services\Profile;

use Inc\Services\Profile\RankingAssignmentProjection;
use PHPUnit\Framework\TestCase;

class RankingAssignmentProjectionTest extends TestCase {
	public function test_counts_each_assigned_task_once_per_work_and_groups_by_price(): void {
		$rows = array(
			array( 'student_person_id' => 4, 'group_lesson_id' => 1, 'work_id' => 10, 'task_id' => 50, 'source' => 'practice', 'task_points' => 2, 'credited' => true ),
			array( 'student_person_id' => 4, 'group_lesson_id' => 1, 'work_id' => 10, 'task_id' => 50, 'source' => 'practice', 'task_points' => 2, 'credited' => true ),
			array( 'student_person_id' => 4, 'group_lesson_id' => 1, 'work_id' => 11, 'task_id' => 50, 'source' => 'practice', 'task_points' => 1, 'credited' => false ),
			array( 'student_person_id' => 4, 'group_lesson_id' => 2, 'work_id' => 10, 'task_id' => 50, 'source' => 'practice', 'task_points' => 2, 'credited' => true ),
		);
		$result = ( new RankingAssignmentProjection() )->build( $rows );
		self::assertSame( 4.0, $result[4]['practice']['amount'] );
		self::assertSame( 5.0, $result[4]['practice']['max_amount'] );
		self::assertSame( array( 1 => array( 'points' => 1.0, 'count' => 0, 'max_count' => 1 ), 2 => array( 'points' => 2.0, 'count' => 2, 'max_count' => 2 ) ), $result[4]['practice']['task_groups'] );
	}

	public function test_unknown_assignment_maximum_keeps_known_score_without_inventing_a_limit(): void {
		$rows = array(
			array( 'student_person_id' => 4, 'group_lesson_id' => 1, 'work_id' => 10, 'task_id' => 50, 'source' => 'homework', 'task_points' => 2, 'credited' => true ),
			array( 'student_person_id' => 4, 'group_lesson_id' => 2, 'work_id' => 11, 'task_id' => 0, 'source' => 'homework', 'task_points' => 0, 'credited' => false, 'max_unknown' => true ),
		);
		$result = ( new RankingAssignmentProjection() )->build( $rows );
		self::assertSame( 2.0, $result[4]['homework']['amount'] );
		self::assertNull( $result[4]['homework']['max_amount'] );
	}

	public function test_any_successful_attempt_credits_an_assignment_once(): void {
		$task = array( 'student_person_id' => 4, 'group_lesson_id' => 1, 'work_id' => 10, 'task_id' => 50, 'source' => 'homework', 'task_points' => 2 );
		$result = ( new RankingAssignmentProjection() )->build( array( $task + array( 'credited' => false ), $task + array( 'credited' => true ) ) );
		self::assertSame( 2.0, $result[4]['homework']['amount'] );
		self::assertSame( 2.0, $result[4]['homework']['max_amount'] );
	}
}
