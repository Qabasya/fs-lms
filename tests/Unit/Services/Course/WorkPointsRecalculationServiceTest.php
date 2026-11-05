<?php

declare( strict_types=1 );

namespace Tests\Unit\Services\Course;

use Inc\DTO\Course\SubmissionDTO;
use Inc\DTO\Course\WorkDTO;
use Inc\Managers\Course\WorkManager;
use Inc\Repositories\WPDBRepositories\SubmissionRepository;
use Inc\Services\Course\WorkPointsRecalculationService;
use PHPUnit\Framework\TestCase;

class WorkPointsRecalculationServiceTest extends TestCase {
	private function submission( int $id, ?int $taskId, array $extra = array() ): SubmissionDTO {
		return SubmissionDTO::fromArray( array_merge( array(
			'id' => $id, 'student_person_id' => 7, 'group_lesson_id' => 8, 'work_id' => 9,
			'work_type' => 'practice', 'task_id' => $taskId, 'status' => 'graded',
			'score' => 1, 'max_score' => 1, 'attempt_count' => 1,
		), $extra ) );
	}

	public function test_dry_run_counts_saved_verdict_without_writing(): void {
		$repo = $this->createMock( SubmissionRepository::class );
		$repo->method( 'listWorkAggregatesAfter' )->willReturn( array( $this->submission( 1, null, array( 'answer_text' => '{"10":{"verdict":"correct","score":1,"maxScore":1}}' ) ) ) );
		$repo->method( 'listPerTaskByStudentWorkLesson' )->willReturn( array( $this->submission( 2, 10 ) ) );
		$repo->expects( self::never() )->method( 'update' );
		$works = $this->createMock( WorkManager::class );
		$works->method( 'get' )->willReturn( WorkDTO::fromArray( array( 'id' => 9, 'work_type' => 'practice', 'item_ids' => array( 10 ), 'task_points' => array( 10 => 3 ) ) ) );
		$result = ( new WorkPointsRecalculationService( $repo, $works ) )->runBatch( 0, 100, true );
		self::assertSame( array( 'last_id' => 1, 'scanned' => 1, 'changed' => 1, 'skipped' => 0 ), $result );
	}

	public function test_incomplete_snapshot_is_skipped(): void {
		$repo = $this->createMock( SubmissionRepository::class );
		$repo->method( 'listWorkAggregatesAfter' )->willReturn( array( $this->submission( 1, null, array( 'answer_text' => '{"10":{"verdict":"correct"},"11":{"verdict":"correct"}}' ) ) ) );
		$repo->method( 'listPerTaskByStudentWorkLesson' )->willReturn( array( $this->submission( 2, 10 ) ) );
		$repo->expects( self::never() )->method( 'update' );
		$works = $this->createMock( WorkManager::class );
		$works->method( 'get' )->willReturn( WorkDTO::fromArray( array( 'id' => 9, 'item_ids' => array( 10, 11 ) ) ) );
		$result = ( new WorkPointsRecalculationService( $repo, $works ) )->runBatch( 0, 100, true );
		self::assertSame( 1, $result['skipped'] );
	}

	public function test_recalculation_keeps_teacher_score_and_updates_aggregate(): void {
		$repo = $this->createMock( SubmissionRepository::class );
		$repo->method( 'listWorkAggregatesAfter' )->willReturn( array( $this->submission( 1, null, array( 'answer_text' => '{"10":{"verdict":"correct","score":1,"maxScore":1},"11":{"verdict":"pending","score":0,"maxScore":2}}' ) ) ) );
		$repo->method( 'listPerTaskByStudentWorkLesson' )->willReturn( array(
			$this->submission( 2, 10 ),
			$this->submission( 3, 11, array( 'score' => 1, 'max_score' => 2, 'graded_by_user_id' => 99 ) ),
		) );
		$written = array();
		$repo->method( 'update' )->willReturnCallback( static function ( int $id, array $data ) use ( &$written ): bool { $written[ $id ] = $data; return true; } );
		$works = $this->createMock( WorkManager::class );
		$works->method( 'get' )->willReturn( WorkDTO::fromArray( array( 'id' => 9, 'item_ids' => array( 10, 11 ), 'task_points' => array( 10 => 3, 11 => 5 ) ) ) );
		$service = $this->getMockBuilder( WorkPointsRecalculationService::class )->setConstructorArgs( array( $repo, $works ) )->onlyMethods( array( 'inTransaction' ) )->getMock();
		$service->method( 'inTransaction' )->willReturnCallback( static fn( callable $operation ): mixed => $operation() );
		$service->runBatch( 0, 100, false );
		self::assertSame( array( 'score' => 3.0, 'max_score' => 3.0 ), $written[2] );
		self::assertArrayNotHasKey( 3, $written );
		self::assertSame( 4.0, $written[1]['score'] );
		self::assertSame( 5.0, $written[1]['max_score'] );
	}

	public function test_survey_and_unchanged_results_are_not_recalculated(): void {
		$repo = $this->createMock( SubmissionRepository::class );
		$repo->method( 'listWorkAggregatesAfter' )->willReturn( array( $this->submission( 1, null, array( 'answer_text' => '{"10":{"verdict":"correct","score":1,"maxScore":1}}' ) ) ) );
		$repo->method( 'listPerTaskByStudentWorkLesson' )->willReturn( array( $this->submission( 2, 10 ) ) );
		$repo->expects( self::never() )->method( 'update' );
		$works = $this->createMock( WorkManager::class );
		$works->method( 'get' )->willReturnOnConsecutiveCalls(
			WorkDTO::fromArray( array( 'id' => 9, 'work_type' => 'survey', 'item_ids' => array( 10 ) ) ),
			WorkDTO::fromArray( array( 'id' => 9, 'work_type' => 'practice', 'item_ids' => array( 10 ) ) )
		);
		$service = new WorkPointsRecalculationService( $repo, $works );
		self::assertSame( 1, $service->runBatch( 0, 100, true )['skipped'] );
		self::assertSame( 0, $service->runBatch( 0, 100, true )['changed'] );
	}
}
