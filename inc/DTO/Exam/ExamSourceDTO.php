<?php
/**
 * Источник приглашения гостя со снимком школы, класса и преподавателя.
 *
 * @package FS_LMS
 */

declare( strict_types=1 );

namespace Inc\DTO\Exam;

/** Источник приглашения гостя со снимком школы, класса и преподавателя. */
readonly class ExamSourceDTO {

	/** Ключ школы служебного источника «Открытая запись»: запись без ссылки школы со страницы выбора класса. Школьных отчётов по нему нет. */
	public const PUBLIC_KEY = 'public';

	/** Допустимые проценты скидки по ссылке преподавателя: 0 — полная цена, 100 — запись без оплаты. Зеркало — `exam-sources.js`. */
	public const DISCOUNT_STEPS = array( 0, 25, 50, 75, 100 );

	/** Принимает типизированные поля без обращения к хранилищу. */
	public function __construct(
		public int $id,
		public int $eventId,
		public ?string $schoolKey,
		public string $schoolName,
		public string $schoolNameNormalized,
		public int $grade,
		public string $teacherName,
		public string $label,
		public bool $isActive,
		public int $keyGeneration,
		public ?string $keyRevokedAt,
		public int $createdByUserId,
		public int $version,
		public string $createdAt,
		public string $updatedAt,
		/** Скидка по ссылке преподавателя, %: 0 — полная цена, 100 — запись без оплаты. У открытой записи не действует. */
		public int $discountPercent = 0,
	) {}

	/** Действующая скидка: у открытой записи её не бывает. */
	public function effectiveDiscount(): int {
		return $this->isPublic() ? 0 : max( 0, min( 100, $this->discountPercent ) );
	}

	/** Запись по ссылке без оплаты (скидка 100 %): бронь сразу превращается в запись, магазин не вызывается. */
	public function isFree(): bool {
		return 100 === $this->effectiveDiscount();
	}

	/** Источник открытой записи (не школа): отчёты по его участникам не формируются. */
	public function isPublic(): bool {
		return self::PUBLIC_KEY === $this->schoolKey;
	}

	/** Восстанавливает типы полей из строки репозитория. */
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
			// Строка до миграции 1.0.88 знает только флаг `is_free`: он равен скидке 100 %.
			discountPercent       : isset( $row['discount_percent'] ) ? (int) $row['discount_percent'] : ( ! empty( $row['is_free'] ) ? 100 : 0 ),
		);
	}
}
