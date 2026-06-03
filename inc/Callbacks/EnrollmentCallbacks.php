<?php

declare( strict_types=1 );

namespace Inc\Callbacks;

use Inc\Core\BaseController;
use Inc\Enums\ApplicationStatus;
use Inc\Enums\AuditAction;
use Inc\Enums\Capability;
use Inc\Enums\Nonce;
use Inc\Repositories\WPDBRepositories\ApplicationRepository;
use Inc\Repositories\OptionsRepositories\StudentGroupRepository;
use Inc\Services\AuditService;
use Inc\Services\Enrollment\EnrollmentService;
use Inc\Services\PasswordGeneratorService;
use Inc\Services\PiiCryptoService;
use Inc\Services\Person\PiiMaskingService;
use Inc\Repositories\WPDBRepositories\PiiAccessLogRepository;
use Inc\DTO\EnrollmentInputDTO;
use Inc\DTO\ParentDataDTO;
use Inc\DTO\PiiAccessLogInputDTO;
use Inc\DTO\StudentDataDTO;
use Inc\DTO\StudentGroupDTO;
use Inc\Enums\DocumentType;
use Inc\Enums\RelationType;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;

/**
 * Class EnrollmentCallbacks
 *
 * Коллбеки для страниц заявок и операций зачисления в административной панели.
 *
 * @package Inc\Callbacks
 *
 * ### Основные обязанности:
 *
 * 1. **Отображение списка заявок** — рендеринг таблицы с фильтрами и пагинацией.
 * 2. **Просмотр карточки заявки** — отображение деталей заявки с PII (с логированием доступа).
 * 3. **Зачисление студента** — обработка AJAX-запроса на создание зачисления.
 * 4. **Корзина заявок** — перемещение в корзину, восстановление, очистка.
 *
 * ### Архитектурная роль:
 *
 * Делегирует бизнес-логику ApplicationRepository, EnrollmentService, AuditService.
 * Управляет отображением страниц и AJAX-операциями в админ-панели.
 */
class EnrollmentCallbacks extends BaseController {

	use Authorizer;
	use Sanitizer;

	/**
	 * Конструктор коллбеков.
	 *
	 * @param ApplicationRepository   $applicationRepository Репозиторий заявок
	 * @param EnrollmentService       $enrollmentService     Сервис зачисления
	 * @param AuditService            $auditService          Сервис аудита
	 * @param PiiCryptoService        $crypto                Сервис шифрования PII
	 * @param PiiMaskingService       $piiMasking            Сервис маскирования PII
	 * @param PiiAccessLogRepository  $piiAccessLog          Репозиторий логов доступа к PII
	 */
	public function __construct(
		private readonly ApplicationRepository   $applicationRepository,
		private readonly EnrollmentService       $enrollmentService,
		private readonly AuditService            $auditService,
		private readonly PiiCryptoService        $crypto,
		private readonly PiiMaskingService       $piiMasking,
		private readonly PiiAccessLogRepository  $piiAccessLog,
		private readonly StudentGroupRepository  $studentGroupRepository,
		private readonly PasswordGeneratorService $passwordGenerator,
	) {
		parent::__construct();
	}

	/**
	 * Таб "Заявки" страницы "Пользователи" (?page=fs_lms_userlist&tab=tab-1)
	 *
	 * @return void
	 */
	public function renderApplicationsListPage(): void {
		// Проверка прав доступа
		if ( ! current_user_can( Capability::ManageApplications->value ) ) {
			wp_die( 'Доступ запрещён.' );
		}

		// Получение и санитизация параметров фильтрации
		$status   = sanitize_key( $_GET['status'] ?? '' );
		$dateFrom = sanitize_text_field( $_GET['date_from'] ?? '' );
		$dateTo   = sanitize_text_field( $_GET['date_to'] ?? '' );
		$page     = max( 1, (int) ( $_GET['paged'] ?? 1 ) );

		$filters = array_filter( array(
			'status'    => $status ?: null,
			'date_from' => $dateFrom ?: null,
			'date_to'   => $dateTo ?: null,
		) );

		$perPage = 20;
		$apps    = $this->applicationRepository->list( $filters, $page, $perPage );
		$total   = $this->applicationRepository->count( $filters );

		// Подключение шаблона списка заявок
		$template = $this->path( 'templates/admin/enrollment/applications-list.php' );

		if ( file_exists( $template ) ) {
			require $template;
		} else {
			echo '<div class="wrap"><h1>Заявки</h1><p>Шаблон не найден.</p></div>';
		}
	}

