<?php

declare( strict_types=1 );

namespace Unit\Services\Course;

use Inc\DTO\Assessment\AttemptDTO;
use Inc\Managers\Assessment\AssessmentManager;
use Inc\Managers\Course\WorkManager;
use Inc\Repositories\WPDBRepositories\AssessmentAnswerRepository;
use Inc\Repositories\WPDBRepositories\AssessmentAttemptRepository;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\SubmissionRepository;
use Inc\Repositories\WPDBRepositories\TaskAttemptRepository;
use Inc\Repositories\WPDBRepositories\WorkTaskCheckRepository;
use Inc\Services\Course\WorkResetService;
use Inc\Services\Profile\NotificationService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * «Сбросить попытки» преподавателя удаляет попытки курса и никогда не трогает официальные попытки экзамена:
 * их жизнь определяют запись, дедлайн и утверждение, а не ручной сброс.
 */
#[AllowMockObjectsWithoutExpectations]
class WorkResetServiceTest extends TestCase {

	private AssessmentAttemptRepository&MockObject $attempts;
	private AssessmentAnswerRepository&MockObject $answers;
	private NotificationService&MockObject $notifications;
	private WorkResetService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->attempts      = $this->createMock( AssessmentAttemptRepository::class );
		$this->answers       = $this->createMock( AssessmentAnswerRepository::class );
		$this->notifications = $this->createMock( NotificationService::class );

		$this->service = new WorkResetService(
			$this->createMock( SubmissionRepository::class ),
			$this->attempts,
			$this->answers,
			$this->createMock( GroupLessonRepository::class ),
			$this->createMock( TaskAttemptRepository::class ),
			$this->createMock( WorkTaskCheckRepository::class ),
			$this->notifications,
			$this->createMock( AssessmentManager::class ),
			$this->createMock( WorkManager::class ),
		);
	}

	/** @param array<string, mixed> $override */
	private function attempt( int $id, array $override = array() ): AttemptDTO {
		return AttemptDTO::fromArray( array_merge( array(
			'id' => $id, 'assessment_id' => 3, 'student_person_id' => 10, 'group_id' => 5, 'attempt_number' => $id,
			'status' => 'graded', 'started_at' => '2026-01-01 00:00:00', 'deadline_at' => '2026-01-01 01:00:00',
		), $override ) );
	}

	/** @return array<string, mixed> */
	private function examOverride(): array {
		return array( 'group_id' => null, 'exam_participation_id' => 7, 'exam_registration_id' => 6 );
	}

	public function test_reset_does_not_delete_exam_attempts(): void {
		$this->attempts->method( 'find' )->willReturn( $this->attempt( 9, $this->examOverride() ) );
		$this->attempts->expects( self::never() )->method( 'listByStudentAndAssessment' );
		$this->attempts->expects( self::never() )->method( 'delete' );
		$this->answers->expects( self::never() )->method( 'deleteByAttempt' );
		$this->notifications->expects( self::never() )->method( 'push' );

		self::assertSame( 0, $this->service->reset( 'attempt', 9 ), 'Экзаменная попытка пропущена: удалено 0.' );
	}

	public function test_reset_skips_exam_attempt_even_if_it_slips_into_the_course_list(): void {
		// Страховка на случай, если выборка когда-нибудь вернёт экзаменную попытку вместе с курсовыми.
		$this->attempts->method( 'find' )->willReturn( $this->attempt( 1 ) );
		$this->attempts->method( 'listByStudentAndAssessment' )->willReturn( array( $this->attempt( 1 ), $this->attempt( 9, $this->examOverride() ) ) );
		$this->attempts->expects( self::once() )->method( 'delete' )->with( 1 )->willReturn( true );
		$this->answers->expects( self::once() )->method( 'deleteByAttempt' )->with( 1 );

		self::assertSame( 1, $this->service->reset( 'attempt', 1 ) );
	}

	public function test_reset_of_course_attempt_deletes_all_course_attempts_of_the_student(): void {
		$this->attempts->method( 'find' )->willReturn( $this->attempt( 1 ) );
		$this->attempts->method( 'listByStudentAndAssessment' )->willReturn( array( $this->attempt( 1 ), $this->attempt( 2 ) ) );
		$this->attempts->method( 'delete' )->willReturn( true );

		self::assertSame( 2, $this->service->reset( 'attempt', 1 ) );
	}

	public function test_unknown_attempt_and_unknown_type_are_reported_as_minus_one(): void {
		$this->attempts->method( 'find' )->willReturn( null );

		self::assertSame( -1, $this->service->reset( 'attempt', 404 ) );
		self::assertSame( -1, $this->service->reset( 'nonsense', 1 ) );
	}
}
