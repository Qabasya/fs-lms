<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Enrollment;

use Inc\Contracts\LogEventDispatcherInterface;
use Inc\Core\BaseController;
use Inc\DTO\Enrollment\StudentDataDTO;
use Inc\DTO\Log\Events\ApplicationStatusEvent;
use Inc\DTO\Person\ParentDataDTO;
use Inc\Enums\Enrollment\ApplicationStatus;
use Inc\Enums\Log\AuditAction;
use Inc\Enums\Access\Capability;
use Inc\Enums\Person\DocumentType;
use Inc\Enums\Log\LogEvent;
use Inc\Enums\Wp\Nonce;
use Inc\Repositories\WPDBRepositories\ApplicationRepository;
use Inc\Services\Security\PiiCryptoService;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;

/**
 * Class ApplicationDataCallbacks
 *
 * Чтение и правка PII-данных заявки (ученик + родитель): расшифровка для карточки,
 * обновление администратором, обновление на этапе проверки (ReadyForReview).
 * Вся крипта заявок (encrypt/decrypt/hash) сосредоточена здесь.
 *
 * Выделен из EnrollmentCallbacks (Т14.2).
 *
 * @package Inc\Callbacks\Enrollment
 */
class ApplicationDataCallbacks extends BaseController {

	use Authorizer;
	use Sanitizer;

	public function __construct(
		private readonly ApplicationRepository       $applicationRepository,
		private readonly PiiCryptoService            $crypto,
		private readonly LogEventDispatcherInterface $logEvents,
	) {
		parent::__construct();
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

		$this->logEvents->dispatch( LogEvent::ApplicationUpdated, new ApplicationStatusEvent(
			get_current_user_id(), AuditAction::UpdateApplicationData, $id
		) );

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

		$this->logEvents->dispatch( LogEvent::ApplicationUpdated, new ApplicationStatusEvent(
			get_current_user_id(), AuditAction::UpdateReviewData, $id
		) );

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
				$parent['doc_type'] = DocumentType::tryFrom( $parentDto->docType )?->label() ?? $parentDto->docType;
			} catch ( \Throwable $e ) {
				$parent = null;
			}
		}

		$this->success( array(
			'student'     => $student,
			'parent'      => $parent,
			'subject_key' => $app->subjectKey ?? '',
		) );
	}
}
