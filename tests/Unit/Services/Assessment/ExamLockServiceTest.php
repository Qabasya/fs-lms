<?php

declare( strict_types=1 );

namespace Unit\Services\Assessment;

use Inc\DTO\Assessment\AssessmentDTO;
use Inc\DTO\Assessment\AttemptDTO;
use Inc\Enums\Assessment\AssessmentKind;
use Inc\Enums\Assessment\AttemptStatus;
use Inc\Enums\Assessment\ScoringPolicy;
use Inc\Managers\Assessment\AssessmentManager;
use Inc\Repositories\WPDBRepositories\AssessmentAttemptRepository;
use Inc\Services\Assessment\ExamLockService;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Inc\Services\Assessment\ExamLockService
 */
class ExamLockServiceTest extends TestCase {

	private AssessmentAttemptRepository $attempts;
	private AssessmentManager           $assessments;
	private ExamLockService             $svc;

	protected function setUp(): void {
		$this->attempts    = $this->createMock( AssessmentAttemptRepository::class );
		$this->assessments = $this->createMock( AssessmentManager::class );
		$this->svc         = new ExamLockService( $this->attempts, $this->assessments );
	}

	private function attempt( int $assessmentId ): AttemptDTO {
		return new AttemptDTO(
			id              : 1,
			assessmentId    : $assessmentId,
			studentPersonId : 10,
			groupId         : null,
			attemptNumber   : 1,
			startedAt       : '2024-01-01 10:00:00',
			deadlineAt      : '2024-01-01 11:00:00',
			submittedAt     : null,
			status          : AttemptStatus::InProgress,
			totalScore      : null,
			maxScore        : null,
			gradedByUserId  : null,
			createdAt       : '2024-01-01 10:00:00',
			updatedAt       : '2024-01-01 10:00:00',
		);
	}

	private function assessment( AssessmentKind $kind ): AssessmentDTO {
		return new AssessmentDTO(
			id              : 5,
			subjectKey      : 'inf',
			title           : 'ЕГЭ',
			taskIds         : [],
			timeLimit       : 0,
			attemptsAllowed : 1,
			passScore       : 0.0,
			scoringPolicy   : ScoringPolicy::Highest,
			status          : 'publish',
			kind            : $kind,
			taskPoints      : [],
			scoreMap        : [],
		);
	}

	public function test_no_active_attempt_not_locked(): void {
		$this->attempts->method( 'findAnyActive' )->willReturn( null );
		self::assertFalse( $this->svc->isLocked( 10 ) );
		self::assertNull( $this->svc->getActiveLockingAttempt( 10 ) );
	}

	public function test_active_control_exam_locks(): void {
		$attempt    = $this->attempt( 5 );
		$assessment = $this->assessment( AssessmentKind::Control );

		$this->attempts->method( 'findAnyActive' )->willReturn( $attempt );
		$this->assessments->method( 'get' )->with( 5 )->willReturn( $assessment );

		self::assertTrue( $this->svc->isLocked( 10 ) );
		self::assertSame( $attempt, $this->svc->getActiveLockingAttempt( 10 ) );
	}

	public function test_active_ege_exam_locks(): void {
		$attempt    = $this->attempt( 5 );
		$assessment = $this->assessment( AssessmentKind::Ege );

		$this->attempts->method( 'findAnyActive' )->willReturn( $attempt );
		$this->assessments->method( 'get' )->willReturn( $assessment );

		self::assertTrue( $this->svc->isLocked( 10 ) );
	}

	public function test_active_ege_computer_exam_locks(): void {
		$attempt    = $this->attempt( 5 );
		$assessment = $this->assessment( AssessmentKind::EgeComputer );

		$this->attempts->method( 'findAnyActive' )->willReturn( $attempt );
		$this->assessments->method( 'get' )->willReturn( $assessment );

		self::assertTrue( $this->svc->isLocked( 10 ) );
	}

	public function test_assessment_not_found_not_locked(): void {
		$this->attempts->method( 'findAnyActive' )->willReturn( $this->attempt( 99 ) );
		$this->assessments->method( 'get' )->willReturn( null );

		self::assertFalse( $this->svc->isLocked( 10 ) );
	}
}
