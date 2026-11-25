<?php

declare( strict_types=1 );

namespace Unit\Services\Course;

use Inc\Contracts\ClockInterface;
use Inc\DTO\Course\GroupLessonDTO;
use Inc\DTO\Course\SubmissionDTO;
use Inc\DTO\Course\WorkDTO;
use Inc\Enums\Course\WorkType;
use Inc\Managers\Course\WorkManager;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\TaskAttemptRepository;
use Inc\Repositories\WPDBRepositories\WorkTaskCheckRepository;
use Inc\Services\Course\ContinuationWorkProgressService;
use Inc\Services\Course\LessonVisibilityService;
use Inc\Services\Course\WorkMarksService;
use PHPUnit\Framework\TestCase;

class ContinuationWorkProgressServiceTest extends TestCase {

	private const DATES = array( 5 => '2026-10-01 10:00:00', 9 => '2026-10-05 10:00:00' );
	private TaskAttemptRepository $attempts;
	private WorkTaskCheckRepository $checks;
	private WorkMarksService $marks;
	private GroupLessonRepository $lessons;
	private ContinuationWorkProgressService $service;

	protected function setUp(): void {
		$works = $this->createStub( WorkManager::class );
		$works->method( 'get' )->willReturn( new WorkDTO( 20, 'inf', 'Практика', WorkType::Practice, range( 1, 5 ), '', 1, 'publish' ) );
		$clock = $this->createStub( ClockInterface::class );
		$clock->method( 'now' )->willReturn( '2026-10-06 12:00:00' );
		$visibility = $this->createStub( LessonVisibilityService::class );
		$visibility->method( 'effectiveVisibility' )->willReturnCallback( static fn( $row ) => $row->visibility );
		$this->service = new ContinuationWorkProgressService(
			$this->lessons = $this->createStub( GroupLessonRepository::class ), $works, $this->marks = $this->createStub( WorkMarksService::class ),
			$this->attempts = $this->createStub( TaskAttemptRepository::class ), $this->checks = $this->createStub( WorkTaskCheckRepository::class ),
			$visibility, $clock
		);
	}

	private function row( int $id, ?int $source, ?string $at, string $status = 'held' ): GroupLessonDTO {
		return GroupLessonDTO::fromArray( array(
			'id' => $id, 'group_id' => 1, 'position' => $id, 'continued_from_id' => $source, 'scheduled_at' => $at,
			'status' => $status, 'visibility' => 'open',
		) );
	}

	private function submission(): SubmissionDTO {
		return SubmissionDTO::fromArray( array(
			'id' => 50, 'student_person_id' => 7, 'group_lesson_id' => 5, 'work_id' => 20, 'attempt_count' => 2,
			'submitted_at' => '2026-10-05 11:00:00', 'status' => 'graded',
		) );
	}

	public function test_latest_submission_through_the_original_link_uses_the_time_of_each_answer(): void {
		$first = $this->row( 5, null, self::DATES[5] );
		$second = $this->row( 9, 5, self::DATES[9] );
		$this->lessons->method( 'listByGroup' )->willReturn( array(
			$second, $this->row( 17, 5, '2026-10-04 10:00:00', 'cancelled' ), $first,
			$this->row( 21, 5, '2026-10-10 10:00:00' ), $this->row( 22, 5, null ),
		) );
		$later = '2026-10-05 11:00:00';
		$this->marks->method( 'submissionTasks' )->willReturn( array(
			1 => array( 'mark' => 'corrected', 'submitted_at' => $later, 'recorded' => true ),
			2 => array( 'mark' => 'correct', 'submitted_at' => $later, 'recorded' => true ),
			3 => array( 'mark' => 'correct', 'submitted_at' => $later, 'recorded' => true ),
			4 => array( 'mark' => 'incorrect', 'submitted_at' => $later, 'recorded' => true ),
			5 => array( 'mark' => 'pending', 'submitted_at' => $later, 'recorded' => true ),
		) );
		$this->attempts->method( 'workAnswerTimes' )->willReturn( array(
			1 => array( 'correct' => $later ), // Перед общей сдачей уже была верная проверка.
			2 => array( 'last' => '2026-10-04 23:59:00' ), // Учитель позже зачёл этот ответ вручную.
			3 => array( 'correct' => self::DATES[9] ), // Ровно в начале продолжения.
		) );
		$this->checks->method( 'correctTimes' )->willReturn( array(
			1 => array( 'round' => 1, 'at' => '2026-10-01 10:45:00' ),
			4 => array( 'round' => 1, 'at' => '2026-10-01 10:46:00' ), // Учитель снял зачёт.
		) );

		$result = $this->service->forSubmission( $first, $this->submission() );
		self::assertSame( $result, $this->service->forSubmission( $second, $this->submission() ) );
		self::assertSame( array( 5, 9 ), array_column( $result, 'group_lesson_id' ) );
		self::assertSame( array( 2, 1 ), array_column( $result, 'new_solved' ) );
		self::assertSame( array( 2, 3 ), array_column( $result, 'solved' ) );
		self::assertSame( array( 'corrected', 'correct', 'remaining', 'remaining', 'remaining' ), $result[0]['marks'] );
		self::assertSame( array( 'corrected', 'correct', 'correct', 'incorrect', 'pending' ), $result[1]['marks'] );
	}

	public function test_correct_inline_check_is_visible_before_any_submission_and_carries_forward_once(): void {
		$this->checks->method( 'correctTimes' )->willReturn( array( 2 => array( 'round' => 1, 'at' => self::DATES[5] ) ) );
		$result = $this->service->forWork( self::DATES, 7, 5, 20, null );
		self::assertSame( array( 1, 0 ), array_column( $result, 'new_solved' ) );
		self::assertSame( array( 1, 1 ), array_column( $result, 'solved' ) );
		self::assertSame( array( 5, 5 ), array_column( $result, 'total' ) );
	}

	public function test_legacy_snapshot_without_task_dates_never_assigns_every_solution_to_the_last_retry(): void {
		$this->marks->method( 'submissionTasks' )->willReturn( array( 1 => array( 'mark' => 'correct', 'submitted_at' => null, 'recorded' => true ) ) );
		$result = $this->service->forWork( self::DATES, 7, 5, 20, $this->submission() );
		self::assertSame( array( null, null ), array_column( $result, 'new_solved' ) );
		self::assertSame( array( null, 1 ), array_column( $result, 'solved' ) );
	}
}
