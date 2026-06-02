<?php

declare( strict_types=1 );

namespace Inc\Callbacks;

use Inc\Core\BaseController;
use Inc\Enums\AuditAction;
use Inc\Enums\ApplicationStatus;
use Inc\Enums\Nonce;
use Inc\Repositories\WPDBRepositories\ApplicationRepository;
use Inc\Services\Application\ApplicationService;
use Inc\Services\Application\JoinCodeService;
use Inc\Services\AuditService;
use Inc\Services\CaptchaService;
use Inc\Services\EmailOtpService;
use Inc\Services\PiiCryptoService;
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
 *
 * ### Основные обязанности:
 *
 * 1. **Подготовка JOIN-страницы** — валидация кода, расшифровка данных ученика.
 * 2. **Шаг A (OTP)** — отправка одноразового кода на email.
 * 3. **Шаг B (создание заявки)** — верификация OTP и создание заявки в БД.
 * 4. **Отправка данных родителя** — заполнение анкеты по JOIN-ссылке.
 *
 * ### Архитектурная роль:
 *
 * Делегирует бизнес-логику ApplicationService, EmailOtpService, CaptchaService и др.
 * Реализует двухэтапный процесс создания заявки с OTP-подтверждением email.
 */
class ApplicationCallbacks extends BaseController {

	use Sanitizer;

	/**
	 * Конструктор коллбеков.
	 *
	 * @param ApplicationService   $applicationService    Сервис работы с заявками
	 * @param EmailOtpService      $emailOtpService       Сервис OTP-кодов
	 * @param CaptchaService       $captchaService        Сервис капчи
	 * @param RateLimitService     $rateLimitService      Сервис ограничения запросов
	 * @param JoinCodeService      $joinCodeService       Сервис JOIN-кодов
	 * @param ApplicationRepository $applicationRepository Репозиторий заявок
	 * @param PiiCryptoService     $crypto                Сервис шифрования PII
	 * @param AuditService         $auditService          Сервис аудита
	 */
	public function __construct(
		private readonly ApplicationService  $applicationService,
		private readonly EmailOtpService     $emailOtpService,
		private readonly CaptchaService      $captchaService,
		private readonly RateLimitService    $rateLimitService,
		private readonly JoinCodeService     $joinCodeService,
		private readonly ApplicationRepository $applicationRepository,
		private readonly PiiCryptoService    $crypto,
		private readonly AuditService        $auditService,
	) {
		parent::__construct();
	}

	/**
	 * Валидирует JOIN-код, расшифровывает данные ученика и передаёт их
	 * в шаблон через set_query_var. Возвращает false, если нужно отдать 404.
	 *
	 * @return bool
	 */
	public function prepareJoinPage(): bool {
		$ip   = (string) ( $_SERVER['REMOTE_ADDR'] ?? '' );
		// get_query_var() — получает кастомный параметр из URL
		$code = get_query_var( 'fs_lms_join_code', '' );

		// Тестовый дебаг-режим: /lms/join/000 → тестовые данные без БД
		if ( defined( 'FS_LMS_TEST_ENV' ) && '000' === $code ) {
			set_query_var( 'fs_lms_student_data', array(
				'full_name'  => 'Тестов Тест Тестович',
				'birth_date' => '2010-05-15',
				'school'     => 'Тестовая школа №1',
				'grade'      => 7,
				'email'      => 'test-student@example.com',
				'phone'      => '+78005553535',
			) );
			set_query_var( 'fs_lms_join_code', $code );
			set_query_var( 'fs_lms_app_id',    0 );
			return true;
		}

		// Проверка формата JOIN-кода
		if ( '' === $code || ! $this->joinCodeService->isValidFormat( $code ) ) {
			return false;
		}

		// Проверка лимита попыток ввода
		if ( ! $this->rateLimitService->allowJoinAttempt( $ip ) ) {
			return false;
		}

		$hash = $this->joinCodeService->hash( $code );
		$app  = $this->applicationRepository->findByJoinCodeHash( $hash );

		// Заявка должна существовать и находиться в статусе ожидания родителя
		if ( null === $app || ApplicationStatus::PendingParent !== $app->status ) {
			return false;
		}

		try {
			// Расшифровка и декодирование данных ученика
			$studentData = json_decode( $this->crypto->decrypt( $app->studentDataEnc ), true );
		} catch ( \Throwable $e ) {
			return false;
		}

		// Логируем факт просмотра ссылки
		$this->auditService->recordAnonymous(
			AuditAction::ViewJoinLink->value,
			'application',
			$app->id
		);

		// Передаём данные в шаблон через query vars
		set_query_var( 'fs_lms_student_data', $studentData );
		set_query_var( 'fs_lms_join_code',    $code );
		set_query_var( 'fs_lms_app_id',       $app->id );

		return true;
	}

