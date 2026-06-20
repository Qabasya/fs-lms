<?php

declare( strict_types=1 );

namespace Unit\Services\Course;

use Inc\Contracts\ClockInterface;
use Inc\DTO\Assessment\AttemptDTO;
use Inc\DTO\Course\GroupLessonDTO;
use Inc\DTO\Course\LessonDTO;
use Inc\DTO\Course\LessonProgressDTO;
use Inc\DTO\Course\SubmissionDTO;
use Inc\Enums\Course\ProgressStatus;
use Inc\Managers\Course\LessonManager;
use Inc\Repositories\WPDBRepositories\AssessmentAttemptRepository;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\LessonProgressRepository;
use Inc\Repositories\WPDBRepositories\SubmissionRepository;
use Inc\Services\Course\LessonProgressService;
use PHPUnit\Framework\TestCase;

class LessonProgressServiceTest extends TestCase {

	private LessonProgressRepository    $progress;
	private GroupLessonRepository       $groupLessons;
	private LessonManager               $lessons;
	private SubmissionRepository        $submissions;
	private AssessmentAttemptRepository $attempts;
	private ClockInterface              $clock;
	private LessonProgressService       $service;

	protected function setUp(): void {
		parent::setUp();
		$this->progress     = $this->createMock( LessonProgressRepository::class );
		$this->groupLessons = $this->createMock( GroupLessonRepository::class );
		$this->lessons      = $this->createMock( LessonManager::class );
		$this->submissions  = $this->createMock( SubmissionRepository::class );
		$this->attempts     = $this->createMock( AssessmentAttemptRepository::class );
		$this->clock        = $this->createMock( ClockInterface::class );
		$this->service      = new LessonProgressService(
			$this->progress, $this->groupLessons, $this->lessons, $this->submissions, $this->attempts, $this->clock
		);
	}

	private function groupLesson( int $lessonId = 7 ): GroupLessonDTO {
		return GroupLessonDTO::fromArray( array( 'id' => 3, 'group_id' => 1, 'lesson_id' => $lessonId, 'position' => 0 ) );
	}

	private function lessonWith( array $steps ): LessonDTO {
		return LessonDTO::fromArray( array( 'id' => 7, 'subject_key' => 'inf', 'topic' => 'L', 'steps' => $steps, 'author_id' => 0, 'status' => 'publish' ) );
	}

	private function step( string $key, string $type, array $payload = array() ): array {
		return array( 'key' => $key, 'type' => $type, 'payload' => $payload );
	}

	private function submission( int $workId, string $status ): SubmissionDTO {
		return SubmissionDTO::fromArray( array(
			'id' => 1, 'student_person_id' => 9, 'group_lesson_id' => 3, 'work_id' => $workId, 'status' => $status,
		) );
	}

	private function attempt( int $assessmentId, string $status ): AttemptDTO {
		return AttemptDTO::fromArray( array(
			'id' => 1, 'assessment_id' => $assessmentId, 'student_person_id' => 9, 'attempt_number' => 1,
			'started_at' => '2024-01-01 00:00:00', 'deadline_at' => '2024-01-01 01:00:00', 'status' => $status,
		) );
	}

	private function progressRow( string $stepKey, ProgressStatus $status ): LessonProgressDTO {
		return LessonProgressDTO::fromArray( array(
			'id' => 1, 'student_person_id' => 9, 'group_lesson_id' => 3, 'lesson_id' => 7,
			'step_key' => $stepKey, 'status' => $status->value, 'completed_at' => null, 'created_at' => '', 'updated_at' => '',
		) );
	}

	public function test_mark_viewed_upserts_with_resolved_lesson_id(): void {
		$this->groupLessons->method( 'find' )->willReturn( $this->groupLesson( 7 ) );
		$this->progress->expects( $this->once() )
			->method( 'upsert' )
			->with( 9, 3, 7, 's_a', ProgressStatus::Viewed, null );

		$this->service->markViewed( 9, 3, 's_a' );
	}

	public function test_mark_completed_uses_clock_timestamp(): void {
		$this->groupLessons->method( 'find' )->willReturn( $this->groupLesson( 7 ) );
		$this->clock->method( 'now' )->willReturn( '2024-02-02 12:00:00' );
		$this->progress->expects( $this->once() )
			->method( 'upsert' )
			->with( 9, 3, 7, 's_a', ProgressStatus::Completed, '2024-02-02 12:00:00' );

		$this->service->markCompleted( 9, 3, 's_a' );
	}

	public function test_mark_noops_when_group_lesson_missing(): void {
		$this->groupLessons->method( 'find' )->willReturn( null );
		$this->progress->expects( $this->never() )->method( 'upsert' );

		$this->service->markViewed( 9, 3, 's_a' );
	}