	/**
	 * Страница карточки заявки (?page=fs-lms-application-detail&id=N)
	 *
	 * @return void
	 */
	public function renderApplicationDetailPage(): void {
		if ( ! current_user_can( Capability::ManageApplications->value ) ) {
			wp_die( 'Доступ запрещён.' );
		}

		$id  = (int) ( $_GET['id'] ?? 0 );
		$app = $this->applicationRepository->find( $id );

		if ( null === $app ) {
			wp_die( 'Заявка не найдена.' );
		}

		$studentData = null;
		$parentData  = null;

		// Расшифровка данных студента (если есть права ViewPII)
		if ( ! empty( $app->studentDataEnc ) && current_user_can( Capability::ViewPII->value ) ) {
			try {
				$studentData = StudentDataDTO::fromArray(
					json_decode( $this->crypto->decrypt( $app->studentDataEnc ), true ) ?? array()
				);
			} catch ( \Throwable $e ) {
				$studentData = null;
			}

			// Логирование факта доступа к PII
			$this->piiAccessLog->create( new PiiAccessLogInputDTO(
				actorUserId:    get_current_user_id(),
				actorRole:      'admin',
				personId:       null,
				fieldsAccessed: 'student_data',
				accessReason:   'application_review',
				actorIp:        (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ),
				createdAt:      current_time( 'mysql', true ),
			) );
		}

		// Расшифровка данных родителя (если есть права ViewPII)
		if ( ! empty( $app->parentDataEnc ) && current_user_can( Capability::ViewPII->value ) ) {
			try {
				$parentData = ParentDataDTO::fromArray(
					json_decode( $this->crypto->decrypt( $app->parentDataEnc ), true ) ?? array()
				);
			} catch ( \Throwable $e ) {
				$parentData = null;
			}
		}

		// Логирование просмотра заявки
		$this->auditService->record(
			AuditAction::ViewApplication->value,
			'application',
			$id
		);

		// Подключение шаблона детальной карточки
		$template = $this->path( 'templates/admin/enrollment/application-detail.php' );

		if ( file_exists( $template ) ) {
			require $template;
		} else {
			echo '<div class="wrap"><h1>Заявка #' . esc_html( (string) $id ) . '</h1><p>Шаблон не найден.</p></div>';
		}
	}

	/**
	 * AJAX: зачисление студента.
	 *
	 * @return void
	 */
	public function ajaxEnrollStudent(): void {
		$this->authorize( Nonce::Enroll, Capability::EnrollStudent );

		$dto = new EnrollmentInputDTO(
			applicationId: $this->sanitizeInt( 'application_id' ),
			contractNo:    $this->requireText( 'contract_no' ),
			contractDate:  $this->requireText( 'contract_date' ),
			orderNo:       $this->requireText( 'order_no' ),
			orderDate:     $this->requireText( 'order_date' ),
			enrolledAt:    $this->requireText( 'enrolled_at' ),
			subjectKey:    $this->requireKey( 'subject_key' ),
			groupId:       $this->requireText( 'group_id' ),
			periodKey:     $this->requireKey( 'period_key' ),
			sendEmailAuto: true,
		);

		try {
			$result = $this->enrollmentService->enroll( $dto );
		} catch ( \DomainException $e ) {
			$this->error( $e->getMessage() );
		} catch ( \InvalidArgumentException $e ) {
			$this->error( $e->getMessage() );
		}

		if ( $result->partialFailure ) {
			$this->success( array(
				'partial'       => true,
				'enrollment_id' => $result->enrollmentId,
				'message'       => 'Зачисление выполнено. Учётные записи будут созданы автоматически в течение 15 минут. Enrollment ID: ' . $result->enrollmentId,
			) );
		}

		$this->success( array(
			'enrollment_id'    => $result->enrollmentId,
			'student_login'    => $result->studentLogin,
			'student_password' => $result->studentPassword,
			'guardian_login'   => $result->guardianLogin,
			'guardian_password' => $result->guardianPassword,
			'message'          => 'Зачисление выполнено.',
		) );
	}

		/**
	 * AJAX: перемещение заявки в корзину.
	 *
	 * @return void
	 */
	public function ajaxMoveApplicationToTrash(): void {
		$this->authorize( Nonce::TrashApplication, Capability::ManageApplications );

		$id = $this->sanitizeInt( 'application_id' );

		$this->applicationRepository->setStatus( $id, ApplicationStatus::Trash );

		$this->auditService->record(
			AuditAction::MoveToTrash->value,
			'application',
			$id
		);

		$this->success();
	}

