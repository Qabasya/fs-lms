<?php

declare( strict_types=1 );

namespace Unit\Modules\EgeComputer;

use Inc\Modules\EgeComputer\Config\OgeCriteriaConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Рубрики ручной проверки 13.1/13.2/14/15/16 «Компьютерного ОГЭ» — источник
 * .docs/oge/criteries.md (2026-08-18). Holistic-модель (один балл 0..max),
 * не аддитивная CriteriaField — см. докблок OgeCriteriaConfig.
 */
class OgeCriteriaConfigTest extends TestCase {

	public static function positionsProvider(): array {
		return [
			'13.1' => [ '13.1', 2 ],
			'13.2' => [ '13.2', 2 ],
			'14'   => [ '14', 3 ],
			'15'   => [ '15', 2 ],
			'16'   => [ '16', 2 ],
		];
	}

	#[DataProvider( 'positionsProvider' )]
	public function test_known_position_returns_rubric_with_max_points( string $position, int $expectedMax ): void {
		$rubric = OgeCriteriaConfig::rubricFor( $position );

		self::assertNotNull( $rubric );
		self::assertSame( $expectedMax, $rubric['max_points'] );
		self::assertStringContainsString( 'fs-oge-rubric__tier', $rubric['html'] );
		// Все уровни присутствуют, от максимума до нуля.
		self::assertStringContainsString( '0 балл', $rubric['html'] );
	}

	public function test_unknown_position_returns_null(): void {
		self::assertNull( OgeCriteriaConfig::rubricFor( '13' ) );
		self::assertNull( OgeCriteriaConfig::rubricFor( '17' ) );
		self::assertNull( OgeCriteriaConfig::rubricFor( '' ) );
	}
}
