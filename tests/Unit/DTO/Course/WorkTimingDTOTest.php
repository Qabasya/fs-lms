<?php

declare( strict_types=1 );

namespace Unit\DTO\Course;

use Inc\DTO\Course\WorkTimingDTO;
use PHPUnit\Framework\TestCase;

class WorkTimingDTOTest extends TestCase {

	public function test_from_array_parses_player_payload(): void {
		$dto = WorkTimingDTO::fromArray( array( 'elapsed' => '600', 'answered' => array( '12' => 30, '13' => '45' ) ) );

		self::assertSame( 600, $dto->elapsedSec );
		self::assertSame( array( 12 => 30, 13 => 45 ), $dto->answeredAgoSec );
	}

	public function test_from_array_drops_invalid_values(): void {
		$dto = WorkTimingDTO::fromArray( array( 'elapsed' => -5, 'answered' => array( '12' => 'x', '0' => 10, '13' => -1 ) ) );

		self::assertNull( $dto->elapsedSec );
		self::assertSame( array(), $dto->answeredAgoSec );
	}

	public function test_answered_at_is_offset_from_server_now(): void {
		$dto = new WorkTimingDTO( 600, array( 12 => 90 ) );

		self::assertSame( '2024-06-01 11:58:30', $dto->answeredAt( 12, '2024-06-01 12:00:00' ) );
		self::assertNull( $dto->answeredAt( 99, '2024-06-01 12:00:00' ) );
	}

	/** Ответ не может предшествовать открытию работы. */
	public function test_answered_at_is_clamped_to_work_start(): void {
		$dto = new WorkTimingDTO( 60, array( 12 => 3600 ) );

		self::assertSame( '2024-06-01 11:59:00', $dto->answeredAt( 12, '2024-06-01 12:00:00' ) );
	}
}