	/**
	 * AJAX: восстановление заявки из корзины.
	 *
	 * @return void
	 */
	public function ajaxRestoreApplicationFromTrash(): void {
		$this->authorize( Nonce::TrashApplication, Capability::ManageApplications );

		$id  = $this->sanitizeInt( 'application_id' );
		$app = $this->applicationRepository->find( $id );

		if ( null === $app ) {
			$this->error( 'Заявка не найдена.' );
		}

		// Определение целевого статуса: ReadyForReview (заполнена родителем) или PendingParent
		$target = ! empty( $app->parentDataEnc )
			? ApplicationStatus::ReadyForReview
			: ApplicationStatus::PendingParent;

		$this->applicationRepository->setStatus( $id, $target );

		$this->auditService->record(
			AuditAction::RestoreFromTrash->value,
			'application',
			$id
		);

		$this->success();
	}

	/**
	 * AJAX: постоянное удаление одной заявки (только из корзины).
	 *
	 * @return void
	 */
	public function ajaxDeleteApplication(): void {
		$this->authorize( Nonce::TrashApplication, Capability::ManageApplications );

		$id  = $this->sanitizeInt( 'application_id' );
		$app = $this->applicationRepository->find( $id );

		if ( null === $app || $app->status !== ApplicationStatus::Trash ) {
			$this->error( 'Заявка не найдена или не в корзине.' );
		}

		$this->applicationRepository->delete( $id );

		$this->auditService->record(
			AuditAction::EmptyTrash->value,
			'application',
			$id,
			array( 'deleted_count' => 1 )
		);

		$this->success();
	}

	/**
	 * AJAX: обновление данных заявки администратором.
	 *
	 * @return void
	 */
	public function ajaxUpdateApplicationData(): void {
		$this->authorize( Nonce::EditApplication, Capability::ManageApplications );

		$id  = $this->sanitizeInt( 'application_id' );
		$app = $this->applicationRepository->find( $id );

		if ( null === $app ) {
			$this->error( 'Заявка не найдена.' );
		}

		$existingStudentDto = new StudentDataDTO( '', '', '', '', '', '', 0, '', '', '', '' );
		if ( ! empty( $app->studentDataEnc ) ) {
			try {
				$existingStudentDto = StudentDataDTO::fromArray(
					json_decode( $this->crypto->decrypt( $app->studentDataEnc ), true ) ?? array()
				);
			} catch ( \Throwable $e ) {
				$this->error( 'Ошибка расшифровки данных.' );
			}
		}

		$email = $this->requireText( 'email' );

		$updatedStudentDto = new StudentDataDTO(
			lastName:   $this->requireText( 'last_name' ),
			firstName:  $this->requireText( 'first_name' ),
			middleName: $this->sanitizeText( 'middle_name' ),
			email:      $email,
			phone:      $this->requireText( 'phone' ),
			school:     $this->sanitizeText( 'school' ),
			grade:      $this->sanitizeInt( 'grade' ),
			birthDate:  $this->requireText( 'birth_date' ),
			docType:    $existingStudentDto->docType,
			docNumber:  $existingStudentDto->docNumber,
			inn:        $existingStudentDto->inn,
		);

		try {
			$newStudentDataEnc = $this->crypto->encrypt( (string) wp_json_encode( $updatedStudentDto->toArray() ) );
		} catch ( \Throwable $e ) {
			$this->error( 'Ошибка шифрования данных.' );
		}

		$emailHash = $this->crypto->hash( $email );

		$this->applicationRepository->update( $id, array(
			'student_data_enc'   => $newStudentDataEnc,
			'student_email_hash' => $emailHash,
			'updated_at'         => current_time( 'mysql', true ),
		) );

		$this->auditService->record(
			AuditAction::UpdateApplicationData->value,
			'application',
			$id
		);

		$this->success();
	}

