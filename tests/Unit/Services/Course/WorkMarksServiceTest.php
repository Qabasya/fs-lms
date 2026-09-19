<?php

declare( strict_types=1 );

namespace Unit\Services\Course;

use Inc\DTO\Course\SubmissionDTO;
use Inc\DTO\Course\SubmissionInputDTO;
use Inc\Managers\Assessment\AssessmentManager;
use Inc\Managers\Course\WorkManager;
use Inc\Repositories\WPDBRepositories\AssessmentAnswerRepository;
use Inc\Repositories\WPDBRepositories\AssessmentAttemptRepository;
use Inc\Repositories\WPDBRepositories\SubmissionRepository;
use Inc\Services\Course\WorkMarksService;
use PHPUnit\Framework\TestCase;

/**
 * Полоска вердиктов работы. Регресс: первая сдача писала per-task строки без
 * `score`/`max_score`, и `graded`-строка читалась как «0 из 1» — все решённые
 * задания рисовались крестиками.
 */
class WorkMarksServiceTest extends TestCase {

	private SubmissionRepository $submissions;
	private WorkMarksService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->submissions = $this->createMock( SubmissionRepository::class );
		$this->service     = new WorkMarksService(
			$this->submissions,
			$this->createMock( WorkManager::class ),
			$this->createMock( AssessmentAttemptRepository::class ),
			$this->createMock( AssessmentAnswerRepository::class ),
			$this->createMock( AssessmentManager::class ),
		);
	}

	private function row( int $id, ?int $taskId, string $status, ?string $answer = null, ?float $score = null, ?float $max = null ): SubmissionDTO {
		return SubmissionDTO::fromArray( array(
			'id'                => $id,
			'student_person_id' => 1,
			'group_lesson_id'   => 2,
			'work_id'           => 3,
			'task_id'           => $taskId,
			'answer_text'       => $answer,
			'status'            => $status,
			'score'             => $score,
			'max_score'         => $max,
		) );
	}

	public function test_graded_rows_without_score_fall_back_to_snapshot(): void {
		$snapshot = wp_json_encode( array(
			10 => array( 'verdict' => 'correct' ),
			11 => array( 'verdict' => 'correct' ),
			12 => array( 'verdict' => 'incorrect' ),
		) );
		$this->submissions->method( 'find' )->willReturn( $this->row( 1, null, 'graded', $snapshot ) );
		$this->submissions->method( 'listPerTaskByStudentWorkLesson' )->willReturn( array(
			$this->row( 2, 10, 'graded' ),
			$this->row( 3, 11, 'graded' ),
			$this->row( 4, 12, 'graded' ),
		) );

		self::assertSame( array( 'correct', 'correct', 'incorrect' ), $this->service->marksFor( 'submission', 1 ) );
	}

	public function test_scored_rows_win_over_snapshot(): void {
		$snapshot = wp_json_encode( array( 10 => array( 'verdict' => 'incorrect' ) ) );
		$this->submissions->method( 'find' )->willReturn( $this->row( 1, null, 'graded', $snapshot ) );
		$this->submissions->method( 'listPerTaskByStudentWorkLesson' )->willReturn( array(
			$this->row( 2, 10, 'graded', null, 1.0, 1.0 ), // учитель засчитал
		) );

		self::assertSame( array( 'correct' ), $this->service->marksFor( 'submission', 1 ) );
	}

	public function test_input_dto_carries_score_only_when_given(): void {
		$without = ( new SubmissionInputDTO( 1, 2, 3, 'homework' ) )->toArray();
		$with    = ( new SubmissionInputDTO( 1, 2, 3, 'homework', score: 1.0, maxScore: 2.0 ) )->toArray();

		self::assertArrayNotHasKey( 'score', $without );
		self::assertSame( 1.0, $with['score'] );
		self::assertSame( 2.0, $with['max_score'] );
	}
}
