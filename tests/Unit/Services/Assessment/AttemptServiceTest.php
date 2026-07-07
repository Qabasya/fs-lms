<?php

declare( strict_types=1 );

namespace Unit\Services\Assessment;

use Inc\Contracts\ClockInterface;
use Inc\Contracts\LogEventDispatcherInterface;
use Inc\DTO\Assessment\AssessmentDTO;
use Inc\DTO\Assessment\AttemptDTO;
use Inc\DTO\Assessment\EgeCompletenessResult;
use Inc\Enums\Assessment\AssessmentKind;
use Inc\Enums\Assessment\ScoringPolicy;
use Inc\Managers\Assessment\AssessmentManager;
use Inc\Repositories\WPDBRepositories\AssessmentAnswerRepository;
use Inc\Repositories\WPDBRepositories\AssessmentAttemptRepository;
use Inc\Services\Assessment\AssessmentAccessPolicy;
use Inc\Services\Assessment\AttemptService;
use Inc\Services\Assessment\AutoGradeService;
use Inc\Services\Assessment\EgeCompletenessChecker;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * T16.11 / T16.8: блок старта незавершённой ЕГЭ-работы (D16.3.б); Control не блокируется.
 */
class AttemptServiceTest extends TestCase {

	private AssessmentAttemptRepository&MockObject $attempts;
	private AssessmentAnswerRepository&MockObject  $answers;
	private AssessmentManager&MockObject           $assessments;
	private AutoGradeService&MockObject            $autoGrade;
	private LogEventDispatcherInterface&MockObject $dispatcher;
	private ClockInterface&MockObject              $clock;
	private AssessmentAccessPolicy&MockObject      $access;
	private EgeCompletenessChecker&MockObject      $completeness;
	private AttemptService                         $service;

	protected function setUp(): void {
		parent::setUp();
		$this->attempts     = $this->createMock( AssessmentAttemptRepository::class );
		$this->answers      = $this->createMock( AssessmentAnswerRepository::class );
		$this->assessments  = $this->createMock( AssessmentManager::class );
		$this->autoGrade    = $this->createMock( AutoGradeService::class );
		$this->dispatcher   = $this->createMock( LogEventDispatcherInterface::class );
		$this->clock        = $this->createMock( ClockInterface::class );
		$this->access       = $this->createMock( AssessmentAccessPolicy::class );
		$this->completeness = $this->createMock( EgeCompletenessChecker::class );

		$this->clock->method( 'now' )->willReturn( '2026-06-01 10:00:00' );
		$this->access->method( 'canAccess' )->willReturn( true );

		$this->service = new AttemptService(
			$this->attempts,
			$this->answers,
			$this->assessments,
			$this->autoGrade,
			$this->dispatcher,
			$this->clock,
			$this->access,
			$this->completeness,
		);
	}

	private function assessment( AssessmentKind $kind ): AssessmentDTO {
		return new AssessmentDTO(
			id: 1, subjectKey: 'inf', title: 'Работа', taskIds: array( 10 ),
			timeLimit: 0, attemptsAllowed: 0, passScore: 0.0,
			scoringPolicy: ScoringPolicy::Highest, status: 'publish',
			kind: $kind, taskPoints: array(), scoreMap: array(),
		);
	}

	private function incomplete(): EgeCompletenessResult {
		return new EgeCompletenessResult( array( '3' ), array(), array(), 27, 26 );
	}

	private function complete(): EgeCompletenessResult {
		return new EgeCompletenessResult( array(), array(), array(), 1, 1 );
	}

	private function seededAttempt(): AttemptDTO {
		return AttemptDTO::fromArray( array(
			'id' => 5, 'assessment_id' => 1, 'student_person_id' => 99,
			'group_id' => null, 'attempt_number' => 1,
			'started_at' => '2026-06-01 10:00:00', 'deadline_at' => '2026-06-01 11:00:00',
			'status' => 'in_progress',
		) );
	}

	public function test_ege_start_blocked_when_incomplete(): void {
		$this->assessments->method( 'get' )->willReturn( $this->assessment( AssessmentKind::Ege ) );
		$this->completeness->method( 'validate' )->willReturn( $this->incomplete() );

		// Попытка НЕ должна создаваться.
		$this->attempts->expects( $this->never() )->method( 'create' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'не укомплектована' );

		$this->service->start( 99, 1, null );
	}

	public function test_ege_start_allowed_when_complete(): void {
		$this->assessments->method( 'get' )->willReturn( $this->assessment( AssessmentKind::Ege ) );
		$this->completeness->method( 'validate' )->willReturn( $this->complete() );
		$this->attempts->method( 'countByAssessmentAndStudent' )->willReturn( 0 );
		$this->attempts->method( 'nextAttemptNumber' )->willReturn( 1 );
		$this->attempts->method( 'create' )->willReturn( 5 );
		$this->attempts->method( 'find' )->willReturn( $this->seededAttempt() );

		$attempt = $this->service->start( 99, 1, null );

		$this->assertSame( 5, $attempt->id );
	}

	public function test_control_start_not_blocked_by_completeness(): void {
		$this->assessments->method( 'get' )->willReturn( $this->assessment( AssessmentKind::Control ) );
		// Для Control проверка укомплектованности не должна вызываться вовсе.
		$this->completeness->expects( $this->never() )->method( 'validate' );
		$this->attempts->method( 'countByAssessmentAndStudent' )->willReturn( 0 );
		$this->attempts->method( 'nextAttemptNumber' )->willReturn( 1 );
		$this->attempts->method( 'create' )->willReturn( 5 );
		$this->attempts->method( 'find' )->willReturn( $this->seededAttempt() );

		$attempt = $this->service->start( 99, 1, null );

		$this->assertSame( 5, $attempt->id );
	}
}
