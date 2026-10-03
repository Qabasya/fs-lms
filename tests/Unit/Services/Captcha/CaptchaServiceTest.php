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
