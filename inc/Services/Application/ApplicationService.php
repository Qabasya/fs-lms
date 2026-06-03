<?php

declare( strict_types=1 );

namespace Inc\Services\Application;

use DomainException;
use Inc\DTO\ApplicationCreatedDTO;
use Inc\DTO\ApplicationInputDTO;
use Inc\DTO\ParentDataDTO;
use Inc\DTO\ParentSubmissionInputDTO;
use Inc\DTO\StudentDataDTO;
use Inc\Enums\ApplicationStatus;
use Inc\Enums\AuditAction;
use Inc\Enums\ConsentType;
use Inc\Repositories\WPDBRepositories\ApplicationRepository;
use Inc\Services\AuditService;
use Inc\Services\ConsentService;
use Inc\Services\EmailOtpService;
use Inc\Services\PiiCryptoService;
use Inc\Shared\Traits\RequestContextProvider;
use Inc\Shared\Traits\TransactionRunner;
use RuntimeException;

/**
 * Class ApplicationService
 *
 * Сервис для управления заявками на обучение (двухэтапный OTP-флоу).
 *
 * @package Inc\Services
 *
 * ### Основные обязанности:
 *
 * 1. **Создание заявки** — процесс создания заявки учеником (OTP-верификация, генерация JOIN-кода).
 * 2. **Заполнение данных родителем** — обработка формы присоединения родителя по JOIN-ссылке.
 * 3. **Истечение просроченных заявок** — автоматический перевод в статус Expired.
 *
 * ### Архитектурная роль:
 *
 * Делегирует работу с БД ApplicationRepository, а вспомогательные операции —
 * JoinCodeService, PiiCryptoService, ConsentService, AuditService, EmailOtpService.
 * Использует трейты TransactionRunner (обёртка над $wpdb->query('START TRANSACTION'))
 * и RequestContextProvider (получение IP, User-Agent, actor ID).
 */