	/**
	 * Шаг A: проверяет капчу, отправляет OTP-код на email.
	 *
	 * @return void
	 */
	public function ajaxSendOtpCode(): void {
		Nonce::Apply->verify();

		$ip = (string) ( $_SERVER['REMOTE_ADDR'] ?? '' );

		// Ограничение частоты запросов
		if ( ! $this->rateLimitService->allowApplicationCreation( $ip ) ) {
			$this->error( 'Слишком много запросов. Попробуйте позже.' );
		}

		// Капча пропускается только в тестовом окружении (FS_LMS_TEST_ENV в wp-config.php)
		if ( ! defined( 'FS_LMS_TEST_ENV' ) ) {
			$captchaToken = $this->sanitizeText( 'captcha_token' );
			if ( ! $this->captchaService->validate( $captchaToken, $ip ) ) {
				$this->error( 'Проверка капчи не пройдена.' );
			}
		}

		$email = $this->sanitizeText( 'email' );

		// Проверка возможности повторной отправки
		if ( ! $this->emailOtpService->canResend( $email ) ) {
			$this->error( 'Повторная отправка возможна через 60 секунд.' );
		}

		// Отправка OTP-кода
		$this->emailOtpService->sendCode( $email );

		// Маскирование email для отображения в интерфейсе
		$masked = (string) preg_replace( '/(?<=.).(?=[^@]*@)/', '*', $email );
		$this->success( array( 'masked_email' => $masked ) );
	}

	/**
	 * Шаг B: верифицирует OTP, создаёт заявку.
	 *
	 * @return void
	 */
	public function ajaxCreateApplication(): void {
		Nonce::VerifyOtp->verify();

		$ip = (string) ( $_SERVER['REMOTE_ADDR'] ?? '' );

		if ( ! $this->rateLimitService->allowApplicationCreation( $ip ) ) {
			$this->error( 'Слишком много запросов. Попробуйте позже.' );
		}

		// Сбор и валидация данных формы
		$lastName   = $this->requireText( 'last_name' );
		$firstName  = $this->requireText( 'first_name' );
		$middleName = $this->sanitizeText( 'middle_name' );
		$email      = $this->requireText( 'email' );
		$phone      = $this->requireText( 'phone' );
		$school     = $this->sanitizeText( 'school' );
		$grade      = $this->sanitizeInt( 'grade' );
		$birthDate  = $this->requireText( 'birth_date' );
		$otpCode    = $this->requireText( 'otp_code' );
		$ua         = (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' );

		$dto = new ApplicationInputDTO(
			lastName:        $lastName,
			firstName:       $firstName,
			middleName:      $middleName,
			email:           $email,
			phone:           $phone,
			school:          $school,
			grade:           $grade,
			birthDate:       $birthDate,
			otpCode:         $otpCode,
			ip:              $ip,
			userAgent:       $ua,
		);

		try {
			$result = $this->applicationService->createApplication( $dto );
		} catch ( \DomainException $e ) {
			// Ошибка валидации бизнес-правил
			$this->error( $e->getMessage() );
		} catch ( \RuntimeException $e ) {
			$this->error( $e->getMessage() );
		}

		$this->success( array(
			'join_url'   => $result->joinUrl,
			'expires_at' => $result->expiresAt,
		) );
	}

	/**
	 * Родитель отправляет свои данные по JOIN-ссылке.
	 *
	 * @return void
	 */
	public function ajaxSubmitParentData(): void {
		Nonce::ParentSubmit->verify();

		$ip = (string) ( $_SERVER['REMOTE_ADDR'] ?? '' );

		if ( ! $this->rateLimitService->allowParentSubmit( $ip ) ) {
			$this->error( 'Слишком много запросов. Попробуйте позже.' );
		}

		// Сбор данных формы родителя
		$dto = new ParentSubmissionInputDTO(
			joinCode:           $this->requireText( 'join_code' ),
			parentLastName:     $this->requireText( 'parent_last_name' ),
			parentFirstName:    $this->requireText( 'parent_first_name' ),
			parentMiddleName:   $this->sanitizeText( 'parent_middle_name' ),
			parentBirthDate:    $this->requireText( 'parent_birth_date' ),
			relationType:       $this->requireKey( 'relation_type' ),
			docType:            $this->requireKey( 'doc_type' ),
			docNumber:          $this->requireText( 'doc_number' ),
			docIssuedBy:        $this->sanitizeText( 'doc_issued_by' ),
			docIssuedDate:      $this->sanitizeText( 'doc_issued_date' ),
			inn:                $this->sanitizeText( 'inn' ),
			address:            $this->sanitizeText( 'address' ),
			phone:              $this->sanitizeText( 'phone' ),
			email:              $this->requireText( 'email' ),
			studentLastName:    $this->requireText( 'student_last_name' ),
			studentFirstName:   $this->requireText( 'student_first_name' ),
			studentMiddleName:  $this->sanitizeText( 'student_middle_name' ),
			studentBirthDate:   $this->requireText( 'student_birth_date' ),
			studentDocType:     $this->requireKey( 'student_doc_type' ),
			studentDocNumber:   $this->requireText( 'student_doc_number' ),
			studentInn:         $this->sanitizeText( 'student_inn' ),
		);

		try {
			$this->applicationService->submitParentData( $dto );
		} catch ( \DomainException $e ) {
			$this->error( $e->getMessage() );
		}

		$this->success( array( 'message' => 'Заявка принята к рассмотрению.' ) );
	}
}