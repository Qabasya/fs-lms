<?php

declare( strict_types=1 );

namespace Unit\Services\Course;

use Inc\DTO\Course\GroupLessonDTO;
use Inc\Services\Course\ExtraLessonUnits;
use PHPUnit\Framework\TestCase;

/** Дополнительное занятие для части группы — строка на ученика (общие extra_key и время); для журнала это одно занятие. */
class ExtraLessonUnitsTest extends TestCase {

	private function row( int $id, ?int $person, ?string $key, ?string $at, string $kind = 'individual' ): GroupLessonDTO {
		return GroupLessonDTO::fromArray( array(
			'id' => $id, 'group_id' => 5, 'lesson_id' => null, 'position' => 0, 'kind' => $kind, 'status' => 'scheduled',
			'visibility' => 'open', 'scheduled_at' => $at, 'student_person_id' => $person, 'extra_key' => $key,
		) );
	}

	public function test_rows_with_same_key_and_time_form_one_unit_with_highest_id_first(): void {
		$units = ExtraLessonUnits::group( array(
			$this->row( 59, 9008, 'k1', '2026-10-22 15:00:00' ),
			$this->row( 60, 9003, 'k1', '2026-10-22 15:00:00' ),
		) );

		self::assertCount( 1, $units );
		self::assertSame( 60, ExtraLessonUnits::key( $units[0] ) );
		self::assertSame( array( 60, 59 ), array_map( static fn( $r ) => $r->id, $units[0] ) );
	}

	public function test_row_moved_to_another_time_is_its_own_unit(): void {
		$units = ExtraLessonUnits::group( array(
			$this->row( 59, 9008, 'k1', '2026-10-22 15:00:00' ),
			$this->row( 60, 9003, 'k1', '2026-10-23 15:00:00' ),
		) );

		self::assertCount( 2, $units );
	}

	public function test_regular_individual_and_undated_rows_are_ignored(): void {
		$units = ExtraLessonUnits::group( array(
			$this->row( 1, null, null, '2026-10-22 15:00:00', 'group' ),
			$this->row( 2, 9003, null, '2026-10-22 15:00:00' ),
			$this->row( 3, 9003, 'k1', null ),
		) );

		self::assertSame( array(), $units );
	}

	public function test_key_map_points_every_own_row_to_the_unit_key(): void {
		$map = ExtraLessonUnits::keyMap( array(
			$this->row( 59, 9008, 'k1', '2026-10-22 15:00:00' ),
			$this->row( 60, 9003, 'k1', '2026-10-22 15:00:00' ),
			$this->row( 61, 9003, 'k2', '2026-10-25 15:00:00' ),
		) );

		self::assertEqualsCanonicalizing( array( 59 => 60, 60 => 60, 61 => 61 ), $map );
		self::assertSame( 60, $map[59] );
		self::assertSame( 61, $map[61] );
	}
}
