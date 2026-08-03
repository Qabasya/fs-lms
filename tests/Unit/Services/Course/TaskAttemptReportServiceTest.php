<?php

declare( strict_types=1 );

namespace Unit\Services\Course;

use Inc\DTO\Assessment\AttemptDTO;
use Inc\DTO\Enrollment\StudentRecordDTO;
use Inc\DTO\Task\TaskAttemptDTO;
use Inc\Enums\Assessment\AttemptStatus;
use Inc\Enums\Course\AttemptSource;
use Inc\Repositories\WPDBRepositories\AssessmentAttemptRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Repositories\WPDBRepositories\TaskAttemptRepository;
use Inc\Services\Course\TaskAttemptReportService;
use PHPUnit\Framework\TestCase;

/**
 * Отчёт «Решения задач»: группировка попыток занятия по шагам и ученикам.
 */
class TaskAttemptReportServiceTest extends TestCase {

	private TaskAttemptRepository&\PHPUnit\Framework\MockObject\MockObject $attempts;
	private StudentRecordRepository&\PHPUnit\Framework\MockObject\MockObject $records;
	private AssessmentAttemptRepository&\PHPUnit\Framework\MockObject\MockObject $exams;
	private TaskAttemptReportService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->attempts = $this->createMock( TaskAttemptRepository::class );
		$this->records  = $this->createMock( StudentRecordRepository::class );
		$this->exams    = $this->createMock( AssessmentAttemptRepository::class );
		$this->service  = new TaskAttemptReportService( $this->attempts, $this->records, $this->exams );

