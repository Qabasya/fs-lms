<?php

declare( strict_types=1 );

namespace Unit\Services\Assessment;

use Inc\Contracts\ClockInterface;
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
		$clock            = $this->createMock( ClockInterface::class );
		$clock->method( 'now' )->willReturn( '2024-01-01 10:30:00' );
		$this->svc         = new ExamLockService( $this->attempts, $this->assessments, $clock );
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
		$assessment = $this->assessment( AssessmentKind::EgeComputer );

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

	private function examAttempt( int $assessmentId ): AttemptDTO {
		return AttemptDTO::fromArray( array(
			'id' => 2, 'assessment_id' => $assessmentId, 'student_person_id' => 10, 'group_id' => null,
			'attempt_number' => 1, 'started_at' => '2024-01-01 10:00:00', 'deadline_at' => '2024-01-01 11:00:00',
			'status' => 'in_progress', 'exam_participation_id' => 7, 'exam_registration_id' => 3,
		) );
	}

	public function test_exam_attempt_outside_course_locks_content(): void {
		$this->attempts->method( 'findAnyActive' )->willReturn( $this->examAttempt( 5 ) );
		$this->assessments->method( 'get' )->willReturn( $this->assessment( AssessmentKind::EgeComputer ) );

		self::assertTrue( $this->svc->isLocked( 10 ) );
		self::assertTrue( $this->svc->getActiveLockingAttempt( 10 )->isExam() );
	}

	public function test_lock_released_after_submit(): void {
		// Сдача переводит попытку из in_progress — запрос активной попытки возвращает null.
		$this->attempts->method( 'findAnyActive' )->willReturn( null );

		self::assertFalse( $this->svc->isLocked( 10 ) );
	}

	/**
	 * Репозиторий отдаёт активной только попытку `in_progress` с дедлайном позже «сейчас»; «сейчас» — местное время сайта из часов.
	 * Поэтому блокировка снимается и когда дедлайн просто прошёл (до того, как cron завершил попытку), и после её завершения.
	 */
	public function test_lock_released_after_deadline_finalize(): void {
		$stored = $this->examAttempt( 5 ); // дедлайн 11:00, часы теста — 10:30
		$this->attempts->method( 'findAnyActive' )->willReturnCallback(
			static fn ( int $person, string $nowLocal ): ?AttemptDTO => 'in_progress' === $stored->status->value && $stored->deadlineAt > $nowLocal ? $stored : null
		);
		$this->assessments->method( 'get' )->willReturn( $this->assessment( AssessmentKind::EgeComputer ) );
		self::assertTrue( $this->svc->isLocked( 10 ), 'До дедлайна экзамен запирает контент.' );

		$late     = new ExamLockService( $this->attempts, $this->assessments, $this->clockAt( '2024-01-01 11:00:01' ) );
		self::assertFalse( $late->isLocked( 10 ), 'Дедлайн прошёл — лока нет, даже если попытка ещё не помечена завершённой.' );
	}

	private function clockAt( string $local ): ClockInterface {
		$clock = $this->createMock( ClockInterface::class );
		$clock->method( 'now' )->willReturn( $local );

		return $clock;
	}

	public function test_lock_lookup_uses_local_site_time_from_clock(): void {
		$this->attempts->expects( self::once() )->method( 'findAnyActive' )->with( 10, '2024-01-01 10:30:00' )->willReturn( null );

		$this->svc->isLocked( 10 );
	}
}
