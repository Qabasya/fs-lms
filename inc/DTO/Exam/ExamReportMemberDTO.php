<?php
/**
 * Членство участия в отчёте; само по себе не раскрывает результат или контакты гостя.
 *
 * @package FS_LMS
 */

declare( strict_types=1 );

namespace Inc\DTO\Exam;

/** Членство участия в отчёте; само по себе не раскрывает результат или контакты гостя. */
readonly class ExamReportMemberDTO {

	/** Принимает типизированные поля без обращения к хранилищу. */
	public function __construct(
		public int $id,
		public int $reportId,
		public int $participationId,
		public ?int $consentRef,
		public string $createdAt,
	) {}

	/** Восстанавливает типы полей из строки репозитория. */
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