		$this->exams->method( 'listByGroupLesson' )->willReturn( array() );
	}

	public function test_groups_attempts_by_step_and_student(): void {
		$this->attempts->method( 'listByGroupLesson' )->willReturn( array(
			$this->attempt( 'step-a', 10, 1, true ),
			$this->attempt( 'step-a', 11, 1, false ),
			$this->attempt( 'step-a', 11, 2, true ),
			$this->attempt( 'step-b', 10, 1, false ),
		) );
		$this->records->method( 'findActiveByGroupId' )->willReturn( array() );

		$steps = $this->service->forLesson( 1, 5 )['steps'];

		self::assertCount( 2, $steps );
		self::assertSame( 'step-a', $steps[0]['step_key'] );
		self::assertCount( 2, $steps[0]['students'] );
		self::assertCount( 2, $steps[0]['students'][1]['attempts'] );
		self::assertSame( 2, $steps[0]['students'][1]['tries'] );
	}

	/** Шаг считается решённым учеником, если верна ХОТЬ ОДНА попытка. */
	public function test_counts_solved_students_per_step(): void {
		$this->attempts->method( 'listByGroupLesson' )->willReturn( array(
			$this->attempt( 'step-a', 10, 1, false ),
			$this->attempt( 'step-a', 10, 2, true ),
			$this->attempt( 'step-a', 11, 1, false ),
		) );
		$this->records->method( 'findActiveByGroupId' )->willReturn( array() );

		$step = $this->service->forLesson( 1, 5 )['steps'][0];

		self::assertSame( 2, $step['total'] );
		self::assertSame( 1, $step['solved'] );
		self::assertTrue( $step['students'][0]['solved'] );
		self::assertFalse( $step['students'][1]['solved'] );
	}

	/** Имена — из снимка student_records, зашифрованные ПД не трогаем. */
	public function test_takes_names_from_roster_snapshot(): void {
		$this->attempts->method( 'listByGroupLesson' )->willReturn( array(
			$this->attempt( 'step-a', 10, 1, true ),
			$this->attempt( 'step-a', 99, 1, true ),
		) );
		$this->records->method( 'findActiveByGroupId' )->willReturn( array( $this->record( 10, 'Леклер', 'Шарль' ) ) );

		$students = $this->service->forLesson( 1, 5 )['steps'][0]['students'];

		self::assertSame( 'Леклер Шарль', $students[0]['name'] );
		// Ученик выбыл из группы — попытки остались, имени в ростере нет.
		self::assertSame( 'Ученик #99', $students[1]['name'] );
	}

	public function test_returns_empty_steps_when_no_attempts(): void {
		$this->attempts->method( 'listByGroupLesson' )->willReturn( array() );
		$this->records->method( 'findActiveByGroupId' )->willReturn( array() );

		$report = $this->service->forLesson( 1, 5 );

		self::assertSame( array(), $report['steps'] );
		self::assertSame( array(), $report['works'] );
		self::assertSame( array(), $report['exams'] );
	}

	/** Пересдачи работы приходят из той же таблицы, но с ключом `work:{id}`. */
	public function test_separates_work_attempts_from_lesson_steps(): void {
		$this->attempts->method( 'listByGroupLesson' )->willReturn( array(
			$this->attempt( 'step-a', 10, 1, true ),
			$this->attempt( AttemptSource::workStepKey( 77 ), 10, 1, false ),
			$this->attempt( AttemptSource::workStepKey( 77 ), 10, 2, true ),
		) );
		$this->records->method( 'findActiveByGroupId' )->willReturn( array() );

		$report = $this->service->forLesson( 1, 5 );

		self::assertCount( 1, $report['steps'] );
		self::assertSame( 'lesson', $report['steps'][0]['source'] );

		self::assertCount( 1, $report['works'] );
		self::assertSame( 'work', $report['works'][0]['source'] );
		self::assertSame( 77, $report['works'][0]['work_id'] );
		self::assertSame( 2, $report['works'][0]['students'][0]['tries'] );
	}

	/** Одна и та же задача в разных работах — разные строки отчёта. */
	public function test_splits_same_task_across_different_works(): void {
		$this->attempts->method( 'listByGroupLesson' )->willReturn( array(
			$this->attempt( AttemptSource::workStepKey( 77 ), 10, 1, true ),
			$this->attempt( AttemptSource::workStepKey( 88 ), 10, 1, true ),
		) );
		$this->records->method( 'findActiveByGroupId' )->willReturn( array() );

		self::assertCount( 2, $this->service->forLesson( 1, 5 )['works'] );
	}

	public function test_collects_exam_attempts_with_best_score_and_retakes(): void {
		$this->attempts->method( 'listByGroupLesson' )->willReturn( array() );
		$this->records->method( 'findActiveByGroupId' )->willReturn( array() );
		$this->exams = $this->createMock( AssessmentAttemptRepository::class );
		$this->exams->method( 'listByGroupLesson' )->willReturn( array(
			$this->examAttempt( 500, 10, 1, 4.0 ),
			$this->examAttempt( 500, 10, 2, 7.0 ),
			$this->examAttempt( 500, 11, 1, 9.0 ),
		) );
		$service = new TaskAttemptReportService( $this->attempts, $this->records, $this->exams );

		$exams = $service->forLesson( 1, 5 )['exams'];

		self::assertCount( 1, $exams );
		self::assertSame( 2, $exams[0]['total'] );
		// Пересдача только у первого ученика.
		self::assertSame( 1, $exams[0]['retakes'] );
		self::assertSame( 7.0, $exams[0]['students'][0]['best_score'] );
		self::assertSame( 9.0, $exams[0]['students'][1]['best_score'] );
	}

	private function examAttempt( int $assessmentId, int $personId, int $number, float $score ): AttemptDTO {
		return new AttemptDTO(
			id             : $number,
			assessmentId   : $assessmentId,
			studentPersonId: $personId,
			groupId        : 1,
			attemptNumber  : $number,
			startedAt      : '2026-08-01 10:00:00',
			deadlineAt     : '2026-08-01 12:00:00',
			submittedAt    : '2026-08-01 11:00:00',
			status         : AttemptStatus::Graded,
			totalScore     : $score,
			maxScore       : 10.0,
			gradedByUserId : 1,
			createdAt      : '2026-08-01 10:00:00',
			updatedAt      : '2026-08-01 11:00:00',
			groupLessonId  : 5,
		);
	}

	private function attempt( string $stepKey, int $personId, int $number, bool $correct ): TaskAttemptDTO {
		return new TaskAttemptDTO(
			id              : $number,
			studentPersonId : $personId,
			groupLessonId   : 5,
			stepKey         : $stepKey,
			taskId          : 700,
			attemptNumber   : $number,
			answer          : 'a',
			isCorrect       : $correct,
			score           : $correct ? 1.0 : 0.0,
			maxScore        : 1.0,
			itemFeedback    : null,
			createdAt       : '2026-08-01 10:00:00',
		);
	}

	private function record( int $personId, string $last, string $first ): StudentRecordDTO {
		return StudentRecordDTO::fromArray( array(
			'id'                 => $personId,
			'student_person_id'  => $personId,
			'snapshot_last_name' => $last,
			'snapshot_first_name'=> $first,
			'created_at'         => '2026-01-01 00:00:00',
			'updated_at'         => '2026-01-01 00:00:00',
		) );
	}
}
