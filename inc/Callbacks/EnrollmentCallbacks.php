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
				$studentData = json_decode( $this->crypto->decrypt( $app->studentDataEnc ), true );
			} catch ( \Throwable $e ) {
				$studentData = null;
			}

			// Логирование факта доступа к PII
			$this->piiAccessLog->create( array(
				'actor_user_id'   => get_current_user_id(),
				'actor_role'      => 'admin',
				'person_id'       => null,
				'fields_accessed' => 'student_data',
				'access_reason'   => 'application_review',
				'actor_ip'        => (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ),
				'created_at'      => current_time( 'mysql', true ),
			) );
		}

		// Расшифровка данных родителя (если есть права ViewPII)
		if ( ! empty( $app->parentDataEnc ) && current_user_can( Capability::ViewPII->value ) ) {
			try {
				$parentData = json_decode( $this->crypto->decrypt( $app->parentDataEnc ), true );
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

		// Частичный сбой — пользователи будут созданы асинхронно
		if ( $result->partialFailure ) {
			$this->success( array(
				'partial'       => true,
				'enrollment_id' => $result->enrollmentId,
				'message'       => 'Зачисление выполнено. Учётные записи будут созданы автоматически в течение 15 минут. Enrollment ID: ' . $result->enrollmentId,
			) );
		}

		$response = array( 'enrollment_id' => $result->enrollmentId );

		// Ссылки для установки паролей (не отправлены автоматически)
		if ( null !== $result->guardianPasswordLink ) {
			$response['guardian_link'] = $result->guardianPasswordLink;
			$response['student_link']  = $result->studentPasswordLink;
			$response['message']       = 'Зачисление выполнено. Передайте ссылки представителю.';
		} else {
			$response['message'] = 'Зачисление выполнено. Ссылка для установки пароля отправлена на почту родителя.';
		}

		$this->success( $response );
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

		$studentData = array();
		if ( ! empty( $app->studentDataEnc ) ) {
			try {
				$studentData = json_decode( $this->crypto->decrypt( $app->studentDataEnc ), true ) ?? array();
			} catch ( \Throwable $e ) {
				$this->error( 'Ошибка расшифровки данных.' );
			}
		}

		$lastName   = $this->requireText( 'last_name' );
		$firstName  = $this->requireText( 'first_name' );
		$middleName = $this->sanitizeText( 'middle_name' );
		$fullName   = trim( "$lastName $firstName $middleName" );

		$studentData['full_name']  = $fullName;
		$studentData['email']      = $this->requireText( 'email' );
		$studentData['phone']      = $this->requireText( 'phone' );
		$studentData['school']     = $this->sanitizeText( 'school' );
		$studentData['grade']      = $this->sanitizeInt( 'grade' );
		$studentData['birth_date'] = $this->requireText( 'birth_date' );

		try {
			$newStudentDataEnc = $this->crypto->encrypt( (string) wp_json_encode( $studentData ) );
		} catch ( \Throwable $e ) {
			$this->error( 'Ошибка шифрования данных.' );
		}

		$emailHash = $this->crypto->hash( $studentData['email'] );

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
		$studentData = array();
		if ( ! empty( $app->studentDataEnc ) ) {
			try {
				$studentData = json_decode( $this->crypto->decrypt( $app->studentDataEnc ), true ) ?? array();
			} catch ( \Throwable $e ) {
				$this->error( 'Ошибка расшифровки данных ученика.' );
			}
		}

		$sLast   = $this->requireText( 'student_last_name' );
		$sFirst  = $this->requireText( 'student_first_name' );
		$sMid    = $this->sanitizeText( 'student_middle_name' );
		$studentData['full_name']  = trim( "$sLast $sFirst $sMid" );
		$studentData['birth_date'] = $this->sanitizeText( 'student_birth_date' );
		$studentData['doc_type']   = $this->sanitizeText( 'student_doc_type' );
		$studentData['doc_number'] = $this->sanitizeText( 'student_doc_number' );
		$studentData['inn']        = $this->sanitizeText( 'student_inn' );

		// Обновление данных родителя
		$parentData = array();
		if ( ! empty( $app->parentDataEnc ) ) {
			try {
				$parentData = json_decode( $this->crypto->decrypt( $app->parentDataEnc ), true ) ?? array();
			} catch ( \Throwable $e ) {
				$this->error( 'Ошибка расшифровки данных родителя.' );
			}
		}

		$pLast   = $this->requireText( 'parent_last_name' );
		$pFirst  = $this->requireText( 'parent_first_name' );
		$pMid    = $this->sanitizeText( 'parent_middle_name' );
		$parentData['full_name']       = trim( "$pLast $pFirst $pMid" );
		$parentData['birth_date']      = $this->sanitizeText( 'parent_birth_date' );
		$parentData['relation_type']   = $this->sanitizeText( 'relation_type' );
		$parentData['email']           = $this->sanitizeText( 'parent_email' );
		$parentData['phone']           = $this->sanitizeText( 'parent_phone' );
		$parentData['doc_type']        = $this->sanitizeText( 'parent_doc_type' );
		$parentData['doc_number']      = $this->sanitizeText( 'parent_doc_number' );
		$parentData['doc_issued_by']   = $this->sanitizeText( 'parent_doc_issued_by' );
		$parentData['doc_issued_date'] = $this->sanitizeText( 'parent_doc_issued_date' );
		$parentData['inn']             = $this->sanitizeText( 'parent_inn' );
		$parentData['address']         = $this->sanitizeText( 'parent_address' );

		try {
			$newStudentDataEnc = $this->crypto->encrypt( (string) wp_json_encode( $studentData ) );
			$newParentDataEnc  = $this->crypto->encrypt( (string) wp_json_encode( $parentData ) );
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
				$sd        = json_decode( $this->crypto->decrypt( $app->studentDataEnc ), true );
				$nameParts = explode( ' ', $sd['full_name'] ?? '', 3 );
				$student   = array(
					'last_name'   => $nameParts[0] ?? '',
					'first_name'  => $nameParts[1] ?? '',
					'middle_name' => $nameParts[2] ?? '',
					'birth_date'  => $sd['birth_date']  ?? '',
					'email'       => $sd['email']       ?? '',
					'phone'       => $sd['phone']       ?? '',
					'school'      => $sd['school']      ?? '',
					'grade'       => $sd['grade']       ?? '',
					'doc_type'    => DocumentType::tryFrom( $sd['doc_type'] ?? '' )?->label() ?? ( $sd['doc_type'] ?? '' ),
					'doc_number'  => $sd['doc_number']  ?? '',
					'inn'         => $sd['inn']         ?? '',
				);
			} catch ( \Throwable $e ) {
				$student = null;
			}
		}

		if ( ! empty( $app->parentDataEnc ) ) {
			try {
				$pd     = json_decode( $this->crypto->decrypt( $app->parentDataEnc ), true );
				$pParts = explode( ' ', $pd['full_name'] ?? '', 3 );
				$parent = array(
					'last_name'       => $pParts[0] ?? '',
					'first_name'      => $pParts[1] ?? '',
					'middle_name'     => $pParts[2] ?? '',
					'birth_date'      => $pd['birth_date']      ?? '',
					'relation_type'   => RelationType::tryFrom( $pd['relation_type'] ?? '' )?->label() ?? ( $pd['relation_type'] ?? '' ),
					'email'           => $pd['email']           ?? '',
					'phone'           => $pd['phone']           ?? '',
					'doc_type'        => DocumentType::tryFrom( $pd['doc_type'] ?? '' )?->label() ?? ( $pd['doc_type'] ?? '' ),
					'doc_number'      => $pd['doc_number']      ?? '',
					'doc_issued_by'   => $pd['doc_issued_by']   ?? '',
					'doc_issued_date' => $pd['doc_issued_date'] ?? '',
					'inn'             => $pd['inn']             ?? '',
					'address'         => $pd['address']         ?? '',
				);
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

		$this->success( $credentials );
	}
}