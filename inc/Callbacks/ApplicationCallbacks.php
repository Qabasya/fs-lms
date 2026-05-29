<?php

declare( strict_types=1 );

namespace Inc\Callbacks;

use Inc\Core\BaseController;
use Inc\Enums\Nonce;
use Inc\Services\ApplicationService;
use Inc\Services\CaptchaService;
use Inc\Services\EmailOtpService;
use Inc\Services\RateLimitService;
use Inc\DTO\ApplicationInputDTO;
use Inc\DTO\ParentSubmissionInputDTO;
use Inc\Shared\Traits\Sanitizer;

/**
 * Class ApplicationCallbacks
 *
 * AJAX-коллбеки публичной формы зачисления (двухэтапный OTP-флоу).
 *
 * @package Inc\Callbacks
 */
class ApplicationCallbacks extends BaseController {

	use Sanitizer;

	public function __construct(
		private readonly ApplicationService $applicationService,
		private readonly EmailOtpService    $emailOtpService,
		private readonly CaptchaService     $captchaService,
		private readonly RateLimitService   $rateLimitService,
	) {
		parent::__construct();
	}

	/**
	 * Шаг A: проверяет капчу, отправляет OTP-код на email.
	 */
	public function ajaxSendOtpCode(): void {
		Nonce::Apply->verify();

		$ip = (string) ( $_SERVER['REMOTE_ADDR'] ?? '' );

		if ( ! $this->rateLimitService->allowApplicationCreation( $ip ) ) {
			$this->error( 'Слишком много запросов. Попробуйте позже.' );
		}

		$captchaToken = $this->sanitizeText( $_POST['captcha_token'] ?? '' );
		if ( ! $this->captchaService->validate( $captchaToken, $ip ) ) {
			$this->error( 'Проверка капчи не пройдена.' );
		}

		$email = $this->sanitizeText( $_POST['email'] ?? '' );

		if ( ! $this->emailOtpService->canResend( $email ) ) {
			$this->error( 'Повторная отправка возможна через 60 секунд.' );
		}

		$this->emailOtpService->sendCode( $email );

		$masked = (string) preg_replace( '/(?<=.).(?=[^@]*@)/', '*', $email );
		$this->success( array( 'masked_email' => $masked ) );
	}

	/**
	 * Шаг B: верифицирует OTP, создаёт заявку.
	 */
	public function ajaxCreateApplication(): void {
		Nonce::VerifyOtp->verify();

		$ip = (string) ( $_SERVER['REMOTE_ADDR'] ?? '' );

		if ( ! $this->rateLimitService->allowApplicationCreation( $ip ) ) {
			$this->error( 'Слишком много запросов. Попробуйте позже.' );
		}

		$fullName  = $this->requireText( $_POST['full_name'] ?? '' );
		$email     = $this->requireText( $_POST['email'] ?? '' );
		$school    = $this->sanitizeText( $_POST['school'] ?? '' );
		$grade     = $this->sanitizeInt( $_POST['grade'] ?? 0 );
		$birthDate = $this->requireText( $_POST['birth_date'] ?? '' );
		$consent   = ! empty( $_POST['consent_accepted'] );
		$otpCode   = $this->requireText( $_POST['otp_code'] ?? '' );
		$ua        = (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' );

		$dto = new ApplicationInputDTO(
			fullName:        $fullName,
			email:           $email,
			school:          $school,
			grade:           $grade,
			birthDate:       $birthDate,
			consentAccepted: $consent,
			otpCode:         $otpCode,
			ip:              $ip,
			userAgent:       $ua,
		);

		try {
			$result = $this->applicationService->createApplication( $dto );
		} catch ( \DomainException $e ) {
			$this->error( $e->getMessage() );
		} catch ( \RuntimeException $e ) {
			$this->error( 'Неверный или истёкший код подтверждения.' );
		}

		$this->success( array(
			'join_url'   => $result->joinUrl,
			'expires_at' => $result->expiresAt,
		) );
	}

	/**
	 * Родитель отправляет свои данные по JOIN-ссылке.
	 */
	public function ajaxSubmitParentData(): void {
		Nonce::ParentSubmit->verify();

		$ip = (string) ( $_SERVER['REMOTE_ADDR'] ?? '' );

		if ( ! $this->rateLimitService->allowParentSubmit( $ip ) ) {
			$this->error( 'Слишком много запросов. Попробуйте позже.' );
		}

		$dto = new ParentSubmissionInputDTO(
			joinCode:          $this->requireText( $_POST['join_code'] ?? '' ),
			parentFullName:    $this->requireText( $_POST['parent_full_name'] ?? '' ),
			parentBirthDate:   $this->requireText( $_POST['parent_birth_date'] ?? '' ),
			relationType:      $this->requireKey( $_POST['relation_type'] ?? '' ),
			docType:           $this->requireKey( $_POST['doc_type'] ?? '' ),
			docNumber:         $this->requireText( $_POST['doc_number'] ?? '' ),
			docIssuedBy:       $this->sanitizeText( $_POST['doc_issued_by'] ?? '' ),
			docIssuedDate:     $this->sanitizeText( $_POST['doc_issued_date'] ?? '' ),
			inn:               $this->sanitizeText( $_POST['inn'] ?? '' ),
			snils:             $this->sanitizeText( $_POST['snils'] ?? '' ),
			address:           $this->sanitizeText( $_POST['address'] ?? '' ),
			phone:             $this->sanitizeText( $_POST['phone'] ?? '' ),
			email:             $this->requireText( $_POST['email'] ?? '' ),
			studentFullName:   $this->requireText( $_POST['student_full_name'] ?? '' ),
			studentBirthDate:  $this->requireText( $_POST['student_birth_date'] ?? '' ),
			studentDocType:    $this->requireKey( $_POST['student_doc_type'] ?? '' ),
			studentDocNumber:  $this->requireText( $_POST['student_doc_number'] ?? '' ),
			studentInn:        $this->sanitizeText( $_POST['student_inn'] ?? '' ),
		);

		try {
			$this->applicationService->submitParentData( $dto );
		} catch ( \DomainException $e ) {
			$this->error( $e->getMessage() );
		}

		$this->success( array( 'message' => 'Заявка принята к рассмотрению.' ) );
	}
}