<?php

declare( strict_types=1 );

namespace Unit\Modules\EgeComputer;

use Inc\Enums\Assessment\AssessmentKind;
use Inc\Modules\EgeComputer\Config\KegeScaleConfig;
use Inc\Modules\EgeComputer\Config\OgeScaleConfig;
use Inc\Modules\EgeComputer\Config\StationExamConfig;
use PHPUnit\Framework\TestCase;

/**
 * Регрессия на цифры из .docs/oge/scores.md (2026-08-18): время/попытки/
 * проходной балл станций больше не редактируются автором работы.
 */
class StationExamConfigTest extends TestCase {

	public function test_control_has_no_override(): void {
		self::assertNull( StationExamConfig::for( AssessmentKind::Control ) );
	}

	public function test_ege_computer_settings(): void {
		$settings = StationExamConfig::for( AssessmentKind::EgeComputer );

		self::assertNotNull( $settings );
		self::assertSame( 235, $settings['timeLimit'] );
		self::assertSame( 1, $settings['maxAttempts'] );
		self::assertSame( 6.0, $settings['passScore'] );
		self::assertSame( KegeScaleConfig::scale(), $settings['scoreMap'] );
	}

	public function test_oge_computer_settings(): void {
		$settings = StationExamConfig::for( AssessmentKind::OgeComputer );

		self::assertNotNull( $settings );
		self::assertSame( 150, $settings['timeLimit'] );
		self::assertSame( 1, $settings['maxAttempts'] );
		self::assertSame( 5.0, $settings['passScore'] );
		self::assertSame( OgeScaleConfig::scale(), $settings['scoreMap'] );
	}
}
