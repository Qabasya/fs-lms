<?php

declare( strict_types=1 );

namespace Unit\Services\Profile;

use Inc\DTO\Course\GroupLessonDTO;
use Inc\DTO\Course\LessonDTO;
use Inc\DTO\Course\StepDTO;
use Inc\DTO\Course\SubmissionDTO;
use Inc\DTO\Course\WorkDTO;
use Inc\DTO\Profile\LearnerContextDTO;
use Inc\Enums\Course\StepType;
use Inc\Enums\Course\WorkType;
use Inc\Managers\Course\LessonManager;
use Inc\Repositories\WPDBRepositories\SubmissionRepository;
use Inc\Services\Course\EffectiveWorksResolver;
use Inc\Services\Course\HomeworkDeadlineService;
use Inc\Services\Course\LessonWorkLink;
use Inc\Services\Profile\Learner\LearnerScheduleSection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class LearnerHomeworkTest extends TestCase {
	private function row( int $id, array $extra = array() ): GroupLessonDTO {
		return GroupLessonDTO::fromArray( $extra + array(
			'id' => $id, 'position' => 1, 'group_id' => 1, 'lesson_id' => $id, 'scheduled_at' => '2026-11-10 10:00:00',
			'ends_at' => '2026-11-10 11:00:00', 'visibility' => 'open',
		) );
	}

	private function work( int $id = 501, int $limit = 0, WorkType $type = WorkType::Homework ): WorkDTO {
		return new WorkDTO( $id, 'math', 'ДЗ', $type, array( 101, 102 ), '', 1, 'publish', $limit );
	}

	private function submission( array $verdicts, int $attempts = 1, ?int $teacher = null ): SubmissionDTO {
		return SubmissionDTO::fromArray( array(
			'id' => 1, 'student_person_id' => 9001, 'group_lesson_id' => 10, 'work_id' => 501,
			'work_type' => 'homework', 'status' => 'graded', 'answer_text' => json_encode( array_map( static fn( string $v ): array => array( 'verdict' => $v ), $verdicts ) ),
			'attempt_count' => $attempts, 'graded_by_user_id' => $teacher,
		) );
	}

	private function select( array $rows, array $works, array $facts = array(), array $cards = array(), array $missingSteps = array(), int $historyAttempts = 0 ): array {
		$repo = $this->createMock( SubmissionRepository::class );
		$ids = array_values( array_unique( array_map( static fn( GroupLessonDTO $r ): int => $r->progressId(), $rows ) ) );
		$repo->expects( self::never() )->method( 'findAggregate' );
		$repo->expects( self::never() )->method( 'listPerTaskByStudentWorkLesson' );
		$repo->method( 'listByGroupLessons' )->willReturnCallback( static function ( array $requested, int $person ) use ( $facts, $ids ): array {
			self::assertSame( 9001, $person );
			self::assertSame( array_values( array_unique( $requested ) ), $requested );
			self::assertSame( array(), array_diff( $requested, $ids ) );
			self::assertNotEmpty( $requested );
			return $facts;
		} );
		$resolver = $this->createStub( EffectiveWorksResolver::class );
		$resolver->method( 'resolve' )->willReturnCallback( static fn( GroupLessonDTO $r ): array => $works[ $r->id ] ?? array() );
		$lessons = $this->createStub( LessonManager::class );
		$lessons->method( 'get' )->willReturnCallback( static fn( int $id ): LessonDTO => new LessonDTO(
			$id, 'math', 'Тема', array_map( static fn( WorkDTO $w ): StepDTO => new StepDTO( 'work-' . $w->id, StepType::Work, array( 'ref' => $w->id ) ),
			array_values( array_filter( $works[ $id ] ?? array(), static fn( WorkDTO $w ): bool => ! in_array( $w->id, $missingSteps, true ) ) ) ), 1, 'publish'
		) );
		$map = array();
		foreach ( $rows as $row ) {
			$map[ $row->id ] = ( $cards[ $row->id ] ?? array() ) + array( 'accessible' => true, 'status' => 'available', 'player_url' => '/player/?gl=' . $row->id, 'topic' => 'Тема ' . $row->id );
		}
		$ctx = new LearnerContextDTO( array( 1 => array( 'subject' => 'Математика' ), 2 => array( 'subject' => 'Физика' ) ), $map, array_values( $map ), $rows, array(), '2026-11-12 12:00:00' );
		$program = $this->createStub( \Inc\Repositories\WPDBRepositories\GroupLessonRepository::class );
		$program->method( 'find' )->willReturnCallback( static function ( int $id ) use ( $rows ): ?GroupLessonDTO {
			foreach ( $rows as $row ) {
				if ( $row->id === $id ) { return $row; }
			}
			return null;
		} );
		$attempts = $this->createStub( \Inc\Repositories\WPDBRepositories\TaskAttemptRepository::class );
		$attempts->method( 'maxAttemptNumberByStep' )->willReturn( $historyAttempts );
		$deadline = $this->createStub( HomeworkDeadlineService::class );
		$submissions = new \Inc\Services\Course\SubmissionService(
			$repo, $program, $resolver,
			$this->createStub( \Inc\Managers\Course\WorkManager::class ),
			$this->createStub( \Inc\Managers\Wp\MediaManager::class ),
			$this->createStub( \Inc\Services\Course\LessonAccessPolicy::class ),
			$this->createStub( \Inc\Services\Course\BatchCheckService::class ),
			$this->createStub( \Inc\Contracts\LogEventDispatcherInterface::class ),
			$this->createStub( \Inc\Contracts\ClockInterface::class ), $attempts,
			$this->createStub( \Inc\Repositories\WPDBRepositories\PersonRepository::class ), $deadline,
			$this->createStub( \Inc\Repositories\WPDBRepositories\WorkTaskCheckRepository::class ),
			$this->createStub( \Inc\Services\Assessment\ExamLockService::class ),
		);
		return ( new LearnerScheduleSection( $repo, $resolver, new LessonWorkLink( $lessons ), $deadline, $submissions ) )->homework( $ctx, 9001 );
	}

	public static function states(): array {
		return array(
			'new' => array( null, 0, 'active' ),
			'wrong unlimited' => array( array( 101 => 'incorrect', 102 => 'correct' ), 0, 'active' ),
			'wrong limited' => array( array( 101 => 'incorrect', 102 => 'correct' ), 2, 'active' ),
			'limit reached' => array( array( 101 => 'incorrect', 102 => 'correct' ), 1, 'exhausted' ),
			'all correct at limit' => array( array( 101 => 'correct', 102 => 'correct' ), 1, 'completed' ),
			'pending' => array( array( 101 => 'pending', 102 => 'correct' ), 0, 'pending' ),
			'pending and wrong can retry' => array( array( 101 => 'pending', 102 => 'incorrect' ), 0, 'active' ),
			'pending and exhausted' => array( array( 101 => 'pending', 102 => 'incorrect' ), 1, 'pending' ),
			'unanswered task' => array( array( 101 => 'correct' ), 0, 'active' ),
		);
	}

	#[DataProvider( 'states' )]
	public function test_states_match_available_student_action( ?array $verdicts, int $limit, string $status ): void {
		$facts = null === $verdicts ? array() : array( 10 => array( $this->submission( $verdicts ) ) );
		$result = $this->select( array( $this->row( 10 ) ), array( 10 => array( $this->work( limit: $limit ) ) ), $facts );
		self::assertSame( $status, $result['status'] );
		if ( 'active' === $status ) {
			self::assertStringContainsString( 'step=work-501', $result['player_url'] );
			self::assertSame( 'Математика', $result['subject'] );
			self::assertGreaterThan( 0, $result['remaining'] );
		}
	}

	public function test_latest_lesson_across_groups_then_older_debts_and_first_unsolved_task(): void {
		$rows = array( $this->row( 10 ), $this->row( 20, array( 'group_id' => 2, 'scheduled_at' => '2026-11-10 15:00:00', 'ends_at' => '2026-11-10 16:00:00' ) ) );
		$works = array( 10 => array( $this->work() ), 20 => array( $this->work() ) );
		self::assertSame( 'Тема 20', $this->select( $rows, $works )['topic'] );
		$result = $this->select( $rows, $works, array( 20 => array( $this->submission( array( 101 => 'correct', 102 => 'correct' ) ) ), 10 => array( $this->submission( array( 101 => 'correct', 102 => 'incorrect' ) ) ) ) );
		self::assertSame( 'Тема 10', $result['topic'] );
		self::assertSame( 1, $result['remaining'] );
		self::assertStringContainsString( 'task=102', $result['player_url'] );
	}

	public function test_actionable_debt_wins_over_new_pending_and_exhausted_works(): void {
		$rows = array( $this->row( 10 ), $this->row( 20, array( 'scheduled_at' => '2026-11-11 10:00:00' ) ) );
		$works = array( 10 => array( $this->work() ), 20 => array( $this->work( limit: 1 ) ) );
		foreach ( array( 'pending', 'incorrect' ) as $verdict ) {
			$result = $this->select( $rows, $works, array( 20 => array( $this->submission( array( 101 => $verdict, 102 => 'correct' ) ) ) ) );
			self::assertSame( 'active', $result['status'] );
			self::assertSame( 'Тема 10', $result['topic'] );
		}
	}

	public function test_manual_credit_is_completed_without_verdict_snapshot(): void {
		self::assertSame( 'completed', $this->select( array( $this->row( 10 ) ), array( 10 => array( $this->work() ) ), array( 10 => array( $this->submission( array(), 0, 44 ) ) ) )['status'] );
	}

	public static function excludedRows(): array {
		return array(
			'cancelled' => array( array( 'status' => 'cancelled' ), array() ),
			'moved' => array( array( 'status' => 'moved' ), array() ),
			'future' => array( array( 'scheduled_at' => '2026-11-13 10:00:00', 'ends_at' => '2026-11-13 11:00:00' ), array() ),
			'ongoing' => array( array( 'scheduled_at' => '2026-11-12 11:30:00', 'ends_at' => '2026-11-12 12:30:00' ), array() ),
			'no end ongoing' => array( array( 'scheduled_at' => '2026-11-12 11:30:00', 'ends_at' => null ), array() ),
			'no start' => array( array( 'scheduled_at' => null ), array() ),
			'access revoked even if completed' => array( array(), array( 'accessible' => false, 'status' => 'done' ) ),
			'no player' => array( array(), array( 'player_url' => '' ) ),
		);
	}

	#[DataProvider( 'excludedRows' )]
	public function test_does_not_assign_unavailable_or_unfinished_lesson( array $row, array $card ): void {
		self::assertSame( array( 'status' => 'empty' ), $this->select( array( $this->row( 10, $row ) ), array( 10 => array( $this->work() ) ), cards: array( 10 => $card ) ) );
	}

	public function test_continuations_share_progress_and_use_latest_occurrence_link(): void {
		$rows = array( $this->row( 10 ), $this->row( 20, array( 'continued_from_id' => 10, 'scheduled_at' => '2026-11-11 10:00:00' ) ) );
		$result = $this->select( $rows, array( 10 => array( $this->work() ), 20 => array( $this->work() ) ), array( 10 => array( $this->submission( array( 101 => 'correct', 102 => 'incorrect' ) ) ) ) );
		self::assertSame( 'Тема 20', $result['topic'] );
		self::assertSame( 1, $result['remaining'] );
		self::assertStringContainsString( 'gl=20', $result['player_url'] );
	}

	public function test_multiple_works_skip_other_types_and_finished_homework(): void {
		$works = array( $this->work( 500, type: WorkType::Practice ), $this->work(), $this->work( 502 ) );
		$result = $this->select( array( $this->row( 10 ) ), array( 10 => $works ), array( 10 => array( $this->submission( array( 101 => 'correct', 102 => 'correct' ) ) ) ) );
		self::assertStringContainsString( 'step=work-502', $result['player_url'] );
	}

	public function test_snapshot_or_extra_without_player_step_does_not_link_to_unrelated_step(): void {
		$result = $this->select( array( $this->row( 10 ) ), array( 10 => array( $this->work(), $this->work( 502 ) ) ), missingSteps: array( 501 ) );
		self::assertStringContainsString( 'step=work-502', $result['player_url'] );
	}

	public function test_past_deadline_does_not_remove_homework_and_empty_context_is_empty(): void {
		self::assertSame( 'active', $this->select( array( $this->row( 10, array( 'homework_due_at' => '2026-11-11 10:00:00' ) ) ), array( 10 => array( $this->work() ) ) )['status'] );
		self::assertSame( array( 'status' => 'empty' ), $this->select( array(), array() ) );
	}
	public function test_legacy_attempt_history_prevents_unavailable_retry_even_without_aggregate(): void {
		self::assertSame( 'exhausted', $this->select( array( $this->row( 10 ) ), array( 10 => array( $this->work( limit: 2 ) ) ), historyAttempts: 2 )['status'] );
	}

	public function test_teacher_graded_wrong_task_is_locked_and_does_not_offer_impossible_retry(): void {
		$task = SubmissionDTO::fromArray( array(
			'id' => 2, 'student_person_id' => 9001, 'group_lesson_id' => 10, 'work_id' => 501,
			'task_id' => 102, 'status' => 'graded', 'graded_by_user_id' => 44,
		) );
		$facts = array( 10 => array( $this->submission( array( 101 => 'correct', 102 => 'incorrect' ) ), $task ) );
		self::assertSame( 'exhausted', $this->select( array( $this->row( 10 ) ), array( 10 => array( $this->work() ) ), $facts )['status'] );
	}

	public function test_missing_end_uses_one_hour_schedule_fallback(): void {
		self::assertSame( 'active', $this->select( array( $this->row( 10, array( 'ends_at' => null ) ) ), array( 10 => array( $this->work() ) ) )['status'] );
	}

	public function test_completed_is_only_returned_when_every_work_is_successful(): void {
		$rows = array( $this->row( 10 ), $this->row( 20 ) );
		$works = array( 10 => array( $this->work( limit: 1 ) ), 20 => array( $this->work( limit: 1 ) ) );
		$correct = $this->submission( array( 101 => 'correct', 102 => 'correct' ) );
		$wrong = $this->submission( array( 101 => 'incorrect', 102 => 'correct' ) );
		$pending = $this->submission( array( 101 => 'pending', 102 => 'correct' ) );
		self::assertSame( 'completed', $this->select( $rows, $works, array( 10 => array( $correct ), 20 => array( $correct ) ) )['status'] );
		self::assertSame( 'exhausted', $this->select( $rows, $works, array( 10 => array( $wrong ), 20 => array( $correct ) ) )['status'] );
		self::assertSame( 'pending', $this->select( $rows, $works, array( 10 => array( $wrong ), 20 => array( $pending ) ) )['status'] );
	}

	public function test_equal_start_time_is_stable_and_assignment_order_is_kept(): void {
		$rows = array( $this->row( 10 ), $this->row( 20 ) );
		$works = array( 10 => array( $this->work() ), 20 => array( $this->work( 502 ), $this->work() ) );
		$result = $this->select( $rows, $works );
		self::assertSame( 'Тема 20', $result['topic'] );
		self::assertStringContainsString( 'step=work-502', $result['player_url'] );
	}

	public function test_early_recording_or_held_mark_does_not_assign_homework_during_lesson(): void {
		$row = $this->row( 10, array( 'status' => 'held', 'recording_url' => 's3://recording', 'scheduled_at' => '2026-11-12 11:30:00', 'ends_at' => null ) );
		self::assertSame( 'empty', $this->select( array( $row ), array( 10 => array( $this->work() ) ) )['status'] );
	}

}
