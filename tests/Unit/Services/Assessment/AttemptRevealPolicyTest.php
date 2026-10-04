<?php

declare( strict_types=1 );

namespace Unit\Services\Assessment;

use Inc\DTO\Assessment\AssessmentDTO;
use Inc\DTO\Assessment\AttemptDTO;
use Inc\Enums\Assessment\AssessmentKind;
use Inc\Enums\Assessment\AttemptStatus;
use Inc\Enums\Assessment\ScoringPolicy;
use Inc\DTO\Exam\ExamParticipationDTO;
use Inc\Repositories\WPDBRepositories\ExamParticipationRepository;
use Inc\Services\Assessment\AttemptRevealPolicy;
use PHPUnit\Framework\TestCase;

/**
 * D18: гейт видимости ответов/баллов ученику — разный для трёх видов контрольной.
 */
class AttemptRevealPolicyTest extends TestCase {

	private AttemptRevealPolicy $policy;

	private ExamParticipationRepository $participations;

	protected function setUp(): void {
		parent::setUp();
		$this->participations = $this->createMock( ExamParticipationRepository::class );
		$this->policy         = new AttemptRevealPolicy( $this->participations );
	}

	private function assessment( AssessmentKind $kind ): AssessmentDTO {
		return new AssessmentDTO(
			id: 1, subjectKey: 'inf', title: 'Работа', taskIds: array( 10 ),
			timeLimit: 0, attemptsAllowed: 0, passScore: 0.0,
			scoringPolicy: ScoringPolicy::Highest, status: 'publish',
			kind: $kind, taskPoints: array(), scoreMap: array(),
		);
	}

	private function attempt( AttemptStatus $status, ?string $approvedAt = null ): AttemptDTO {
		return new AttemptDTO(
			id: 1, assessmentId: 1, studentPersonId: 1, groupId: null, attemptNumber: 1,
			startedAt: '2026-01-01 10:00:00', deadlineAt: '2026-01-01 11:00:00', submittedAt: null,
			status: $status, totalScore: null, maxScore: null, gradedByUserId: null,
			createdAt: '2026-01-01 10:00:00', updatedAt: '2026-01-01 10:00:00',
			approvedAt: $approvedAt,
		);
	}

	public function test_control_rule_unchanged(): void {
		self::assertTrue( $this->policy->isRevealed(
			$this->assessment( AssessmentKind::Control ),
			$this->attempt( AttemptStatus::Submitted )
		) );
	}

	public function test_course_oge_rule_unchanged(): void {
		self::assertTrue( $this->policy->isRevealed(
			$this->assessment( AssessmentKind::OgeComputer ),
			$this->attempt( AttemptStatus::Graded )
		) );
	}

	/** ОГЭ: не оценено вручную (13-16) — статус Submitted, ответы скрыты. */
	public function test_oge_is_hidden_while_submitted(): void {
		self::assertFalse( $this->policy->isRevealed(
			$this->assessment( AssessmentKind::OgeComputer ),
			$this->attempt( AttemptStatus::Submitted )
		) );
	}

	/**
	 * ЕГЭ: Graded наступает сразу при сдаче (все автопроверяемые) — САМ ПО СЕБЕ
	 * не открывает ответы, нужен approved_at.
	 */
	public function test_ege_stays_hidden_when_graded_but_not_approved(): void {
		self::assertFalse( $this->policy->isRevealed(
			$this->assessment( AssessmentKind::EgeComputer ),
			$this->attempt( AttemptStatus::Graded )
		) );
	}

	public function test_ege_is_revealed_once_approved(): void {
		self::assertTrue( $this->policy->isRevealed(
			$this->assessment( AssessmentKind::EgeComputer ),
			$this->attempt( AttemptStatus::Graded, '2026-01-01 12:00:00' )
		) );
	}

	/** approved_at не влияет на ОГЭ-гейт — там условие только status===Graded. */
	public function test_oge_ignores_approved_at(): void {
		self::assertFalse( $this->policy->isRevealed(
			$this->assessment( AssessmentKind::OgeComputer ),
			$this->attempt( AttemptStatus::Submitted, '2026-01-01 12:00:00' )
		) );
	}
	private function examAttempt( AttemptStatus $status, ?string $approvedAt = null ): AttemptDTO {
		return new AttemptDTO(
			id: 1, assessmentId: 1, studentPersonId: 1, groupId: null, attemptNumber: 1,
			startedAt: '2026-01-01 10:00:00', deadlineAt: '2026-01-01 11:00:00', submittedAt: null,
			status: $status, totalScore: null, maxScore: null, gradedByUserId: null,
			createdAt: '2026-01-01 10:00:00', updatedAt: '2026-01-01 10:00:00',
			approvedAt: $approvedAt, examParticipationId: 7, examRegistrationId: 3,
		);
	}

	private function participation( string $audience ): void {
		$this->participations->method( 'find' )->willReturn( ExamParticipationDTO::fromArray( array(
			'id' => 7, 'event_id' => 1, 'participant_id' => 1, 'audience' => $audience, 'transfer_allowed' => 0,
			'version' => 1, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
		) ) );
	}

	public function test_exam_student_ege_hidden_until_approved(): void {
		$this->participation( 'student' );
		self::assertFalse( $this->policy->isRevealed(
			$this->assessment( AssessmentKind::EgeComputer ),
			$this->examAttempt( AttemptStatus::Graded )
		) );
		self::assertTrue( $this->policy->isRevealed(
			$this->assessment( AssessmentKind::EgeComputer ),
			$this->examAttempt( AttemptStatus::Graded, '2026-01-01 12:00:00' )
		) );
	}

	/** Экзаменный ОГЭ, в отличие от курсового, требует явного утверждения. */
	public function test_exam_student_oge_hidden_until_approved_even_when_graded(): void {
		$this->participation( 'student' );
		self::assertFalse( $this->policy->isRevealed(
			$this->assessment( AssessmentKind::OgeComputer ),
			$this->examAttempt( AttemptStatus::Graded )
		) );
	}

	public function test_exam_guest_revealed_after_submit(): void {
		$this->participation( 'guest' );
		self::assertTrue( $this->policy->isRevealed(
			$this->assessment( AssessmentKind::EgeComputer ),
			$this->examAttempt( AttemptStatus::Submitted )
		) );
	}

	public function test_exam_guest_hidden_while_in_progress(): void {
		$this->participation( 'guest' );
		self::assertFalse( $this->policy->isRevealed(
			$this->assessment( AssessmentKind::EgeComputer ),
			$this->examAttempt( AttemptStatus::InProgress )
		) );
	}

	public function test_exam_without_participation_row_is_hidden(): void {
		$this->participations->method( 'find' )->willReturn( null );
		self::assertFalse( $this->policy->isRevealed(
			$this->assessment( AssessmentKind::EgeComputer ),
			$this->examAttempt( AttemptStatus::Graded, '2026-01-01 12:00:00' )
		) );
	}
}
