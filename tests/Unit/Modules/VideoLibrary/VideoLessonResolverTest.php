<?php

declare( strict_types=1 );

namespace Unit\Modules\VideoLibrary;

use Inc\DTO\Course\GroupLessonDTO;
use Inc\Modules\VideoLibrary\Services\VideoLessonResolver;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use PHPUnit\Framework\TestCase;

class VideoLessonResolverTest extends TestCase {

	private GroupLessonRepository $lessons;
	private VideoLessonResolver $resolver;

	protected function setUp(): void {
		parent::setUp();
		$this->lessons  = $this->createMock( GroupLessonRepository::class );
		$this->resolver = new VideoLessonResolver( $this->lessons );
	}

	private function lesson( int $id, ?string $scheduledAt, array $over = array() ): GroupLessonDTO {
		return new GroupLessonDTO(
			id              : $id,
			groupId         : $over['group_id'] ?? 3,
			lessonId        : 10,
			position        : 0,
			workIdsSnapshot : null,
			extraWorkIds    : array(),
			scheduledAt     : $scheduledAt,
			endsAt          : $over['ends_at'] ?? null,
			isPinned        : false,
			teacherUserId   : $over['teacher_user_id'] ?? null,
			visibility      : 'open',
			openedAt        : null,
			homeworkDueAt   : null,
			allowLate       : true,
			recordingUrl    : null,
			createdByUserId : null,
			updatedByUserId : null,
			kind            : $over['kind'] ?? 'group',
			status          : $over['status'] ?? 'scheduled',
		);
	}

	private function at( string $datetime ): \DateTimeImmutable {
		return new \DateTimeImmutable( $datetime, new \DateTimeZone( 'Europe/Kaliningrad' ) );
	}

	// ── Ветка A: group_id ────────────────────────────────────────────────────

	public function test_group_branch_matches_lesson_in_window(): void {
		$this->lessons->method( 'listByGroupAndDay' )->with( 3, '2026-07-08' )
			->willReturn( array( $this->lesson( 101, '2026-07-08 16:00:00', array( 'ends_at' => '2026-07-08 17:30:00' ) ) ) );

		$result = $this->resolver->resolve( $this->at( '2026-07-08 16:04:45' ), 3, null );

		self::assertSame( 101, $result['group_lesson_id'] );
		self::assertSame( 'matched', $result['reason'] );
	}

	public function test_recording_before_window_start_is_unmatched(): void {
		// Запись за 50 минут до начала — вне окна [−45 мин; +45 мин].
		$this->lessons->method( 'listByGroupAndDay' )
			->willReturn( array( $this->lesson( 101, '2026-07-08 16:00:00', array( 'ends_at' => '2026-07-08 17:30:00' ) ) ) );

		$result = $this->resolver->resolve( $this->at( '2026-07-08 15:10:00' ), 3, null );

		self::assertNull( $result['group_lesson_id'] );
		self::assertSame( 'no_candidates', $result['reason'] );
	}

	public function test_null_ends_at_falls_back_to_three_hours(): void {
		// ends_at NULL → окно до scheduled_at + 3ч + 45 мин = 19:45.
		$this->lessons->method( 'listByGroupAndDay' )
			->willReturn( array( $this->lesson( 101, '2026-07-08 16:00:00' ) ) );

		self::assertSame( 'matched', $this->resolver->resolve( $this->at( '2026-07-08 19:40:00' ), 3, null )['reason'] );
		self::assertSame( 'no_candidates', $this->resolver->resolve( $this->at( '2026-07-08 19:50:00' ), 3, null )['reason'] );
	}