	/**
	 * AJAX: обновление данных заявки в статусе ReadyForReview (ученик + родитель).
	 *
	 * @return void
	 */
	public function ajaxUpdateReviewData(): void {
		$this->authorize( Nonce::ReviewApplication, Capability::ManageApplications );

		$id  = $this->sanitizeInt( 'application_id' );
		$app = $this->applicationRepository->find( $id );

		if ( null === $app || $app->status !== ApplicationStatus::ReadyForReview ) {
			$this->error( 'Заявка не найдена или недоступна.' );
		}

		// Обновление данных ученика
		$existingStudentDto = new StudentDataDTO( '', '', '', '', '', '', 0, '', '', '', '' );
		if ( ! empty( $app->studentDataEnc ) ) {
			try {
				$existingStudentDto = StudentDataDTO::fromArray(
					json_decode( $this->crypto->decrypt( $app->studentDataEnc ), true ) ?? array()
				);
			} catch ( \Throwable $e ) {
				$this->error( 'Ошибка расшифровки данных ученика.' );
			}
		}

		$updatedStudentDto = new StudentDataDTO(
			lastName:   $this->requireText( 'student_last_name' ),
			firstName:  $this->requireText( 'student_first_name' ),
			middleName: $this->sanitizeText( 'student_middle_name' ),
			email:      $existingStudentDto->email,
			phone:      $existingStudentDto->phone,
			school:     $existingStudentDto->school,
			grade:      $existingStudentDto->grade,
			birthDate:  $this->sanitizeText( 'student_birth_date' ),
			docType:    $this->sanitizeText( 'student_doc_type' ),
			docNumber:  $this->sanitizeText( 'student_doc_number' ),
			inn:        $this->sanitizeText( 'student_inn' ),
		);

		// Обновление данных родителя
		$updatedParentDto = new ParentDataDTO(
			lastName:      $this->requireText( 'parent_last_name' ),
			firstName:     $this->requireText( 'parent_first_name' ),
			middleName:    $this->sanitizeText( 'parent_middle_name' ),
			birthDate:     $this->sanitizeText( 'parent_birth_date' ),
			relationType:  $this->sanitizeText( 'relation_type' ),
			docType:       $this->sanitizeText( 'parent_doc_type' ),
			docNumber:     $this->sanitizeText( 'parent_doc_number' ),
			docIssuedBy:   $this->sanitizeText( 'parent_doc_issued_by' ),
			docIssuedDate: $this->sanitizeText( 'parent_doc_issued_date' ),
			inn:           $this->sanitizeText( 'parent_inn' ),
			address:       $this->sanitizeText( 'parent_address' ),
			phone:         $this->sanitizeText( 'parent_phone' ),
			email:         $this->sanitizeText( 'parent_email' ),
		);

		try {
			$newStudentDataEnc = $this->crypto->encrypt( (string) wp_json_encode( $updatedStudentDto->toArray() ) );
			$newParentDataEnc  = $this->crypto->encrypt( (string) wp_json_encode( $updatedParentDto->toArray() ) );
		} catch ( \Throwable $e ) {
			$this->error( 'Ошибка шифрования данных.' );
		}

		$this->applicationRepository->update( $id, array(
			'student_data_enc' => $newStudentDataEnc,
			'parent_data_enc'  => $newParentDataEnc,
			'updated_at'       => current_time( 'mysql', true ),
		) );

		$this->auditService->record(
			AuditAction::UpdateReviewData->value,
			'application',
			$id
		);

		$this->success();
	}

	/**
	 * AJAX: перевод заявки из ReadyForReview в Enrolling.
	 *
	 * @return void
	 */
	public function ajaxStartEnrollment(): void {
		$this->authorize( Nonce::Manager, Capability::ManageApplications );

		$id  = $this->sanitizeInt( 'application_id' );
		$app = $this->applicationRepository->find( $id );

		if ( null === $app || $app->status !== ApplicationStatus::ReadyForReview ) {
			$this->error( 'Заявка не найдена или не в статусе "Готова к зачислению".' );
		}

		$this->applicationRepository->update( $id, array(
			'status'     => ApplicationStatus::Enrolling->value,
			'updated_at' => current_time( 'mysql', true ),
		) );

		$this->auditService->record( AuditAction::StartEnrollment->value, 'application', $id );

		$this->success();
	}

	/**
	 * AJAX: получение расшифрованных данных заявки (ученик + родитель).
	 *
	 * @return void
	 */
	public function ajaxGetApplicationData(): void {
		$this->authorize( Nonce::Manager, Capability::ManageApplications );

		$id  = $this->sanitizeInt( 'application_id' );
		$app = $this->applicationRepository->find( $id );

		if ( null === $app ) {
			$this->error( 'Заявка не найдена.' );
		}

		$student = null;
		$parent  = null;

		if ( ! empty( $app->studentDataEnc ) ) {
			try {
				$studentDto = StudentDataDTO::fromArray(
					json_decode( $this->crypto->decrypt( $app->studentDataEnc ), true ) ?? array()
				);
				$student    = $studentDto->toArray();
				$student['doc_type'] = DocumentType::tryFrom( $studentDto->docType )?->label() ?? $studentDto->docType;
			} catch ( \Throwable $e ) {
				$student = null;
			}
		}

		if ( ! empty( $app->parentDataEnc ) ) {
			try {
				$parentDto = ParentDataDTO::fromArray(
					json_decode( $this->crypto->decrypt( $app->parentDataEnc ), true ) ?? array()
				);
				$parent    = $parentDto->toArray();
				$parent['doc_type']      = DocumentType::tryFrom( $parentDto->docType )?->label() ?? $parentDto->docType;
				$parent['relation_type'] = RelationType::tryFrom( $parentDto->relationType )?->label() ?? $parentDto->relationType;
			} catch ( \Throwable $e ) {
				$parent = null;
			}
		}

		$this->success( array( 'student' => $student, 'parent' => $parent ) );
	}

