<?php

declare(strict_types=1);

namespace Unit\Services\Captcha;

use Inc\Contracts\CaptchaProviderInterface;
use Inc\Enums\Auth\AuthAction;
use Inc\Enums\Auth\AuthResult;
use Inc\Enums\Auth\CaptchaFailure;
use Inc\Enums\Auth\CaptchaOutcome;
use Inc\Enums\Auth\CaptchaScope;
use Inc\Enums\Auth\LoginFailReason;
use Inc\Services\Captcha\CaptchaProviderFactory;
use Inc\Services\Captcha\CaptchaService;
use Inc\Services\Log\AuthLogWriter;
use Inc\Services\Security\RateLimitService;
use Inc\Services\Shared\PluginConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Смягчённое правило капчи: сбой доставки (VPN/блокировщик) пропускается по лимиту, не бесконечно.
 */
class CaptchaServiceTest extends TestCase {

	private const IP = '10.0.0.1';

	private AuthLogWriter&\PHPUnit\Framework\MockObject\MockObject $authLog;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_test_transients'] = array();
	}

	private function makeService( bool $configured = true, bool $tokenValid = true ): CaptchaService {
		$provider = $this->createStub( CaptchaProviderInterface::class );
		$provider->method( 'isConfigured' )->willReturn( $configured );
		$provider->method( 'validate' )->willReturn( $tokenValid );

		$factory = $this->createStub( CaptchaProviderFactory::class );
		$factory->method( 'make' )->willReturn( $provider );

		$config = $this->createStub( PluginConfig::class );
		$config->method( 'isTestEnv' )->willReturn( false );
		$config->method( 'trustedIps' )->willReturn( array() );

		$this->authLog = $this->createMock( AuthLogWriter::class );

		return new CaptchaService( $factory, new RateLimitService( $config ), $this->authLog );
	}

	public function test_not_configured_captcha_always_passes(): void {
		$service = $this->makeService( configured: false );

		self::assertSame( CaptchaOutcome::Passed, $service->check( '', self::IP, CaptchaScope::Apply ) );
	}

	public function test_valid_token_passes_and_invalid_is_rejected(): void {
		self::assertSame( CaptchaOutcome::Passed, $this->makeService( tokenValid: true )->check( 'tok', self::IP, CaptchaScope::Login ) );
		self::assertSame( CaptchaOutcome::Rejected, $this->makeService( tokenValid: false )->check( 'tok', self::IP, CaptchaScope::Login ) );
	}

	public function test_invalid_token_is_not_softened_by_failure_claim(): void {
		$service = $this->makeService( tokenValid: false );

		self::assertSame(
			CaptchaOutcome::Rejected,
			$service->check( 'forged', self::IP, CaptchaScope::Apply, CaptchaFailure::NotLoaded )
		);
	}

	public function test_empty_token_without_reason_is_rejected(): void {
		$service = $this->makeService();
		$this->authLog->expects( $this->never() )->method( 'recordEvent' );

		self::assertSame( CaptchaOutcome::Rejected, $service->check( '', self::IP, CaptchaScope::Apply ) );
	}

	public function test_dismissed_challenge_is_rejected(): void {
		$service = $this->makeService();
		$this->authLog->expects( $this->never() )->method( 'recordEvent' );

		self::assertSame(
			CaptchaOutcome::Rejected,
			$service->check( '', self::IP, CaptchaScope::Apply, CaptchaFailure::Dismissed )
		);
	}

	#[DataProvider( 'deliveryFailures' )]
	public function test_delivery_failure_passes_in_fallback_and_is_logged( CaptchaFailure $failure, LoginFailReason $reason ): void {
		$service = $this->makeService();
		$this->authLog->expects( $this->once() )->method( 'recordEvent' )->with(
			AuthAction::CaptchaFallback,
			AuthResult::Success,
			$reason,
			$this->callback( static fn( array $d ): bool => 'apply' === $d['form'] && 'v1' === $d['visit'] ),
			'a@b.ru'
		);

		self::assertSame(
			CaptchaOutcome::Fallback,
			$service->check( '', self::IP, CaptchaScope::Apply, $failure, 'a@b.ru', 'v1' )
		);
	}

	/** @return array<string, array{CaptchaFailure, LoginFailReason}> */
	public static function deliveryFailures(): array {
		return array(
			'не загрузилась' => array( CaptchaFailure::NotLoaded, LoginFailReason::CaptchaNotLoaded ),
			'не открылась'   => array( CaptchaFailure::Timeout, LoginFailReason::CaptchaTimeout ),
			'сеть'           => array( CaptchaFailure::Network, LoginFailReason::CaptchaNetwork ),
		);
	}

	// ── Шов fs_lms_captcha_fallback_unlimited (Ш6) ───────────────────────────────

	protected function tearDown(): void {
		unset( $GLOBALS['_fs_test_filter_returns'], $GLOBALS['_fs_test_record_filters'], $GLOBALS['_fs_test_filter_calls'] );
		parent::tearDown();
	}

	private function unlimitedAnswer( mixed $value ): void {
		$GLOBALS['_fs_test_filter_returns']['fs_lms_captcha_fallback_unlimited'] = $value;
	}

	public function test_seam_without_subscriber_keeps_the_limit(): void {
		$service = $this->makeService();

		for ( $i = 1; $i <= 30; $i++ ) {
			self::assertSame( CaptchaOutcome::Fallback, $service->check( '', self::IP, CaptchaScope::Login, CaptchaFailure::NotLoaded ), "пропуск #$i" );
		}
		self::assertSame( CaptchaOutcome::Rejected, $service->check( '', self::IP, CaptchaScope::Login, CaptchaFailure::NotLoaded ), 'лимит входа — 30 в час' );
	}

	public function test_subscriber_can_lift_the_limit_for_a_known_request(): void {
		$this->unlimitedAnswer( true );
		$service = $this->makeService();

		for ( $i = 1; $i <= 40; $i++ ) {
			self::assertSame( CaptchaOutcome::Fallback, $service->check( '', self::IP, CaptchaScope::Login, CaptchaFailure::NotLoaded, 'student-5' ), "пропуск #$i" );
		}
	}

	public function test_only_true_lifts_the_limit(): void {
		$service = $this->makeService();
		$this->unlimitedAnswer( 'yes' );

		for ( $i = 1; $i <= 30; $i++ ) {
			$service->check( '', self::IP, CaptchaScope::Login, CaptchaFailure::NotLoaded );
		}

		self::assertSame( CaptchaOutcome::Rejected, $service->check( '', self::IP, CaptchaScope::Login, CaptchaFailure::NotLoaded ), 'не-true шов не считается' );
	}

	public function test_seam_receives_scope_identifier_and_ip(): void {
		$GLOBALS['_fs_test_record_filters'] = true;
		$service                            = $this->makeService();

		$service->check( '', self::IP, CaptchaScope::Login, CaptchaFailure::NotLoaded, 'student-5' );

		$calls = array_values( array_filter( $GLOBALS['_fs_test_filter_calls'], static fn( array $c ): bool => 'fs_lms_captcha_fallback_unlimited' === $c['hook'] ) );
		self::assertCount( 1, $calls );
		self::assertFalse( $calls[0]['value'], 'по умолчанию лимит действует' );
		self::assertSame( array( CaptchaScope::Login, 'student-5', self::IP ), $calls[0]['args'] );
	}

	public function test_seam_is_not_asked_for_a_token_or_a_failure_that_forbids_fallback(): void {
		$GLOBALS['_fs_test_record_filters'] = true;
		$this->unlimitedAnswer( true );

		$this->makeService( tokenValid: false )->check( 'tok', self::IP, CaptchaScope::Login, CaptchaFailure::NotLoaded, 'student-5' );
		$this->makeService()->check( '', self::IP, CaptchaScope::Login, CaptchaFailure::Dismissed, 'student-5' );
		$this->makeService()->check( '', self::IP, CaptchaScope::Login, null, 'student-5' );

		self::assertSame( array(), $GLOBALS['_fs_test_filter_calls'] ?? array(), 'шов зовётся только при сбое доставки, который разрешает пропуск' );
	}

	public function test_dismissed_challenge_stays_rejected_even_when_a_subscriber_lifts_the_limit(): void {
		$this->unlimitedAnswer( true );

		self::assertSame( CaptchaOutcome::Rejected, $this->makeService()->check( '', self::IP, CaptchaScope::Login, CaptchaFailure::Dismissed, 'student-5' ) );
	}

	public function test_lifted_limit_is_still_logged_and_marked_in_the_note(): void {
		$this->unlimitedAnswer( true );
		$service = $this->makeService();
		$this->authLog->expects( $this->once() )->method( 'recordEvent' )->with(
			AuthAction::CaptchaFallback,
			AuthResult::Success,
			$this->anything(),
			$this->callback( static fn( array $d ): bool => str_contains( (string) $d['note'], 'без лимита по правилу модуля' ) ),
			'student-5'
		);

		$service->check( '', self::IP, CaptchaScope::Login, CaptchaFailure::NotLoaded, 'student-5' );
	}

	public function test_limited_fallback_note_has_no_module_remark(): void {
		$service = $this->makeService();
		$this->authLog->expects( $this->once() )->method( 'recordEvent' )->with(
			AuthAction::CaptchaFallback,
			AuthResult::Success,
			$this->anything(),
			$this->callback( static fn( array $d ): bool => ! str_contains( (string) $d['note'], 'модуля' ) ),
			null
		);

		$service->check( '', self::IP, CaptchaScope::Login, CaptchaFailure::NotLoaded );
	}

	public function test_fallback_is_limited_per_ip_and_scope(): void {
		$service = $this->makeService();

		// Заявка: 8 пропусков в час с адреса, девятый — отказ.
		for ( $i = 1; $i <= 8; $i++ ) {
			self::assertSame( CaptchaOutcome::Fallback, $service->check( '', self::IP, CaptchaScope::Apply, CaptchaFailure::NotLoaded ), "пропуск #$i" );
		}
		self::assertSame( CaptchaOutcome::Rejected, $service->check( '', self::IP, CaptchaScope::Apply, CaptchaFailure::NotLoaded ) );

		// Вход считается отдельно и другим IP лимит заявки не мешает.
		self::assertSame( CaptchaOutcome::Fallback, $service->check( '', self::IP, CaptchaScope::Login, CaptchaFailure::NotLoaded ) );
		self::assertSame( CaptchaOutcome::Fallback, $service->check( '', '10.0.0.2', CaptchaScope::Apply, CaptchaFailure::NotLoaded ) );
	}
}
