<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Enrollment;

use Inc\Contracts\LogEventDispatcherInterface;
use Inc\Core\BaseController;
use Inc\DTO\Application\ApplicationInputDTO;
use Inc\DTO\Enrollment\StudentDataDTO;
use Inc\DTO\Log\Events\ApplicationStatusEvent;
use Inc\DTO\Person\ParentSubmissionInputDTO;
use Inc\Enums\ApplicationStatus;
use Inc\Enums\AuditAction;
use Inc\Enums\AuthAction;
use Inc\Enums\AuthResult;
use Inc\Enums\LogEvent;
use Inc\Enums\Nonce;
use Inc\Repositories\OptionsRepositories\ConsentDefinitionsRepository;
use Inc\Repositories\WPDBRepositories\ApplicationRepository;
use Inc\Repositories\WPDBRepositories\PersonDocumentsRepository;
use Inc\Services\Application\ApplicationService;
use Inc\Services\Application\JoinCodeService;
use Inc\Services\Captcha\CaptchaService;
use Inc\Services\Email\EmailOtpService;
use Inc\Services\Log\AuthLogWriter;
use Inc\Services\Security\FormGuardService;
use Inc\Services\Security\PiiCryptoService;
use Inc\Services\Security\RateLimitService;
use Inc\Services\Shared\PluginConfig;
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
	 * @param LogEventDispatcherInterface $logEvents        Диспетчер событий логирования
	 */
	public function __construct(
		private readonly ApplicationService           $applicationService,
		private readonly EmailOtpService              $emailOtpService,
		private readonly CaptchaService               $captchaService,
		private readonly RateLimitService             $rateLimitService,
		private readonly JoinCodeService              $joinCodeService,
		private readonly ApplicationRepository        $applicationRepository,
		private readonly PiiCryptoService             $crypto,
		private readonly LogEventDispatcherInterface  $logEvents,
		private readonly ConsentDefinitionsRepository $consentDefinitions,
		private readonly AuthLogWriter                $authLog,
		private readonly PluginConfig                 $pluginConfig,
		private readonly FormGuardService             $formGuard,
		private readonly PersonDocumentsRepository    $personDocumentsRepository,
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
		if ( $this->pluginConfig->isTestEnv() && '000' === $code ) {
			set_query_var( 'fs_lms_student_data', StudentDataDTO::fromArray( array(
				'full_name'  => 'Тестов Тест Тестович',
				'birth_date' => '2010-05-15',
				'school'     => 'Тестовая школа №1',
				'grade'      => 7,
				'email'      => 'test-student@example.com',
				'phone'      => '+78005553535',
			) ) );
			set_query_var( 'fs_lms_join_code',     $code );
			set_query_var( 'fs_lms_app_id',        0 );
			set_query_var( 'fs_lms_consent_url',   $this->resolveConsentUrl( 'pd_processing' ) );
			set_query_var( 'fs_lms_parent_data',   null );
			set_query_var( 'fs_lms_parent_locked', false );
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
			$decoded = json_decode( $this->crypto->decrypt( $app->studentDataEnc ), true ) ?? array();

			// Для восстановленных заявок (привязан существующий ученик) накладываем
			// актуальные PII из person_documents поверх снапшота: снапшот мог быть
			// сделан до того, как админ дозаполнил данные (например, паспорт).
			if ( null !== $app->studentPersonId ) {
				$decoded = $this->overlayLiveStudentDocs( $app->studentPersonId, $decoded );
			}

			$studentData = StudentDataDTO::fromArray( $decoded );
		} catch ( \Throwable $e ) {
			return false;
		}

		// Логируем факт просмотра ссылки
		$this->logEvents->dispatch(
			LogEvent::ApplicationViewed,
			new ApplicationStatusEvent( 0, AuditAction::ViewJoinLink, $app->id )
		);

		// Если родитель уже назначен — расшифровываем его данные для предзаполнения формы
		$parentData   = null;
		$parentLocked = false;
		if ( $app->parentPersonId !== null && ! empty( $app->parentDataEnc ) ) {
			try {
				$parentData   = json_decode( $this->crypto->decrypt( $app->parentDataEnc ), true ) ?? array();
				$parentLocked = true;
			} catch ( \Throwable ) {
				$parentData = null;
			}
		}

		// Передаём данные в шаблон через query vars
		set_query_var( 'fs_lms_student_data',  $studentData );
		set_query_var( 'fs_lms_join_code',     $code );
		set_query_var( 'fs_lms_app_id',        $app->id );
		set_query_var( 'fs_lms_consent_url',   $this->resolveConsentUrl( 'pd_processing' ) );
		set_query_var( 'fs_lms_parent_data',   $parentData );
		set_query_var( 'fs_lms_parent_locked', $parentLocked );

		return true;
	}

	/**
	 * Накладывает актуальные PII ученика из person_documents поверх данных снапшота заявки.
	 *
	 * Снапшот (studentDataEnc) фиксируется в момент восстановления и может устареть,
	 * если админ дозаполнил документы позже. Для восстановленных заявок берём живые
	 * значения; отсутствующие/нерасшифровываемые поля оставляем как в снапшоте.
	 *
	 * @param int                  $personId ID ученика
	 * @param array<string, mixed> $data     Данные из снапшота заявки
	 *
	 * @return array<string, mixed>
	 */
	private function overlayLiveStudentDocs( int $personId, array $data ): array {
		$docs = $this->personDocumentsRepository->findByPersonId( $personId );
		if ( null === $docs ) {
			return $data;
		}

		if ( $docs->docType ) {
			$data['doc_type'] = $docs->docType;
		}

		foreach ( array(
			'email'      => $docs->emailEnc,
			'phone'      => $docs->phoneEnc,
			'doc_number' => $docs->docNumberEnc,
			'inn'        => $docs->innEnc,
		) as $key => $enc ) {
			if ( ! $enc ) {
				continue;
			}
			try {
				$data[ $key ] = $this->crypto->decrypt( $enc );
			} catch ( \Throwable ) {
				// Поле не расшифровалось — оставляем значение из снапшота.
			}
		}

		return $data;
	}

	/**
	 * Возвращает URL текущей версии согласия для отображения в форме.
	 * Если страница не опубликована — возвращает пустую строку.
	 */
	private function resolveConsentUrl( string $typeKey ): string {
		$def    = $this->consentDefinitions->findByKey( $typeKey );
		$pageId = (int) ( $def['page_id'] ?? 0 );
		if ( $pageId <= 0 ) {
			return '';
		}

		$url = get_permalink( $pageId );
		return $url ?: '';
	}

	/**
	 * Шаг A: проверяет капчу, отправляет OTP-код на email.
	 *
	 * @return void
	 */
	public function ajaxSendOtpCode(): void {
		Nonce::Apply->verify();

		$ip = (string) ( $_SERVER['REMOTE_ADDR'] ?? '' );

		// Дешёвая бот-защита: honeypot + тайминг формы — до траты бюджета на капчу/письма.
		$honeypot   = $this->sanitizeText( $this->formGuard->honeypotField() );
		$formToken  = $this->sanitizeText( 'form_token' );
		if ( ! $this->formGuard->isHuman( $honeypot, $formToken ) ) {
			$this->error( 'Не удалось подтвердить отправку формы. Обновите страницу и попробуйте снова.' );
		}

		// Ограничение частоты запросов по IP
		if ( ! $this->rateLimitService->allowApplicationCreation( $ip ) ) {
			$this->error( 'Слишком много запросов. Попробуйте позже.' );
		}

		// Капча пропускается только в тестовом окружении
		if ( ! $this->pluginConfig->isTestEnv() ) {
			$captchaToken = $this->sanitizeText( 'captcha_token' );
			if ( ! $this->captchaService->validate( $captchaToken, $ip ) ) {
				$this->error( 'Проверка капчи не пройдена.' );
			}
		}

		$email = $this->sanitizeText( 'email' );

		// Ограничение отправок OTP на один адрес (анти-бомбинг, окно — сутки)
		if ( ! $this->rateLimitService->allowOtpSendForEmail( $email ) ) {
			$this->error( 'Слишком много отправок кода на эту почту. Попробуйте завтра.' );
		}

		// Проверка возможности повторной отправки
		if ( ! $this->emailOtpService->canResend( $email ) ) {
			$this->error( 'Повторная отправка возможна через 60 секунд.' );
		}

		// Отправка OTP-кода
		$this->emailOtpService->sendCode( $email );
		$this->authLog->record( $email, AuthAction::OtpSent, AuthResult::Success );

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
		$username   = $this->requireText( 'username' );
		$password   = $this->requireText( 'password' );
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
			username:        $username,
			password:        $password,
		);

		try {
			$result = $this->applicationService->createApplication( $dto );
		} catch ( \DomainException $e ) {
			$this->error( $e->getMessage() );
		} catch ( \RuntimeException $e ) {
			$this->error( $e->getMessage() );
		}

		$this->authLog->record( $email, AuthAction::OtpVerified, AuthResult::Success );
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

		// Проверяем, назначен ли родитель заранее (поля родителя тогда не обязательны)
		$joinCode     = $this->requireText( 'join_code' );
		$parentLocked = false;
		$app          = $this->applicationRepository->findByJoinCodeHash( $this->joinCodeService->hash( $joinCode ) );
		if ( $app !== null && $app->parentPersonId !== null ) {
			$parentLocked = true;
		}

		// Сбор данных формы родителя
		$dto = new ParentSubmissionInputDTO(
			joinCode:           $joinCode,
			parentLastName:     $parentLocked ? $this->sanitizeText( 'parent_last_name' ) : $this->requireText( 'parent_last_name' ),
			parentFirstName:    $parentLocked ? $this->sanitizeText( 'parent_first_name' ) : $this->requireText( 'parent_first_name' ),
			parentMiddleName:   $this->sanitizeText( 'parent_middle_name' ),
			parentBirthDate:    $parentLocked ? $this->sanitizeText( 'parent_birth_date' ) : $this->requireText( 'parent_birth_date' ),
			docType:            $parentLocked ? $this->sanitizeKey( 'doc_type' ) : $this->requireKey( 'doc_type' ),
			docNumber:          $parentLocked ? $this->sanitizeText( 'doc_number' ) : $this->requireText( 'doc_number' ),
			docIssuedBy:        $this->sanitizeText( 'doc_issued_by' ),
			docIssuedDate:      $this->sanitizeText( 'doc_issued_date' ),
			inn:                $this->sanitizeText( 'inn' ),
			address:            $this->sanitizeText( 'address' ),
			phone:              $this->sanitizeText( 'phone' ),
			email:              $parentLocked ? $this->sanitizeText( 'email' ) : $this->requireText( 'email' ),
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

	public function ajaxCheckUsernameAvailable(): void {
		Nonce::CheckUsernameAvailable->verify();

		$username = $this->sanitizeText( 'username' );

		if ( '' === $username ) {
			$this->error( 'Логин не указан.' );
		}

		$this->success( array( 'available' => ! username_exists( $username ) ) );
	}

	public function ajaxCheckEmailAvailable(): void {
		Nonce::CheckEmailAvailable->verify();

		$email = $this->sanitizeEmail( 'email' );

		if ( '' === $email ) {
			$this->error( 'Email не указан.' );
		}

		$this->success( array( 'available' => ! email_exists( $email ) ) );
	}
}