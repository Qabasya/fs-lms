<?php

declare(strict_types=1);

namespace Unit\Services\Security;

use Inc\DTO\Person\LoginAttemptDTO;
use Inc\Enums\Auth\LoginFailReason;
use Inc\Managers\Person\UserManager;
use Inc\Services\Security\LoginDiagnosticsService;
use Inc\Services\Security\LoginGuardService;
use PHPUnit\Framework\TestCase;

/**
 * Отказ по капче в журнале входа различает три случая: форма промолчала, капча не дошла (VPN), задание закрыто.
 */
class LoginDiagnosticsCaptchaTest extends TestCase {

	private function reason( string $token, string $unavailable ): LoginFailReason {
		$users = $this->createMock( UserManager::class );
		$error = new \WP_Error( LoginGuardService::CODE_CAPTCHA, 'x' );
		$dto   = ( new LoginDiagnosticsService( $users ) )->diagnose( new LoginAttemptDTO( 'user', 'pw', $token, true, $unavailable ), 'user', $error );

		return $dto->reason;
	}

	public function test_no_token_and_no_reason_means_form_script_did_not_report(): void {
		self::assertSame( LoginFailReason::CaptchaMissing, $this->reason( '', '' ) );
	}

	public function test_vpn_failure_with_exhausted_limit_is_its_own_reason(): void {
		self::assertSame( LoginFailReason::CaptchaFallbackLimit, $this->reason( '', 'network' ) );
	}

	public function test_dismissed_challenge_and_rejected_token_are_distinguished(): void {
		self::assertSame( LoginFailReason::CaptchaDismissed, $this->reason( '', 'dismissed' ) );
		self::assertSame( LoginFailReason::CaptchaRejected, $this->reason( 'tok', '' ) );
	}
}
