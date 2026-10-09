<?php

declare( strict_types=1 );

namespace Tests\Unit\Services\Shared;

use Inc\Repositories\OptionsRepositories\PluginConfigRepository;
use Inc\Services\Shared\PluginConfig;
use PHPUnit\Framework\TestCase;

/**
 * Настройки экзаменов для гостей: значения по умолчанию и приведение к границам (11a.6.1).
 */
class PluginConfigTest extends TestCase {

	/** @param array<string, mixed> $stored */
	private function config( array $stored = array() ): PluginConfig {
		$repo = $this->createMock( PluginConfigRepository::class );
		$repo->method( 'get' )->willReturn( $stored );

		return new PluginConfig( $repo );
	}

	public function test_defaults(): void {
		$c = $this->config();

		self::assertSame( 0, $c->examProductId( 9 ) );
		self::assertSame( 0, $c->examProductId( 11 ) );
		self::assertSame( 20, $c->examHoldMinutes() );
		self::assertSame( 40, $c->examIpActiveHoldsLimit() );
		self::assertSame( 60, $c->examIpHourlyLimit() );
		self::assertSame( 60, $c->examSourceActiveHoldsLimit() );
		self::assertSame( 365, $c->examGuestRetentionDays() );
		self::assertSame( 30, $c->examUnpaidRetentionDays() );
		self::assertSame( array( 'phone' => '', 'email' => '', 'hours' => '', 'address' => '' ), $c->centerContactsFallback() );
	}

	public function test_values_are_clamped_to_bounds(): void {
		self::assertSame( 5, $this->config( array( 'exam_hold_minutes' => 1 ) )->examHoldMinutes() );
		self::assertSame( 120, $this->config( array( 'exam_hold_minutes' => 999 ) )->examHoldMinutes() );
		self::assertSame( 1, $this->config( array( 'exam_ip_hourly' => 0 ) )->examIpHourlyLimit() );
		self::assertSame( 20, $this->config( array( 'exam_hold_minutes' => 'abc' ) )->examHoldMinutes() );
	}

	public function test_one_product_may_serve_both_grades(): void {
		$c = $this->config( array( 'exam_product_9' => 77, 'exam_product_11' => 77 ) );

		self::assertSame( 77, $c->examProductId( 9 ) );
		self::assertSame( 77, $c->examProductId( 11 ) );
	}

	public function test_view_state_has_exam_block(): void {
		$state = $this->config( array( 'exam_hold_minutes' => 30 ) )->viewState();

		self::assertSame( 30, $state['exams']['hold_minutes'] );
	}
}
