<?php

declare( strict_types=1 );

namespace Unit\Services\Assessment;

use Inc\Services\Assessment\SecondaryScoreService;
use PHPUnit\Framework\TestCase;

class SecondaryScoreServiceTest extends TestCase {

	private SecondaryScoreService $svc;

	protected function setUp(): void {
		$this->svc = new SecondaryScoreService();
	}

	private function map(): array {
		return [ 0 => 0, 5 => 36, 10 => 64, 15 => 100 ];
	}

	public function test_exact_match(): void {
		self::assertSame( 36, $this->svc->translate( 5.0, $this->map() ) );
	}

	public function test_floor_used_for_fractional_primary(): void {
		// 5.9 → floor → 5 → 36
		self::assertSame( 36, $this->svc->translate( 5.9, $this->map() ) );
	}

	public function test_nearest_lower_key_used_when_no_exact_match(): void {
		// 7 → no exact match, nearest lower = 5 → 36
		self::assertSame( 36, $this->svc->translate( 7.0, $this->map() ) );
	}

	public function test_zero_primary_score(): void {
		self::assertSame( 0, $this->svc->translate( 0.0, $this->map() ) );
	}

	public function test_maximum_primary_score(): void {
		self::assertSame( 100, $this->svc->translate( 15.0, $this->map() ) );
	}

	public function test_primary_above_maximum_returns_max_secondary(): void {
		self::assertSame( 100, $this->svc->translate( 99.0, $this->map() ) );
	}

	public function test_empty_score_map_returns_null(): void {
		self::assertNull( $this->svc->translate( 5.0, [] ) );
	}

	public function test_primary_below_minimum_returns_null(): void {
		// Нет ключа ≤ −1 → null.
		$map = [ 1 => 10, 2 => 20 ];
		self::assertNull( $this->svc->translate( 0.0, $map ) );
	}
}
