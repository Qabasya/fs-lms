<?php

declare( strict_types=1 );

namespace Unit\Services\Course;

use Inc\Contracts\ClockInterface;
use Inc\DTO\Course\GroupLessonDTO;
use Inc\DTO\Course\LessonDTO;
use Inc\Enums\GateState;
use Inc\Enums\ProgressStatus;
use Inc\Managers\LessonManager;
use Inc\Services\Course\LessonAccessPolicy;
use Inc\Services\Course\LessonGateResolver;
use Inc\Services\Course\LessonProgressService;
use PHPUnit\Framework\TestCase;

class LessonGateResolverTest extends TestCase {

	private LessonProgressService $progress;
	private LessonManager         $lessons;
	private LessonAccessPolicy    $access;
	private ClockInterface        $clock;
	private LessonGateResolver    $resolver;

	protected function setUp(): void {
		parent::setUp();
		$this->progress = $this->createMock( LessonProgressService::class );
		$this->lessons  = $this->createMock( LessonManager::class );
		$this->access   = $this->createMock( LessonAccessPolicy::class );
		$this->clock    = $this->createMock( ClockInterface::class );
		$this->resolver = new LessonGateResolver( $this->progress, $this->lessons, $this->access, $this->clock );
		$this->clock->method( 'now' )->willReturn( '2024-06-01 00:00:00' );
	}

	private function groupLesson( ?string $scheduledAt = null ): GroupLessonDTO {
		return GroupLessonDTO::fromArray( array(
			'id' => 3, 'group_id' => 1, 'lesson_id' => 7, 'position' => 0, 'scheduled_at' => $scheduledAt,
		) );
	}

	private function step( string $key, ?string $gate = null ): array {
		return array( 'key' => $key, 'type' => 'text', 'payload' => null === $gate ? array() : array( 'gate' => $gate ) );
	}

	private function lessonWith( array $steps ): LessonDTO {
		return LessonDTO::fromArray( array( 'id' => 7, 'subject_key' => 'inf', 'topic' => 'L', 'steps' => $steps, 'author_id' => 0, 'status' => 'publish' ) );
	}

	// ── lesson-level ──

	public function test_lesson_locked_when_no_read_access(): void {
		$this->access->method( 'canRead' )->willReturn( false );

		self::assertSame( GateState::Locked, $this->resolver->resolveLesson( 9, $this->groupLesson() ) );
	}

	public function test_lesson_locked_when_scheduled_in_future(): void {
		$this->access->method( 'canRead' )->willReturn( true );

		self::assertSame( GateState::Locked, $this->resolver->resolveLesson( 9, $this->groupLesson( '2024-12-31 00:00:00' ) ) );
	}

	public function test_lesson_available_when_access_and_no_date(): void {
		$this->access->method( 'canRead' )->willReturn( true );

		self::assertSame( GateState::Available, $this->resolver->resolveLesson( 9, $this->groupLesson() ) );
	}

	public function test_lesson_available_when_scheduled_in_past(): void {
		$this->access->method( 'canRead' )->willReturn( true );

		self::assertSame( GateState::Available, $this->resolver->resolveLesson( 9, $this->groupLesson( '2024-01-01 00:00:00' ) ) );
	}

	// ── step-level ──

	public function test_step_locked_when_lesson_locked(): void {
		$this->access->method( 'canRead' )->willReturn( false );

		self::assertSame( GateState::Locked, $this->resolver->resolveStep( 9, $this->groupLesson(), 's_a' ) );
	}

	public function test_step_none_gate_is_available(): void {
		$this->access->method( 'canRead' )->willReturn( true );
		$this->lessons->method( 'get' )->willReturn( $this->lessonWith( array( $this->step( 's_a' ) ) ) );
		$this->progress->method( 'getStepStatuses' )->willReturn( array() );

		self::assertSame( GateState::Available, $this->resolver->resolveStep( 9, $this->groupLesson(), 's_a' ) );
	}

	public function test_step_sequential_first_step_available(): void {
		$this->access->method( 'canRead' )->willReturn( true );
		$this->lessons->method( 'get' )->willReturn( $this->lessonWith( array( $this->step( 's_a', 'sequential' ) ) ) );
		$this->progress->method( 'getStepStatuses' )->willReturn( array() );

		self::assertSame( GateState::Available, $this->resolver->resolveStep( 9, $this->groupLesson(), 's_a' ) );
	}

	public function test_step_sequential_locked_when_previous_incomplete(): void {
		$this->access->method( 'canRead' )->willReturn( true );
		$this->lessons->method( 'get' )->willReturn( $this->lessonWith( array( $this->step( 's_a' ), $this->step( 's_b', 'sequential' ) ) ) );
		$this->progress->method( 'getStepStatuses' )->willReturn( array( 's_a' => ProgressStatus::Viewed ) );

		self::assertSame( GateState::Locked, $this->resolver->resolveStep( 9, $this->groupLesson(), 's_b' ) );
	}

	public function test_step_sequential_available_when_previous_complete(): void {
		$this->access->method( 'canRead' )->willReturn( true );
		$this->lessons->method( 'get' )->willReturn( $this->lessonWith( array( $this->step( 's_a' ), $this->step( 's_b', 'sequential' ) ) ) );
		$this->progress->method( 'getStepStatuses' )->willReturn( array( 's_a' => ProgressStatus::Completed ) );

		self::assertSame( GateState::Available, $this->resolver->resolveStep( 9, $this->groupLesson(), 's_b' ) );
	}

	public function test_step_after_gate_checks_named_step(): void {
		$this->access->method( 'canRead' )->willReturn( true );
		$this->lessons->method( 'get' )->willReturn( $this->lessonWith( array( $this->step( 's_a' ), $this->step( 's_b' ), $this->step( 's_c', 'after:s_a' ) ) ) );
		$this->progress->method( 'getStepStatuses' )->willReturn( array( 's_a' => ProgressStatus::Completed ) );

		self::assertSame( GateState::Available, $this->resolver->resolveStep( 9, $this->groupLesson(), 's_c' ) );
	}

	public function test_step_locked_for_unknown_step(): void {
		$this->access->method( 'canRead' )->willReturn( true );
		$this->lessons->method( 'get' )->willReturn( $this->lessonWith( array( $this->step( 's_a' ) ) ) );
		$this->progress->method( 'getStepStatuses' )->willReturn( array() );

		self::assertSame( GateState::Locked, $this->resolver->resolveStep( 9, $this->groupLesson(), 's_missing' ) );
	}
}
