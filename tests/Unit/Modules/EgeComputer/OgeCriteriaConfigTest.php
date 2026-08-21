<?php

declare( strict_types=1 );

namespace Unit\Modules\EgeComputer;

use Inc\Modules\EgeComputer\Config\OgeCriteriaConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Рубрики ручной проверки 13/14/15/16 «Компьютерного ОГЭ» — источник
 * .docs/oge/criteries.md (2026-08-18). Holistic-модель (один балл 0..max),
 * не аддитивная CriteriaField — см. докблок OgeCriteriaConfig.
 *
 * Позиция «13» — альтернатива 13.1/13.2 внутри одного поста
 * (`AlternativeConditionsTemplate`, 2026-08-18): рубрика содержит критерии
 * ОБОИХ вариантов сразу, проверяющий выбирает подходящий сам.
 */
class OgeCriteriaConfigTest extends TestCase {

	public static function positionsProvider(): array {
		return [
			'13' => [ '13', 2 ],
			'14' => [ '14', 3 ],
			'15' => [ '15', 2 ],
			'16' => [ '16', 2 ],
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

	public function test_position_13_contains_both_alternative_variants(): void {
		$rubric = OgeCriteriaConfig::rubricFor( '13' );

		self::assertNotNull( $rubric );
		self::assertStringContainsString( 'fs-oge-rubric__variant', $rubric['html'] );
		self::assertStringContainsString( '13.1', $rubric['html'] );
		self::assertStringContainsString( '13.2', $rubric['html'] );
		// Оба набора уровней присутствуют — 2 вхождения "2 балла" (верх шкалы каждого варианта).
		self::assertSame( 2, substr_count( $rubric['html'], 'fs-oge-rubric__variant' ) );
	}

	public function test_unknown_position_returns_null(): void {
		self::assertNull( OgeCriteriaConfig::rubricFor( '13.1' ) );
		self::assertNull( OgeCriteriaConfig::rubricFor( '13.2' ) );
		self::assertNull( OgeCriteriaConfig::rubricFor( '17' ) );
		self::assertNull( OgeCriteriaConfig::rubricFor( '' ) );
	}
}
