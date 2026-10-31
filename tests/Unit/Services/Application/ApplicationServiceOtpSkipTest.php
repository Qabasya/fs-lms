<?php

declare(strict_types=1);

namespace Unit\Services\Application;

use Inc\Contracts\ClockInterface;
use Inc\Contracts\LogEventDispatcherInterface;
use Inc\DTO\Application\ApplicationInputDTO;
use Inc\Repositories\WPDBRepositories\ApplicationRepository;
use Inc\Services\Application\ApplicationService;
use Inc\Services\Application\JoinCodeService;
use Inc\Services\Application\LoginAvailabilityService;
use Inc\Services\Email\EmailOtpService;
use Inc\Services\Enrollment\FamilyEmailPolicy;
use Inc\Services\Person\ConsentService;
use Inc\Services\Security\CredentialsPolicy;
use Inc\Services\Security\PiiCryptoService;
use PHPUnit\Framework\TestCase;

/**
 * Заявка из доверенной сети (FS_LMS_TRUSTED_IPS, офис) создаётся без OTP:
 * флаг skipOtp решает только ApplicationCallbacks по реальному IP запроса.
 */
class ApplicationServiceOtpSkipTest extends TestCase {

	private ApplicationRepository $repository;
	private EmailOtpService $otp;
	private ApplicationService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->repository = $this->createMock( ApplicationRepository::class );
		$this->otp        = $this->createMock( EmailOtpService::class );

		$joinCodes = $this->createStub( JoinCodeService::class );
		$joinCodes->method( 'generate' )->willReturn( '123456' );
		$joinCodes->method( 'hash' )->willReturn( 'hash' );
		$joinCodes->method( 'applicationExpiresAt' )->willReturn( '2030-01-01 00:00:00' );

		$crypto = $this->createStub( PiiCryptoService::class );
		$crypto->method( 'encrypt' )->willReturn( 'enc' );
		$crypto->method( 'hash' )->willReturn( 'email_hash' );

		$clock = $this->createStub( ClockInterface::class );
		$clock->method( 'now' )->willReturn( '2026-10-31 12:00:00' );

		$logins = $this->createStub( LoginAvailabilityService::class );
		$logins->method( 'isTaken' )->willReturn( false );
		$logins->method( 'hash' )->willReturn( 'login_hash' );

		$this->service = new ApplicationService(
			$this->repository,
			$joinCodes,
			$crypto,
			$this->createStub( ConsentService::class ),
			$this->createStub( LogEventDispatcherInterface::class ),
			$this->otp,
			$clock,
			$logins,
			$this->createStub( CredentialsPolicy::class ),
			$this->createStub( FamilyEmailPolicy::class ),
		);
	}

	private function input(): ApplicationInputDTO {
		return new ApplicationInputDTO(
			lastName:   'Иванов',
			firstName:  'Иван',
			middleName: '',
			email:      'ivan@test.com',
			phone:      '+79000000000',
			school:     'Школа №1',
			grade:      9,
			birthDate:  '2011-01-01',
			otpCode:    '',
			ip:         '10.0.0.1',
			userAgent:  'test',
			username:   'ivanov',
			password:   'Pass%123',
			subjectKey: 'inf_ege',
		);
	}

	public function test_trusted_ip_creates_application_without_otp_verification(): void {
		$this->otp->expects( $this->never() )->method( 'verify' );
		$this->repository->method( 'findActiveByEmail' )->willReturn( null );
		$this->repository->expects( $this->once() )->method( 'create' )->willReturn( 42 );

		$result = $this->service->createApplication( $this->input(), true );

		$this->assertSame( 42, $result->applicationId );
	}

	public function test_default_flow_still_verifies_otp(): void {
		$this->otp->expects( $this->once() )->method( 'verify' )->with( 'ivan@test.com', '123456' )->willReturn( true );
		$this->repository->method( 'findActiveByEmail' )->willReturn( null );
		$this->repository->expects( $this->once() )->method( 'create' )->willReturn( 43 );

		$input  = new ApplicationInputDTO(
			lastName: 'Иванов', firstName: 'Иван', middleName: '', email: 'ivan@test.com',
			phone: '+79000000000', school: 'Школа №1', grade: 9, birthDate: '2011-01-01',
			otpCode: '123456', ip: '8.8.8.8', userAgent: 'test', username: 'ivanov2', password: 'Pass%123',
		);
		$result = $this->service->createApplication( $input );

		$this->assertSame( 43, $result->applicationId );
	}

	public function test_wrong_otp_rejects_application_by_default(): void {
		$this->otp->expects( $this->once() )->method( 'verify' )->willReturn( false );
		$this->repository->expects( $this->never() )->method( 'create' );

		$this->expectException( \RuntimeException::class );
		$this->service->createApplication( $this->input() );
	}
}