	public function test_two_lessons_same_day_nearest_wins(): void {
		$this->lessons->method( 'listByGroupAndDay' )->willReturn( array(
			$this->lesson( 101, '2026-07-08 10:00:00', array( 'ends_at' => '2026-07-08 11:30:00' ) ),
			$this->lesson( 102, '2026-07-08 12:00:00', array( 'ends_at' => '2026-07-08 13:30:00' ) ),
		) );

		// 11:50 попадает в окно обоих (11:30+45 и 12:00−45); ближе к 12:00.
		$result = $this->resolver->resolve( $this->at( '2026-07-08 11:50:00' ), 3, null );

		self::assertSame( 102, $result['group_lesson_id'] );
	}

	public function test_equidistant_candidates_are_ambiguous(): void {
		$this->lessons->method( 'listByGroupAndDay' )->willReturn( array(
			$this->lesson( 101, '2026-07-08 12:00:00', array( 'ends_at' => '2026-07-08 12:30:00' ) ),
			$this->lesson( 102, '2026-07-08 13:00:00', array( 'ends_at' => '2026-07-08 14:00:00' ) ),
		) );

		// 12:30 — ровно посередине (по 30 мин до каждого scheduled_at) и в окне обоих:
		// 101 — [11:15; 13:15], 102 — [12:15; 14:45].
		$result = $this->resolver->resolve( $this->at( '2026-07-08 12:30:00' ), 3, null );

		self::assertNull( $result['group_lesson_id'] );
		self::assertSame( 'ambiguous', $result['reason'] );
	}

	public function test_cancelled_lessons_are_skipped(): void {
		$this->lessons->method( 'listByGroupAndDay' )->willReturn( array(
			$this->lesson( 101, '2026-07-08 16:00:00', array( 'status' => 'cancelled' ) ),
		) );

		$result = $this->resolver->resolve( $this->at( '2026-07-08 16:04:45' ), 3, null );

		self::assertSame( 'no_candidates', $result['reason'] );
	}

	public function test_individual_lesson_in_group_folder_matches_via_group_branch(): void {
		// «Индивидуальную запись положили в папку группы» — kind=individual в кандидатах группы.
		$this->lessons->method( 'listByGroupAndDay' )->willReturn( array(
			$this->lesson( 103, '2026-07-08 18:00:00', array( 'kind' => 'individual' ) ),
		) );

		$result = $this->resolver->resolve( $this->at( '2026-07-08 18:05:00' ), 3, null );

		self::assertSame( 103, $result['group_lesson_id'] );
	}

	// ── Ветка B: teacher_user_id ─────────────────────────────────────────────

	public function test_teacher_branch_uses_individual_lessons(): void {
		$this->lessons->expects( self::once() )->method( 'listIndividualByTeacherAndDay' )->with( 7, '2026-07-08' )
			->willReturn( array( $this->lesson( 201, '2026-07-08 16:00:00', array( 'kind' => 'individual' ) ) ) );
		$this->lessons->expects( self::never() )->method( 'listByGroupAndDay' );

		$result = $this->resolver->resolve( $this->at( '2026-07-08 16:10:00' ), null, 7 );

		self::assertSame( 201, $result['group_lesson_id'] );
		self::assertSame( 'matched', $result['reason'] );
	}

	public function test_group_id_takes_precedence_over_teacher(): void {
		$this->lessons->expects( self::once() )->method( 'listByGroupAndDay' )->willReturn( array() );
		$this->lessons->expects( self::never() )->method( 'listIndividualByTeacherAndDay' );

		$this->resolver->resolve( $this->at( '2026-07-08 16:00:00' ), 3, 7 );
	}

	public function test_no_branch_inputs_yields_no_candidates(): void {
		$result = $this->resolver->resolve( $this->at( '2026-07-08 16:00:00' ), null, null );

		self::assertSame( 'no_candidates', $result['reason'] );
	}

	public function test_lessons_without_scheduled_at_are_skipped(): void {
		$this->lessons->method( 'listByGroupAndDay' )->willReturn( array(
			$this->lesson( 101, null ),
		) );

		self::assertSame( 'no_candidates', $this->resolver->resolve( $this->at( '2026-07-08 16:00:00' ), 3, null )['reason'] );
	}
}
