<?php

declare( strict_types=1 );

namespace Inc\DTO\Course;

/**
 * Class ScheduleReflowResultDTO
 *
 * Результат раскладки тем курса по слотам периода (Этап 2, Tasks.md):
 * помимо конфликтов кабинета — сколько слотов сгенерировано, сколько строк
 * претендует на слот и сколько из них не поместилось (ушли в пул «Темы курса»).
 *
 * @package Inc\DTO\Course
 */
readonly class ScheduleReflowResultDTO {

	public function __construct(
		public int $conflicts,
		public int $slots,
		public int $consuming,
		public int $unplaced,
	) {}
}
