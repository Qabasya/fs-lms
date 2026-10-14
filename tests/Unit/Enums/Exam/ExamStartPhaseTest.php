<?php

declare( strict_types=1 );

namespace Tests\Unit\Enums\Exam;

use Inc\Enums\Exam\ExamStartPhase;
use PHPUnit\Framework\TestCase;

/** Фаза старта экзамена: больше 5 минут, меньше 5 минут, идёт, завершён. */
class ExamStartPhaseTest extends TestCase {

	public function test_far_before_five_minutes(): void {
		self::assertSame( ExamStartPhase::Far, ExamStartPhase::of( 301, 4000 ) );
		self::assertSame( ExamStartPhase::Far, ExamStartPhase::of( 86400, 90000 ) );
	}

	public function test_soon_within_five_minutes(): void {
		self::assertSame( ExamStartPhase::Soon, ExamStartPhase::of( 300, 3900 ) );
		self::assertSame( ExamStartPhase::Soon, ExamStartPhase::of( 1, 3600 ) );
	}

	public function test_running_from_start_until_window_end(): void {
		self::assertSame( ExamStartPhase::Running, ExamStartPhase::of( 0, 3600 ) );
		self::assertSame( ExamStartPhase::Running, ExamStartPhase::of( 0, 1 ) );
	}

	public function test_ended_when_window_is_over(): void {
		self::assertSame( ExamStartPhase::Ended, ExamStartPhase::of( 0, 0 ) );
		self::assertSame( ExamStartPhase::Ended, ExamStartPhase::of( -100, -1 ) );
	}
}
