<?php

declare( strict_types=1 );

namespace Inc\DTO\Exam;

readonly class ExamSourceDTO {

	public function __construct(
		public int     $id,
		public int     $eventId,
		public ?string $schoolKey,
		public string  $schoolName,
		public string  $schoolNameNormalized,
		public int     $grade,
		public string  $teacherName,
		public string  $label,
		public bool    $isActive,
		public int     $keyGeneration,
		public ?string $keyRevokedAt,
		public int     $createdByUserId,
		public int     $version,
		public string  $createdAt,
		public string  $updatedAt,
	) {}

	public static function fromArray( array $row ): self {
		return new self(
			id                    : (int) $row['id'],
			eventId               : (int) $row['event_id'],
			schoolKey             : $row['school_key'] ?? null,
			schoolName            : (string) $row['school_name'],
			schoolNameNormalized  : (string) $row['school_name_normalized'],
			grade                 : (int) $row['grade'],
			teacherName           : (string) $row['teacher_name'],
			label                 : (string) $row['label'],
			isActive              : (bool) $row['is_active'],
			keyGeneration         : (int) $row['key_generation'],
			keyRevokedAt          : $row['key_revoked_at'] ?? null,
			createdByUserId       : (int) $row['created_by_user_id'],
			version               : (int) $row['version'],
			createdAt             : (string) $row['created_at'],
			updatedAt             : (string) $row['updated_at'],
		);
	}
}
