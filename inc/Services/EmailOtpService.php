<?php

declare( strict_types=1 );

namespace Inc\Services;

use RuntimeException;

readonly class EmailOtpService {

	public function __construct(
		private EmailService $emailService,
	) {}

	public function sendCode( string $email ): void {
		if ( ! $this->canResend( $email ) ) {
			throw new RuntimeException( 'Повторная отправка кода недоступна. Подождите перед следующей попыткой.' );
		}

		$code = (string) random_int( 100000, 999999 );
		$hash = $this->hashCode( $code );

		set_transient( $this->otpKey( $email ), $hash, 600 );

		$this->emailService->sendOtpCode( $email, $code );

		set_transient( $this->cooldownKey( $email ), 1, 60 );
	}

	public function verify( string $email, string $code ): bool {
		if ( defined( 'FS_LMS_OTP_BYPASS_CODE' ) && $code === FS_LMS_OTP_BYPASS_CODE ) {
			return true;
		}

		$stored = get_transient( $this->otpKey( $email ) );

		if ( false === $stored ) {
			return false;
		}

		if ( ! hash_equals( (string) $stored, $this->hashCode( $code ) ) ) {
			return false;
		}

		delete_transient( $this->otpKey( $email ) );

		return true;
	}

	public function canResend( string $email ): bool {
		return false === get_transient( $this->cooldownKey( $email ) );
	}

	public function invalidate( string $email ): void {
		delete_transient( $this->otpKey( $email ) );
		delete_transient( $this->cooldownKey( $email ) );
	}

	private function otpKey( string $email ): string {
		return 'fs_lms_otp_' . hash( 'sha256', $email );
	}

	private function cooldownKey( string $email ): string {
		return 'fs_lms_otp_cd_' . hash( 'sha256', $email );
	}

	private function hashCode( string $code ): string {
		$salt = defined( 'FS_LMS_HASH_SALT' ) ? FS_LMS_HASH_SALT : '';

		return hash( 'sha256', $code . $salt );
	}
}