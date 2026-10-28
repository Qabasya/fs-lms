<?php

declare( strict_types=1 );

namespace Unit\Enums\Access;

use Inc\Enums\Access\AccessLevel;
use PHPUnit\Framework\TestCase;

/** Порядок уровней доступа: по нему шов `fs_lms_lesson_access` отличает понижение от повышения. */
class AccessLevelTest extends TestCase {

	public function test_rank_orders_none_below_read_below_read_submit(): void {
		self::assertSame( 0, AccessLevel::None->rank() );
		self::assertSame( 1, AccessLevel::Read->rank() );
		self::assertSame( 2, AccessLevel::ReadSubmit->rank() );
	}

	public function test_every_case_has_a_distinct_rank(): void {
		$ranks = array_map( static fn( AccessLevel $l ): int => $l->rank(), AccessLevel::cases() );

		self::assertSame( range( 0, count( $ranks ) - 1 ), $ranks, 'ранги идут подряд в порядке объявления' );
	}
}
