<?php

declare( strict_types=1 );

namespace Inc\DTO\Course;

/**
 * Замена преподавателя на период (fs_lms_substitutions, Эпик 5, D5).
 *
 * Grant на период: замещающий (`substituteTeacherId`) получает доступ к группе
 * с `validFrom` по `validTo` включительно. `groups.teacher_id` НЕ перезаписывается —
 * эффективный преподаватель резолвится на чтении ({@see \Inc\Services\Course\EffectiveTeacherResolver}).
 *
 * @package Inc\DTO\Course
 */
readonly class SubstitutionDTO {

	public function __construct(
		public int     $id,
		public int     $groupId,
		public ?int    $originalTeacherId,
		public int     $substituteTeacherId,
		public string  $validFrom,   // 'Y-m-d'
		public string  $validTo,     // 'Y-m-d'
		public ?string $reason,
		public ?int    $approvedBy,
		public string  $createdAt,
	) {}

	public static function fromArray( array $row ): self {
		return new self(
			id                  : (int) $row['id'],
			groupId             : (int) $row['group_id'],
			originalTeacherId   : isset( $row['original_teacher_id'] ) ? (int) $row['original_teacher_id'] : null,
			substituteTeacherId : (int) $row['substitute_teacher_id'],
			validFrom           : (string) $row['valid_from'],
			validTo             : (string) $row['valid_to'],
			reason              : $row['reason'] ?? null,
			approvedBy          : isset( $row['approved_by'] ) ? (int) $row['approved_by'] : null,
			createdAt           : (string) ( $row['created_at'] ?? '' ),
		);
	}

	/** Активна ли замена на дату 'Y-m-d' (границы включительно). */
	public function isActiveOn( string $date ): bool {
		return $this->validFrom <= $date && $date <= $this->validTo;
	}
}
