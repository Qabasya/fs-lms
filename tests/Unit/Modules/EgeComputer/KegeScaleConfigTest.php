<?php

declare( strict_types=1 );

namespace Unit\Modules\EgeComputer;

use Inc\Modules\EgeComputer\Config\KegeScaleConfig;
use PHPUnit\Framework\TestCase;

/**
 * Максимум КЕГЭ фиксирован: 29 первичных баллов, 100 вторичных.
 */
class KegeScaleConfigTest extends TestCase {

	public function test_primary_max_is_29(): void {
		self::assertSame( 29, KegeScaleConfig::primaryMax() );
	}

	public function test_scale_maps_full_primary_to_100(): void {
		self::assertSame( 100, KegeScaleConfig::scale()[ KegeScaleConfig::primaryMax() ] );
		self::assertSame( 100, KegeScaleConfig::secondaryMax() );
	}

	public function test_tasks_26_and_27_take_two_answers_the_rest_one(): void {
		self::assertSame( 2, KegeScaleConfig::answerSlots( 26 ) );
		self::assertSame( 2, KegeScaleConfig::answerSlots( 27 ) );
		self::assertSame( 1, KegeScaleConfig::answerSlots( 14 ) );
	}
}