readonly class ApplicationService {

	use TransactionRunner;        // Трейт с методом inTransaction() для атомарных операций
	use RequestContextProvider;   // Трейт с методом requestContext() для получения IP/UA

	/**
	 * Конструктор сервиса.
	 *
	 * @param ApplicationRepository $applicationRepository Репозиторий заявок
	 * @param JoinCodeService       $joinCodeService       Сервис JOIN-кодов
	 * @param PiiCryptoService      $crypto                Сервис шифрования PII
	 * @param ConsentService        $consentService        Сервис согласий
	 * @param AuditService          $auditService          Сервис аудита
	 * @param EmailOtpService       $emailOtpService       Сервис OTP-кодов
	 */
	public function __construct(
		private ApplicationRepository $applicationRepository,
		private JoinCodeService       $joinCodeService,
		private PiiCryptoService      $crypto,
		private ConsentService        $consentService,
		private AuditService          $auditService,
		private EmailOtpService       $emailOtpService,
	) {}

	/**
	 * Создаёт новую заявку (шаг B — после верификации OTP).
	 *
	 * @param ApplicationInputDTO $input Данные формы
	 *
	 * @throws RuntimeException   Если OTP-код неверен или истёк
	 * @throws DomainException    Если уже есть незавершённая заявка с таким email
	 *
	 * @return ApplicationCreatedDTO
	 */
	public function createApplication( ApplicationInputDTO $input ): ApplicationCreatedDTO {
		// verify() — проверка OTP-кода
		if ( ! $this->emailOtpService->verify( $input->email, $input->otpCode ) ) {
			throw new RuntimeException( 'Неверный или истёкший код подтверждения.' );
		}

		// Хэш email для поиска существующих заявок (без расшифровки)
		$emailHash = $this->crypto->hash( $input->email );

		if ( null !== $this->applicationRepository->findActiveByEmail( $emailHash ) ) {
			throw new DomainException( 'Незавершённая заявка уже существует.' );
		}

		// Генерация JOIN-кода и срока его действия
		$joinCode      = $this->joinCodeService->generate();
		$joinCodeHash  = $this->joinCodeService->hash( $joinCode );
		$joinCodeEnc   = $this->crypto->encrypt( $joinCode );
		$expiresAt     = gmdate( 'Y-m-d H:i:s', time() + 14 * DAY_IN_SECONDS );

		// Шифрование данных ученика
		$studentDataEnc = $this->crypto->encrypt( (string) wp_json_encode( array(
			'last_name'   => $input->lastName,
			'first_name'  => $input->firstName,
			'middle_name' => $input->middleName,
			'full_name'   => $input->fullName(),
			'email'       => $input->email,
			'phone'       => $input->phone,
			'school'      => $input->school,
			'grade'       => $input->grade,
			'birth_date'  => $input->birthDate,
		) ) );

		// inTransaction() — атомарное выполнение блока операций
		$ctx = $this->requestContext();

		$appId = $this->inTransaction( function () use ( $emailHash, $joinCodeHash, $joinCodeEnc, $expiresAt, $studentDataEnc, $ctx, $input ): int {
			// Создание записи заявки
			$id = $this->applicationRepository->create( array(
				'status'               => ApplicationStatus::PendingParent->value,
				'join_code_hash'       => $joinCodeHash,
				'join_code_enc'        => $joinCodeEnc,
				'join_code_expires_at' => $expiresAt,
				'student_email_hash'   => $emailHash,
				'student_data_enc'     => $studentDataEnc,
				'parent_submitted_ip'  => $ctx->ip,
				'created_at'           => current_time( 'mysql', true ),
				'updated_at'           => current_time( 'mysql', true ),
			) );

			// Фиксация согласия на обработку ПД (сам ученик)
			$this->consentService->recordSelfConsent( $id, ConsentType::PdProcessing, $ctx );

			// Логирование события в аудит
			$this->auditService->recordAnonymous(
				AuditAction::CreateApplication->value,
				'application',
				$id,
				array( 'email_hash' => $emailHash )
			);

			return $id;
		} );

		// Формирование URL для присоединения родителя
		$joinUrl = home_url( '/lms/join/' . $joinCode );

		return new ApplicationCreatedDTO( $appId, $joinUrl, $expiresAt );
	}

	/**
	 * Обрабатывает заполнение данных родителя по JOIN-ссылке.
	 *
	 * @param ParentSubmissionInputDTO $input Данные формы родителя
	 *
	 * @throws DomainException Если заявка не найдена или недоступна
	 *
	 * @return void
	 */
	public function submitParentData( ParentSubmissionInputDTO $input ): void {
		$codeHash = $this->joinCodeService->hash( $input->joinCode );
		$app      = $this->applicationRepository->findByJoinCodeHash( $codeHash );

		// Заявка должна существовать и быть в статусе ожидания родителя
		if ( null === $app || ApplicationStatus::PendingParent !== $app->status ) {
			throw new DomainException( 'Заявка не найдена или недоступна.' );
		}

		$ctx = $this->requestContext();

		// Шифрование данных родителя
		$parentDto = new ParentDataDTO(
			lastName:      $input->parentLastName,
			firstName:     $input->parentFirstName,
			middleName:    $input->parentMiddleName,
			birthDate:     $input->parentBirthDate,
			relationType:  $input->relationType,
			docType:       $input->docType,
			docNumber:     $input->docNumber,
			docIssuedBy:   $input->docIssuedBy,
			docIssuedDate: $input->docIssuedDate,
			inn:           $input->inn,
			address:       $input->address,
			phone:         $input->phone,
			email:         $input->email,
		);
		$parentDataEnc = $this->crypto->encrypt( (string) wp_json_encode( $parentDto->toArray() ) );

		// Слияние с исходными данными ученика (email, phone, school, grade сохраняются)
		$existingStudentDto = new StudentDataDTO( '', '', '', '', '', '', 0, '', '', '', '' );
		if ( ! empty( $app->studentDataEnc ) ) {
			try {
				$existingStudentDto = StudentDataDTO::fromArray(
					json_decode( $this->crypto->decrypt( $app->studentDataEnc ), true ) ?? array()
				);
			} catch ( \Throwable ) {
				// Оставляем пустой DTO
			}
		}

		$updatedStudentDto = new StudentDataDTO(
			lastName:   $input->studentLastName,
			firstName:  $input->studentFirstName,
			middleName: $input->studentMiddleName,
			email:      $existingStudentDto->email,
			phone:      $existingStudentDto->phone,
			school:     $existingStudentDto->school,
			grade:      $existingStudentDto->grade,
			birthDate:  $input->studentBirthDate,
			docType:    $input->studentDocType,
			docNumber:  $input->studentDocNumber,
			inn:        $input->studentInn,
		);
		$studentDataEnc = $this->crypto->encrypt( (string) wp_json_encode( $updatedStudentDto->toArray() ) );

		$appId = $app->id;

		$this->inTransaction( function () use ( $appId, $parentDataEnc, $studentDataEnc, $ctx ): void {
			// Обновление заявки: переход в статус ReadyForReview
			$this->applicationRepository->update( $appId, array(
				'status'              => ApplicationStatus::ReadyForReview->value,
				'parent_data_enc'     => $parentDataEnc,
				'student_data_enc'    => $studentDataEnc,
				'parent_submitted_ip' => $ctx->ip,
				'parent_submitted_ua' => $ctx->userAgent,
				'updated_at'          => current_time( 'mysql', true ),
			) );

			// Фиксация согласия родителя на обработку ПД ребёнка
			$this->consentService->recordGuardianConsent( $appId, ConsentType::PdChildProcessing, 0, $ctx );

			// Логирование события
			$this->auditService->recordAnonymous(
				AuditAction::SubmitParentData->value,
				'application',
				$appId
			);
		} );

		// Триггер для внешних действий (например, уведомление администратора)
		do_action( 'fs_lms_application_ready', $appId );
	}

	/**
	 * Переводит просроченные заявки в статус Expired.
	 *
	 * @return int Количество обработанных заявок
	 */
	public function expireStale(): int {
		$apps  = $this->applicationRepository->findExpiredPending();
		$count = 0;

		foreach ( $apps as $app ) {
			$this->applicationRepository->setStatus( $app->id, ApplicationStatus::Expired );

			$this->auditService->recordAnonymous(
				AuditAction::ExpireApplication->value,
				'application',
				$app->id
			);

			$count++;
		}

		return $count;
	}
}