	public function test_get_step_statuses_resolves_inline_work_and_assessment(): void {
		$this->groupLessons->method( 'find' )->willReturn( $this->groupLesson( 7 ) );
		$this->lessons->method( 'get' )->willReturn( $this->lessonWith( array(
			$this->step( 's_t', 'text' ),
			$this->step( 's_w', 'work', array( 'ref' => 20 ) ),
			$this->step( 's_a', 'assessment', array( 'ref' => 30 ) ),
		) ) );
		$this->progress->method( 'listForStudent' )->willReturn( array( $this->progressRow( 's_t', ProgressStatus::Viewed ) ) );
		$this->submissions->method( 'listByStudentAndGroupLesson' )->willReturn( array( $this->submission( 20, 'submitted' ) ) );
		$this->attempts->method( 'listByStudentAndAssessment' )->willReturn( array() );

		$statuses = $this->service->getStepStatuses( 9, 3 );

		self::assertSame( ProgressStatus::Viewed, $statuses['s_t'] );       // инлайн — из таблицы прогресса
		self::assertSame( ProgressStatus::Completed, $statuses['s_w'] );    // work — из сдачи
		self::assertSame( ProgressStatus::Available, $statuses['s_a'] );    // assessment — нет попытки
	}

	public function test_work_completion_comes_only_from_facts_not_stored_status(): void {
		$this->groupLessons->method( 'find' )->willReturn( $this->groupLesson( 7 ) );
		$this->lessons->method( 'get' )->willReturn( $this->lessonWith( array(
			$this->step( 's_w', 'work', array( 'ref' => 20 ) ),
		) ) );
		// В таблице ошибочно лежит Completed, но сдачи нет → шаг НЕ должен считаться пройденным.
		$this->progress->method( 'listForStudent' )->willReturn( array( $this->progressRow( 's_w', ProgressStatus::Completed ) ) );
		$this->submissions->method( 'listByStudentAndGroupLesson' )->willReturn( array() );

		$statuses = $this->service->getStepStatuses( 9, 3 );

		self::assertSame( ProgressStatus::Available, $statuses['s_w'] );
	}

	public function test_assessment_completed_from_submitted_attempt(): void {
		$this->groupLessons->method( 'find' )->willReturn( $this->groupLesson( 7 ) );
		$this->lessons->method( 'get' )->willReturn( $this->lessonWith( array(
			$this->step( 's_a', 'assessment', array( 'ref' => 30 ) ),
		) ) );
		$this->progress->method( 'listForStudent' )->willReturn( array() );
		$this->submissions->method( 'listByStudentAndGroupLesson' )->willReturn( array() );
		$this->attempts->method( 'listByStudentAndAssessment' )->with( 9, 30 )->willReturn( array( $this->attempt( 30, 'submitted' ) ) );

		$statuses = $this->service->getStepStatuses( 9, 3 );

		self::assertSame( ProgressStatus::Completed, $statuses['s_a'] );
	}

	public function test_is_lesson_completed_true_when_all_steps_complete(): void {
		$this->groupLessons->method( 'find' )->willReturn( $this->groupLesson( 7 ) );
		$this->lessons->method( 'get' )->willReturn( $this->lessonWith( array(
			$this->step( 's_t', 'text' ),
			$this->step( 's_w', 'work', array( 'ref' => 20 ) ),
		) ) );
		$this->progress->method( 'listForStudent' )->willReturn( array( $this->progressRow( 's_t', ProgressStatus::Completed ) ) );
		$this->submissions->method( 'listByStudentAndGroupLesson' )->willReturn( array( $this->submission( 20, 'graded' ) ) );

		self::assertTrue( $this->service->isLessonCompleted( 9, 3 ) );
	}

	public function test_is_lesson_completed_false_when_a_step_incomplete(): void {
		$this->groupLessons->method( 'find' )->willReturn( $this->groupLesson( 7 ) );
		$this->lessons->method( 'get' )->willReturn( $this->lessonWith( array(
			$this->step( 's_t', 'text' ),
			$this->step( 's_w', 'work', array( 'ref' => 20 ) ),
		) ) );
		$this->progress->method( 'listForStudent' )->willReturn( array( $this->progressRow( 's_t', ProgressStatus::Viewed ) ) );
		$this->submissions->method( 'listByStudentAndGroupLesson' )->willReturn( array( $this->submission( 20, 'graded' ) ) );

		self::assertFalse( $this->service->isLessonCompleted( 9, 3 ) );
	}

	public function test_is_lesson_completed_false_for_lesson_without_steps(): void {
		$this->groupLessons->method( 'find' )->willReturn( $this->groupLesson( 7 ) );
		$this->lessons->method( 'get' )->willReturn( $this->lessonWith( array() ) );
		$this->progress->method( 'listForStudent' )->willReturn( array() );
		$this->submissions->method( 'listByStudentAndGroupLesson' )->willReturn( array() );

		self::assertFalse( $this->service->isLessonCompleted( 9, 3 ) );
	}
}
