<?php

declare( strict_types=1 );

namespace Inc\Services;

use DomainException;
use Inc\DTO\ApplicationCreatedDTO;
use Inc\DTO\ApplicationInputDTO;
use Inc\DTO\ParentSubmissionInputDTO;
use Inc\Enums\ApplicationStatus;
use Inc\Enums\AuditAction;
use Inc\Enums\ConsentType;
use Inc\Repositories\WPDBRepositories\ApplicationRepository;
use Inc\Shared\Traits\RequestContextProvider;
use Inc\Shared\Traits\TransactionRunner;
use RuntimeException;

readonly class ApplicationService {

	use TransactionRunner;
	use RequestContextProvider;

	public function __construct(
		private ApplicationRepository $applicationRepository,
		private JoinCodeService       $joinCodeService,
		private PiiCryptoService      $crypto,
		private ConsentService        $consentService,
		private AuditService          $auditService,
		private EmailOtpService       $emailOtpService,
	) {}

	public function createApplication( ApplicationInputDTO $input ): ApplicationCreatedDTO {
		if ( ! $this->emailOtpService->verify( $input->email, $input->otpCode ) ) {
			throw new RuntimeException( 'Неверный или истёкший код подтверждения.' );
		}

		$emailHash = $this->crypto->hash( $input->email );

		if ( null !== $this->applicationRepository->findActiveByEmail( $emailHash ) ) {
			throw new DomainException( 'Незавершённая заявка уже существует.' );
		}

		$joinCode      = $this->joinCodeService->generate();
		$joinCodeHash  = $this->joinCodeService->hash( $joinCode );
		$expiresAt     = gmdate( 'Y-m-d H:i:s', time() + 14 * DAY_IN_SECONDS );
		$studentDataEnc = $this->crypto->encrypt( (string) wp_json_encode( array(
			'full_name'  => $input->fullName,
			'email'      => $input->email,
			'school'     => $input->school,
			'grade'      => $input->grade,
			'birth_date' => $input->birthDate,
		) ) );

		$ctx = $this->requestContext();

		$appId = $this->inTransaction( function () use ( $emailHash, $joinCodeHash, $expiresAt, $studentDataEnc, $ctx, $input ): int {
			$id = $this->applicationRepository->create( array(
				'status'               => ApplicationStatus::PendingParent->value,
				'join_code_hash'       => $joinCodeHash,
				'join_code_expires_at' => $expiresAt,
				'student_email_hash'   => $emailHash,
				'student_data_enc'     => $studentDataEnc,
				'parent_submitted_ip'  => $ctx->ip,
				'created_at'           => current_time( 'mysql', true ),
				'updated_at'           => current_time( 'mysql', true ),
			) );

			$this->consentService->recordSelfConsent( $id, ConsentType::PdProcessing, $ctx );

			$this->auditService->recordAnonymous(
				AuditAction::CreateApplication->value,
				'application',
				$id,
				array( 'email_hash' => $emailHash )
			);

			return $id;
		} );

		$joinUrl = home_url( '/lms/join/' . $joinCode );

		return new ApplicationCreatedDTO( $appId, $joinUrl, $expiresAt );
	}

	public function submitParentData( ParentSubmissionInputDTO $input ): void {
		$codeHash = $this->joinCodeService->hash( $input->joinCode );
		$app      = $this->applicationRepository->findByJoinCodeHash( $codeHash );

		if ( null === $app || ApplicationStatus::PendingParent !== $app->status ) {
			throw new DomainException( 'Заявка не найдена или недоступна.' );
		}

		$ctx = $this->requestContext();

		$parentDataEnc = $this->crypto->encrypt( (string) wp_json_encode( array(
			'full_name'       => $input->parentFullName,
			'birth_date'      => $input->parentBirthDate,
			'relation_type'   => $input->relationType,
			'doc_type'        => $input->docType,
			'doc_number'      => $input->docNumber,
			'doc_issued_by'   => $input->docIssuedBy,
			'doc_issued_date' => $input->docIssuedDate,
			'inn'             => $input->inn,
			'snils'           => $input->snils,
			'address'         => $input->address,
			'phone'           => $input->phone,
			'email'           => $input->email,
		) ) );

		$studentDataEnc = $this->crypto->encrypt( (string) wp_json_encode( array(
			'full_name'  => $input->studentFullName,
			'birth_date' => $input->studentBirthDate,
			'doc_type'   => $input->studentDocType,
			'doc_number' => $input->studentDocNumber,
			'inn'        => $input->studentInn,
		) ) );

		$appId = $app->id;

		$this->inTransaction( function () use ( $appId, $parentDataEnc, $studentDataEnc, $ctx ): void {
			$this->applicationRepository->update( $appId, array(
				'status'             => ApplicationStatus::ReadyForReview->value,
				'parent_data_enc'    => $parentDataEnc,
				'student_data_enc'   => $studentDataEnc,
				'parent_submitted_ip' => $ctx->ip,
				'parent_submitted_ua' => $ctx->userAgent,
				'updated_at'         => current_time( 'mysql', true ),
			) );

			$this->consentService->recordGuardianConsent( $appId, ConsentType::PdChildProcessing, 0, $ctx );

			$this->auditService->recordAnonymous(
				AuditAction::SubmitParentData->value,
				'application',
				$appId
			);
		} );

		do_action( 'fs_lms_application_ready', $appId );
	}

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