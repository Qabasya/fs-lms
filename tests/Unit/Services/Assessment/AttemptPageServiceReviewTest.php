<?php

declare( strict_types=1 );

namespace Unit\Services\Assessment;

use Inc\Contracts\ClockInterface;
use Inc\DTO\Assessment\AssessmentDTO;
use Inc\DTO\Assessment\AttemptDTO;
use Inc\DTO\Exam\AttemptContext;
use Inc\DTO\Person\PersonDTO;
use Inc\Enums\Assessment\AssessmentKind;
use Inc\Enums\Assessment\ScoringPolicy;
use Inc\Enums\Exam\ExamAudience;
use Inc\Enums\Log\ErrorCode;
use Inc\Repositories\WPDBRepositories\AssessmentAttemptRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Services\Assessment\AssessmentAccessPolicy;
use Inc\Services\Assessment\AttemptOutcomeService;
use Inc\Services\Assessment\AttemptPageService;
use Inc\Services\Assessment\AttemptResultService;
use Inc\Services\Assessment\AttemptService;
use Inc\Services\Assessment\AttemptTaskViewBuilder;
use Inc\Services\Course\GroupAccessGuard;
use Inc\Services\Exam\ExamAttemptService;
use Inc\Shared\CodedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Просмотр результата конкретной попытки станции (`?attempt=ID`): преподаватель группы
 * видит работу ученика сразу, ученик — только свою и по правилу утверждения, остальным — ничего.
 */
class AttemptPageServiceReviewTest extends TestCase {

	private AssessmentAttemptRepository&MockObject $attempts;
	private PersonRepository&MockObject $persons;
	private AssessmentAccessPolicy&MockObject $access;
	private GroupAccessGuard&MockObject $guard;
	private AttemptResultService&MockObject $results;
	private AttemptOutcomeService&MockObject $outcome;
	private AttemptService&MockObject $attemptService;
	private ExamAttemptService&MockObject $examAttempts;
	private AttemptPageService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->attempts = $this->createMock( AssessmentAttemptRepository::class );
		$this->persons  = $this->createMock( PersonRepository::class );
		$this->access   = $this->createMock( AssessmentAccessPolicy::class );
		$this->guard    = $this->createMock( GroupAccessGuard::class );
		$this->results        = $this->createMock( AttemptResultService::class );
		$this->outcome        = $this->createMock( AttemptOutcomeService::class );
		$this->attemptService = $this->createMock( AttemptService::class );
		$this->examAttempts   = $this->createMock( ExamAttemptService::class );
		$clock          = $this->createMock( ClockInterface::class );
		$clock->method( 'now' )->willReturn( '2026-10-01 10:00:00' );

