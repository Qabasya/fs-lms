<?php

declare( strict_types=1 );

namespace Inc\DTO\Exam;

readonly class ExamReportMemberDTO {

	public function __construct(
		public int     $id,
		public int     $reportId,
		public int     $participationId,
		public ?int    $consentRef,
		public string  $createdAt,
	) {}

	public static function fromArray( array $row ): self {
		return new self(
			id             : (int) $row['id'],
			reportId       : (int) $row['report_id'],
			participationId: (int) $row['participation_id'],
			consentRef     : isset( $row['consent_ref'] ) ? (int) $row['consent_ref'] : null,
			createdAt      : (string) $row['created_at'],
		);
	}
}
