<?php

declare( strict_types=1 );

namespace Inc\DTO\Exam;

readonly class ExamEventDTO {

	public function __construct(
		public int     $id,
		public string  $subjectKey,
		public string  $title,
		public ?string $description,
		public int     $ownerUserId,
		public string  $status,
		public string  $periodFrom,
		public string  $periodTo,
		public ?string $registrationOpensAt,
		public ?string $registrationClosesAt,
		public bool    $guestRegistrationEnabled,
		public ?int    $defaultAssessmentId,
		public ?string $variantSnapshot,
		public ?string $cancelReason,
		public ?string $publishedAt,
		public ?string $completedAt,
		public ?string $cancelledAt,
		public int     $version,
		public string  $createdAt,
		public string  $updatedAt,
	) {}

	public static function fromArray( array $row ): self {
		return new self(
			id                      : (int) $row['id'],
			subjectKey              : (string) $row['subject_key'],
			title                   : (string) $row['title'],
			description             : $row['description'] ?? null,
			ownerUserId             : (int) $row['owner_user_id'],
			status                  : (string) $row['status'],
			periodFrom              : (string) $row['period_from'],
			periodTo                : (string) $row['period_to'],
			registrationOpensAt     : $row['registration_opens_at'] ?? null,
			registrationClosesAt    : $row['registration_closes_at'] ?? null,
			guestRegistrationEnabled: (bool) $row['guest_registration_enabled'],
			defaultAssessmentId     : isset( $row['default_assessment_id'] ) ? (int) $row['default_assessment_id'] : null,
			variantSnapshot         : $row['variant_snapshot'] ?? null,
			cancelReason            : $row['cancel_reason'] ?? null,
			publishedAt             : $row['published_at'] ?? null,
			completedAt             : $row['completed_at'] ?? null,
			cancelledAt             : $row['cancelled_at'] ?? null,
			version                 : (int) $row['version'],
			createdAt               : (string) $row['created_at'],
			updatedAt               : (string) $row['updated_at'],
		);
	}

	/**
	 * Снимок варианта проведения (`variant_snapshot`: объект с ключом — ID работы).
	 * Нет снимка или он не разобрался — null; тогда вызывающий берёт значения из формата.
	 *
	 * @return array<string, mixed>|null
	 */
	public function snapshotFor( int $assessmentId ): ?array {
		if ( null === $this->variantSnapshot || '' === $this->variantSnapshot ) {
			return null;
		}

		$decoded = json_decode( $this->variantSnapshot, true );
		$entry   = is_array( $decoded ) ? ( $decoded[ (string) $assessmentId ] ?? null ) : null;

		return is_array( $entry ) ? $entry : null;
	}
}
