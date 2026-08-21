<?php

declare( strict_types=1 );

namespace Unit\Modules\EgeComputer;

use Inc\Modules\EgeComputer\Config\OgeScaleConfig;
use PHPUnit\Framework\TestCase;

/**
 * Регрессия на цифры из .docs/oge/scores.md (2026-08-18) — источник истины,
 * тест ловит случайную опечатку при переносе таблицы в код.
 */
class OgeScaleConfigTest extends TestCase {

	public function test_scale_matches_scores_md(): void {
		$expected = [
			0 => 2, 1 => 2, 2 => 2, 3 => 2, 4 => 2,
			5 => 3, 6 => 3, 7 => 3, 8 => 3, 9 => 3, 10 => 3,
			11 => 4, 12 => 4, 13 => 4, 14 => 4, 15 => 4, 16 => 4,
			17 => 5, 18 => 5, 19 => 5, 20 => 5, 21 => 5,
		];

		self::assertSame( $expected, OgeScaleConfig::scale() );
	}

	public function test_secondary_max_is_five(): void {
		self::assertSame( 5, OgeScaleConfig::secondaryMax() );
	}

	public function test_max_primary_is_twenty_one(): void {
		self::assertSame( 21, OgeScaleConfig::maxPrimary() );
	}
}
