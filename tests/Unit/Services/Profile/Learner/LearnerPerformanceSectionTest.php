<?php

declare( strict_types=1 );

namespace Unit\Services\Profile\Learner;

use Inc\DTO\Assessment\AssessmentDTO;
use Inc\DTO\Assessment\AttemptDTO;
use Inc\DTO\Course\GradebookEntryDTO;
use Inc\DTO\Profile\LearnerContextDTO;
use Inc\Enums\Assessment\AssessmentKind;
use Inc\Enums\Assessment\ScoringPolicy;
use Inc\Managers\Assessment\AssessmentManager;
use Inc\Managers\Course\LessonManager;
use Inc\Repositories\WPDBRepositories\AssessmentAttemptRepository;
use Inc\Repositories\WPDBRepositories\AttendanceRepository;
use Inc\Repositories\WPDBRepositories\SubmissionRepository;
use Inc\Services\Assessment\AttemptRevealPolicy;
use Inc\Services\Course\GradebookService;
use Inc\Services\Course\HomeworkDeadlineService;
use Inc\Services\Course\WorkMarksService;
use Inc\Services\Profile\Learner\LearnerPerformanceSection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Дневник ученика: экзамен станции, результат которого учитель ещё не утвердил, показывается
 * как «На проверке» — без балла и без крестиков по заданиям.
 */
class LearnerPerformanceSectionTest extends TestCase {

	private GradebookService&MockObject $gradebook;
	private AssessmentAttemptRepository&MockObject $attempts;
	private AssessmentManager&MockObject $assessments;
	private WorkMarksService&MockObject $marks;
	private LearnerPerformanceSection $section;

	protected function setUp(): void {
		parent::setUp();

		$this->gradebook   = $this->createMock( GradebookService::class );
		$this->attempts    = $this->createMock( AssessmentAttemptRepository::class );
		$this->assessments = $this->createMock( AssessmentManager::class );
		$this->marks       = $this->createMock( WorkMarksService::class );
		$deadlines         = $this->createMock( HomeworkDeadlineService::class );
		$deadlines->method( 'missed' )->willReturn( array() );

		$this->section = new LearnerPerformanceSection(
			$this->gradebook,
			$this->createMock( AttendanceRepository::class ),
			$this->createMock( SubmissionRepository::class ),
			$this->attempts,
			$this->createMock( LessonManager::class ),
			$deadlines,
			$this->marks,
			$this->assessments,
			new AttemptRevealPolicy(),
		);
	}

	private function entry(): GradebookEntryDTO {
		return new GradebookEntryDTO(
			studentPersonId: 11, groupId: 1, sourceType: 'attempt', sourceId: 5, title: 'Экзамен', category: 'assessment',
			score: 62.0, maxScore: 100.0, gradedAt: '2026-09-29 12:41:00', displayType: 'fraction',
		);
	}

	private function arrange( AssessmentKind $kind, ?string $approvedAt, string $status = 'graded' ): void {
		$this->gradebook->method( 'forStudent' )->willReturn( array( $this->entry() ) );
		$this->marks->method( 'marksFor' )->willReturn( array( 'incorrect', 'incorrect', 'correct' ) );
		$this->attempts->method( 'find' )->willReturn( AttemptDTO::fromArray( array(
			'id' => 5, 'assessment_id' => 9, 'student_person_id' => 11, 'attempt_number' => 1, 'status' => $status,
			'started_at' => '2026-09-29 10:00:00', 'deadline_at' => '2026-09-29 13:00:00', 'approved_at' => $approvedAt,
			'created_at' => '2026-09-29 10:00:00',
		) ) );
		$this->assessments->method( 'get' )->willReturn( new AssessmentDTO(
			id: 9, subjectKey: 'inf', title: 'Экзамен', taskIds: array( 1, 2, 3 ),
			timeLimit: 0, attemptsAllowed: 0, passScore: 0.0, scoringPolicy: ScoringPolicy::Highest,
			status: 'publish', kind: $kind, taskPoints: array(), scoreMap: array(),
		) );
	}

	private function grades(): array {
		return $this->section->grades( new LearnerContextDTO( array(), array(), array(), array(), array(), '2026-10-01 10:00:00' ), 11 );
	}

	public function test_unapproved_ege_exam_is_shown_as_pending_without_marks(): void {
		$this->arrange( AssessmentKind::EgeComputer, null );

		$row = $this->grades()[0];

		self::assertSame( 'pending', $row['display'] );
		self::assertSame( 'На проверке', $row['value'] );
		self::assertSame( array( 'pending', 'pending', 'pending' ), $row['marks'] );
	}

	public function test_approved_ege_exam_shows_real_result(): void {
		$this->arrange( AssessmentKind::EgeComputer, '2026-09-29 18:00:00' );

		$row = $this->grades()[0];

		self::assertSame( 'fraction', $row['display'] );
		self::assertSame( '62/100', $row['value'] );
		self::assertSame( array( 'incorrect', 'incorrect', 'correct' ), $row['marks'] );
	}

	public function test_control_exam_is_never_hidden(): void {
		$this->arrange( AssessmentKind::Control, null );

		$row = $this->grades()[0];

		self::assertSame( 'fraction', $row['display'] );
		self::assertSame( array( 'incorrect', 'incorrect', 'correct' ), $row['marks'] );
	}
}