		$this->service = new AttemptPageService(
			$this->attempts,
			$this->persons,
			$this->access,
			$this->results,
			$this->outcome,
			$this->createMock( AttemptTaskViewBuilder::class ),
			$clock,
			$this->guard,
			$this->attemptService,
			$this->examAttempts,
		);
	}

	private function person( int $id ): PersonDTO {
		return PersonDTO::fromArray( array(
			'id' => $id, 'last_name' => 'Новиков', 'first_name' => 'М', 'is_student' => true,
			'created_at' => '2026-01-01', 'updated_at' => '2026-01-01',
		) );
	}

	private function attempt( int $student = 11, ?int $groupId = 1, string $status = 'graded', int $assessmentId = 9 ): AttemptDTO {
		return AttemptDTO::fromArray( array(
			'id' => 5, 'assessment_id' => $assessmentId, 'student_person_id' => $student, 'group_id' => $groupId,
			'attempt_number' => 1, 'status' => $status, 'started_at' => '2026-09-29 10:00:00',
			'deadline_at' => '2026-09-29 13:00:00', 'submitted_at' => '2026-09-29 12:41:00',
		) );
	}

	private function assessment(): AssessmentDTO {
		return new AssessmentDTO(
			id: 9, subjectKey: 'inf', title: 'Экзамен', taskIds: array( 1 ), timeLimit: 0, attemptsAllowed: 0, passScore: 0.0,
			scoringPolicy: ScoringPolicy::Highest, status: 'publish', kind: AssessmentKind::EgeComputer,
			taskPoints: array(), scoreMap: array(),
		);
	}

	public function test_group_manager_sees_the_students_attempt_revealed(): void {
		$this->attempts->method( 'find' )->willReturn( $this->attempt() );
		$this->persons->method( 'findByWpUserId' )->willReturn( $this->person( 77 ) ); // преподаватель
		$this->persons->method( 'find' )->willReturn( $this->person( 11 ) );
		$this->guard->method( 'canManage' )->with( 1, 51 )->willReturn( true );

		$page = $this->service->buildReview( $this->assessment(), 5, 51 );

		self::assertNotNull( $page );
		self::assertTrue( $page->reviewMode );
		self::assertTrue( $page->reviewReveal, 'преподаватель видит результат до утверждения' );
		self::assertSame( 5, $page->lastAttempt->id );
		self::assertNull( $page->activeAttempt );
		self::assertSame( 11, $page->person->id, 'в плашке — ученик, а не преподаватель' );
	}

	public function test_student_sees_own_attempt_with_normal_reveal_rule(): void {
		$this->attempts->method( 'find' )->willReturn( $this->attempt( 11 ) );
		$this->persons->method( 'findByWpUserId' )->willReturn( $this->person( 11 ) );
		$this->guard->expects( self::never() )->method( 'canManage' );

		$page = $this->service->buildReview( $this->assessment(), 5, 102 );

		self::assertNotNull( $page );
		self::assertFalse( $page->reviewReveal, 'ученика ждёт «На проверке», пока не утверждено' );
	}

	public function test_stranger_gets_nothing(): void {
		$this->attempts->method( 'find' )->willReturn( $this->attempt() );
		$this->persons->method( 'findByWpUserId' )->willReturn( $this->person( 99 ) );
		$this->guard->method( 'canManage' )->willReturn( false );

		self::assertNull( $this->service->buildReview( $this->assessment(), 5, 200 ) );
	}

	public function test_attempt_of_another_assessment_is_refused(): void {
		$this->attempts->method( 'find' )->willReturn( $this->attempt( 11, 1, 'graded', 123 ) );
		$this->persons->method( 'findByWpUserId' )->willReturn( $this->person( 11 ) );

		self::assertNull( $this->service->buildReview( $this->assessment(), 5, 102 ) );
	}

	public function test_attempt_in_progress_has_no_result_page(): void {
		$this->attempts->method( 'find' )->willReturn( $this->attempt( 11, 1, 'in_progress' ) );
		$this->persons->method( 'findByWpUserId' )->willReturn( $this->person( 11 ) );

		self::assertNull( $this->service->buildReview( $this->assessment(), 5, 102 ) );
	}

	public function test_unknown_attempt_is_refused(): void {
		$this->attempts->method( 'find' )->willReturn( null );

		self::assertNull( $this->service->buildReview( $this->assessment(), 999, 51 ) );
	}

	public function test_attempt_outside_a_group_needs_exam_authoring_access(): void {
		$this->attempts->method( 'find' )->willReturn( $this->attempt( 11, null ) );
		$this->persons->method( 'findByWpUserId' )->willReturn( $this->person( 77 ) );
		$this->persons->method( 'find' )->willReturn( $this->person( 11 ) );
		$this->guard->expects( self::never() )->method( 'canManage' );
		$this->access->method( 'canPreview' )->with( 51, 9 )->willReturn( true );

		self::assertTrue( $this->service->buildReview( $this->assessment(), 5, 51 )?->reviewReveal );
	}

	/* ── Политика раскрытия: итог и разбор только раскрытым попыткам ── */

	public function test_student_review_of_unapproved_exam_attempt_has_no_scores(): void {
		$this->attempts->method( 'find' )->willReturn( $this->attempt( 11 ) );
		$this->persons->method( 'findByWpUserId' )->willReturn( $this->person( 11 ) );
		$this->attemptService->method( 'isRevealed' )->willReturn( false );
		$this->results->expects( self::never() )->method( 'studentPerTask' );
		$this->outcome->expects( self::never() )->method( 'label' );

		$page = $this->service->buildReview( $this->assessment(), 5, 102 );

		self::assertNotNull( $page );
		self::assertSame( array(), $page->resultPerTask );
		self::assertSame( '', $page->outcome );
		self::assertFalse( $page->reviewReveal );
	}

	public function test_student_review_of_revealed_attempt_keeps_results(): void {
		$this->attempts->method( 'find' )->willReturn( $this->attempt( 11 ) );
		$this->persons->method( 'findByWpUserId' )->willReturn( $this->person( 11 ) );
		$this->attemptService->method( 'isRevealed' )->willReturn( true );
		$this->results->method( 'studentPerTask' )->willReturn( array( array( 'n' => 1 ) ) );
		$this->outcome->method( 'label' )->willReturn( '72' );

		$page = $this->service->buildReview( $this->assessment(), 5, 102 );

		self::assertSame( array( array( 'n' => 1 ) ), $page->resultPerTask );
		self::assertSame( '72', $page->outcome );
	}

	public function test_manager_review_gets_results_even_when_not_revealed(): void {
		$this->attempts->method( 'find' )->willReturn( $this->attempt() );
		$this->persons->method( 'findByWpUserId' )->willReturn( $this->person( 77 ) );
		$this->persons->method( 'find' )->willReturn( $this->person( 11 ) );
		$this->guard->method( 'canManage' )->willReturn( true );
		$this->attemptService->method( 'isRevealed' )->willReturn( false );
		$this->results->method( 'studentPerTask' )->willReturn( array( array( 'n' => 1 ) ) );

		self::assertSame( array( array( 'n' => 1 ) ), $this->service->buildReview( $this->assessment(), 5, 51 )->resultPerTask );
	}

	/* ── Страница станции экзамена: ?exam_reg=ID ── */

	private function examContext(): AttemptContext {
		return new AttemptContext( ExamAudience::Student, 4, 6, 11, 102 );
	}

	private function examAttempt( string $status ): AttemptDTO {
		return AttemptDTO::fromArray( array(
			'id' => 5, 'assessment_id' => 9, 'student_person_id' => 11, 'group_id' => null,
			'attempt_number' => 1, 'status' => $status, 'started_at' => '2026-10-01 09:00:00',
			'deadline_at' => '2026-10-01 12:55:00', 'exam_participation_id' => 4, 'exam_registration_id' => 6,
		) );
	}

	public function test_exam_page_for_foreign_registration_is_refused(): void {
		$this->examAttempts->method( 'contextForStudent' )->willThrowException( new CodedException( ErrorCode::ExamAccess, 'Экзамен недоступен.' ) );

		self::assertNull( $this->service->buildForExam( $this->assessment(), 102, 6 ) );
	}

	public function test_exam_page_for_variant_of_another_session_is_refused(): void {
		$this->examAttempts->method( 'contextForStudent' )->willReturn( $this->examContext() );
		$this->examAttempts->method( 'stationState' )->willReturn( array( 'assessment_id' => 123, 'attempt' => null ) );
		$this->persons->method( 'find' )->willReturn( $this->person( 11 ) );

		self::assertNull( $this->service->buildForExam( $this->assessment(), 102, 6 ) );
	}

	public function test_exam_page_for_closed_registration_without_attempt_is_refused(): void {
		$this->examAttempts->method( 'contextForStudent' )->willReturn( $this->examContext() );
		$this->examAttempts->method( 'stationState' )->willReturn( null );
		$this->persons->method( 'find' )->willReturn( $this->person( 11 ) );

		self::assertNull( $this->service->buildForExam( $this->assessment(), 102, 6 ) );
	}

	public function test_exam_page_before_start_has_no_attempt_and_no_retry(): void {
		$this->examAttempts->method( 'contextForStudent' )->willReturn( $this->examContext() );
		$this->examAttempts->method( 'stationState' )->willReturn( array( 'assessment_id' => 9, 'attempt' => null ) );
		$this->persons->method( 'find' )->willReturn( $this->person( 11 ) );

		$page = $this->service->buildForExam( $this->assessment(), 102, 6 );

		self::assertNotNull( $page );
		self::assertNull( $page->activeAttempt );
		self::assertNull( $page->lastAttempt );
		self::assertFalse( $page->canRetry );
	}

	public function test_exam_page_with_running_attempt_exposes_it_as_active(): void {
		$this->examAttempts->method( 'contextForStudent' )->willReturn( $this->examContext() );
		$this->examAttempts->method( 'stationState' )->willReturn( array( 'assessment_id' => 9, 'attempt' => $this->examAttempt( 'in_progress' ) ) );
		$this->persons->method( 'find' )->willReturn( $this->person( 11 ) );

		$page = $this->service->buildForExam( $this->assessment(), 102, 6 );

		self::assertSame( 5, $page->activeAttempt->id );
		self::assertNull( $page->lastAttempt );
		self::assertTrue( $page->examInProgress );
	}

	public function test_exam_page_of_unapproved_attempt_has_no_scores(): void {
		$this->examAttempts->method( 'contextForStudent' )->willReturn( $this->examContext() );
		$this->examAttempts->method( 'stationState' )->willReturn( array( 'assessment_id' => 9, 'attempt' => $this->examAttempt( 'submitted' ) ) );
		$this->persons->method( 'find' )->willReturn( $this->person( 11 ) );
		$this->attemptService->method( 'isRevealed' )->willReturn( false );
		$this->results->expects( self::never() )->method( 'studentPerTask' );

		$page = $this->service->buildForExam( $this->assessment(), 102, 6 );

		self::assertSame( 5, $page->lastAttempt->id );
		self::assertSame( array(), $page->resultPerTask );
		self::assertSame( '', $page->outcome );
		self::assertFalse( $page->reviewReveal );
	}
}
