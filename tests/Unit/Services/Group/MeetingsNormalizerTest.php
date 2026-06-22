<?php

declare( strict_types=1 );

namespace Unit\Services\Group;

use Inc\Services\Group\MeetingsNormalizer;
use PHPUnit\Framework\TestCase;

class MeetingsNormalizerTest extends TestCase {

	public function test_enriches_entry_with_canonical_fields(): void {
		$out = MeetingsNormalizer::normalizeList( array(
			array( 'day' => 'wed', 'start' => '18:30', 'end' => '20:00' ),
		) );

		self::assertCount( 1, $out );
		self::assertSame( 'wed', $out[0]['day'] );      // legacy сохранён
		self::assertSame( '18:30', $out[0]['start'] );
		self::assertSame( '20:00', $out[0]['end'] );
		self::assertSame( 3, $out[0]['weekday'] );       // канон: Ср = 3 (ISO)
		self::assertSame( '18:30', $out[0]['time'] );
		self::assertSame( 90, $out[0]['duration_min'] );
	}

	public function test_iso_numbers_for_each_day(): void {
		$days = array( 'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 7 );
		foreach ( $days as $slug => $iso ) {
			$out = MeetingsNormalizer::normalizeList( array( array( 'day' => $slug, 'start' => '09:00', 'end' => '10:00' ) ) );
			self::assertSame( $iso, $out[0]['weekday'], "weekday for $slug" );
		}
	}

	public function test_drops_invalid_day(): void {
		$out = MeetingsNormalizer::normalizeList( array(
			array( 'day' => 'noday', 'start' => '10:00', 'end' => '11:00' ),
			array( 'day' => 'mon', 'start' => '10:00', 'end' => '11:00' ),
			'garbage',
		) );

		self::assertCount( 1, $out );
		self::assertSame( 'mon', $out[0]['day'] );
	}

	public function test_duration_fallback_when_time_invalid_or_inverted(): void {
		$missing  = MeetingsNormalizer::normalizeList( array( array( 'day' => 'mon', 'start' => '10:00', 'end' => '' ) ) );
		$inverted = MeetingsNormalizer::normalizeList( array( array( 'day' => 'mon', 'start' => '12:00', 'end' => '11:00' ) ) );

		self::assertSame( 60, $missing[0]['duration_min'] );
		self::assertSame( 60, $inverted[0]['duration_min'] );
	}

	public function test_idempotent_on_already_normalized_superset(): void {
		$once  = MeetingsNormalizer::normalizeList( array( array( 'day' => 'fri', 'start' => '15:00', 'end' => '16:30' ) ) );
		$twice = MeetingsNormalizer::normalizeList( $once );

		self::assertSame( $once, $twice );
	}
}