	/**
	 * AJAX: отмена зачисления (Enrolling → ReadyForReview).
	 *
	 * @return void
	 */
	public function ajaxCancelEnrollment(): void {
		$this->authorize( Nonce::Manager, Capability::ManageApplications );

		$id  = $this->sanitizeInt( 'application_id' );
		$app = $this->applicationRepository->find( $id );

		if ( null === $app || $app->status !== ApplicationStatus::Enrolling ) {
			$this->success();
			return;
		}

		$this->applicationRepository->update( $id, array(
			'status'     => ApplicationStatus::ReadyForReview->value,
			'updated_at' => current_time( 'mysql', true ),
		) );

		$this->success();
	}

	/**
	 * AJAX: список групп по периоду и предмету.
	 *
	 * @return void
	 */
	public function ajaxGetStudentGroups(): void {
		$this->authorize( Nonce::Manager, Capability::ManageApplications );

		$periodId  = $this->sanitizeText( 'period_id' );
		$subjectId = $this->sanitizeText( 'subject_id' );

		$groups = $this->studentGroupRepository->getByPeriodAndSubject( $periodId, $subjectId );

		$result = array_values( array_map(
			static fn( StudentGroupDTO $g ) => array( 'id' => $g->id, 'title' => $g->title ),
			$groups
		) );

		$this->success( $result );
	}

	/**
	 * AJAX: очистка корзины (физическое удаление всех заявок со статусом Trash).
	 *
	 * @return void
	 */
	public function ajaxEmptyApplicationsTrash(): void {
		$this->authorize( Nonce::TrashApplication, Capability::ManageApplications );

		// Получение всех заявок в корзине
		$trashApps = $this->applicationRepository->list(
			array( 'status' => ApplicationStatus::Trash->value ),
			1,
			9999
		);

		$count = 0;

		foreach ( $trashApps as $app ) {
			try {
				// Физическое удаление записи из БД
				$this->applicationRepository->delete( $app->id );
				$count++;
			} catch ( \Throwable $e ) {
				// Логируем, но не останавливаем цикл
				error_log( '[FS LMS] EmptyTrash: не удалось удалить заявку #' . $app->id . ': ' . $e->getMessage() );
			}
		}

		$this->auditService->record(
			AuditAction::EmptyTrash->value,
			'application',
			null,
			array( 'deleted_count' => $count )
		);

		$this->success( array( 'deleted' => $count ) );
	}

	/**
	 * AJAX: возвращает логин и расшифрованный пароль пользователя.
	 * Используется кнопкой "Показать логин+пароль" в карточке заявки/зачисления.
	 *
	 * Принимает: user_id (int)
	 *
	 * @return void
	 */
	public function ajaxRevealUserCredentials(): void {
		$this->authorize( Nonce::RevealPii, Capability::ManageApplications );

		$user_id = $this->requireInt( 'user_id', error: 'ID пользователя не указан.' );

		$credentials = $this->passwordGenerator->getCredentials( $user_id );

		if ( null === $credentials ) {
			$this->error( 'Пароль недоступен. Пользователь сменил пароль самостоятельно — воспользуйтесь функцией сброса.' );

			return;
		}

		$actor_id  = get_current_user_id();
		$actor_wp  = $actor_id ? get_userdata( $actor_id ) : false;
		$personId  = (int) get_user_meta( $user_id, 'fs_lms_person_id', true ) ?: null;

		$this->piiAccessLog->create( new PiiAccessLogInputDTO(
			actorUserId:    $actor_id ?: null,
			actorRole:      ( $actor_wp && ! empty( $actor_wp->roles ) ) ? (string) reset( $actor_wp->roles ) : null,
			personId:       $personId,
			fieldsAccessed: 'login,password',
			accessReason:   'admin_reveal_credentials',
			actorIp:        sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '' ),
			createdAt:      current_time( 'mysql', true ),
		) );

		$this->success( $credentials );
	}
}