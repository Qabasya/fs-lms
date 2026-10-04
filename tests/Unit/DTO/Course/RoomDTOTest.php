<?php

declare( strict_types=1 );

namespace Unit\DTO\Course;

use Inc\DTO\Course\RoomDTO;
use PHPUnit\Framework\TestCase;

/** Кабинет: вместимость и допуск предмета — то, чем пользуется назначение сеанса экзамена. */
class RoomDTOTest extends TestCase {

	/** @param array<string, mixed> $override */
	private function room( array $override = array() ): RoomDTO {
		return RoomDTO::fromArray( array_merge( array( 'id' => '2', 'name' => '315', 'seats' => '20', 'allowed_subjects' => '[]', 'is_active' => '1' ), $override ) );
	}

	public function test_has_capacity_false_for_zero_seats(): void {
		self::assertFalse( $this->room( array( 'seats' => '0' ) )->hasCapacity() );
	}

	public function test_has_capacity_true_for_positive_seats(): void {
		self::assertTrue( $this->room()->hasCapacity() );
	}

	public function test_missing_seats_column_means_no_capacity(): void {
		$row = array( 'id' => '2', 'name' => '315', 'allowed_subjects' => '[]', 'is_active' => '1' );

		self::assertFalse( RoomDTO::fromArray( $row )->hasCapacity() );
	}

	public function test_empty_subject_list_allows_any_subject(): void {
		self::assertTrue( $this->room()->allowsSubject( 'inf_ege' ) );
	}

	public function test_subject_list_restricts_subjects(): void {
		$room = $this->room( array( 'allowed_subjects' => '["inf_ege","inf_oge"]' ) );

		self::assertTrue( $room->allowsSubject( 'inf_oge' ) );
		self::assertFalse( $room->allowsSubject( 'python' ) );
	}

	public function test_to_array_exposes_seats(): void {
		self::assertSame( 20, $this->room()->toArray()['seats'] );
	}
}
