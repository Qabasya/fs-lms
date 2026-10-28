<?php

declare( strict_types=1 );

namespace Inc\Modules\Masterclass\DTO;

/**
 * Мастер-класс (строка `fs_lms_masterclasses`).
 *
 * Все даты — UTC в формате `Y-m-d H:i:s`: такие строки сравниваются лексикографически, поэтому `isFrozen()`
 * и `isRunning()` — простое сравнение строк. Заморозка ВЫВОДИТСЯ из времени (`freezeAt`), а не из `frozenAt`:
 * `frozenAt` — лишь отметка тика, что событие записано в журнал (продление сдвигает `freezeAt` и снимает её).
 *
 * @package Inc\Modules\Masterclass\DTO
 */
readonly class MasterclassDTO {

	/**
	 * @param int         $id                Номер мастер-класса
	 * @param string      $schoolName        Школа
	 * @param string      $subjectKey        Предмет
	 * @param int         $lessonId          Урок банка
	 * @param int|null    $groupId           Группа (null — вычищена или удалена)
	 * @param int|null    $groupLessonId     Занятие группы
	 * @param int         $teacherUserId     Преподаватель
	 * @param string      $startsAt          Начало окна (UTC)
	 * @param string      $endsAt            Конец окна (UTC)
	 * @param string      $freezeAt          С этого момента доступ закрыт (UTC)
	 * @param string      $unusedPurgeAt     С этого момента удаляются аккаунты без входа (UTC)
	 * @param string      $purgeAt           С этого момента вычищается всё (UTC)
	 * @param string|null $frozenAt          Тик записал заморозку
	 * @param string|null $unusedPurgedAt    Неиспользованные аккаунты удалены
	 * @param string|null $deleteRequestedAt Администратор нажал «Удалить»
	 * @param string|null $purgedAt          Аккаунты и группа вычищены
	 * @param int|null    $createdByUserId   Кто создал
	 * @param string      $createdAt         Создан (UTC)
	 * @param string      $updatedAt         Изменён (UTC)
	 */
	public function __construct(
		public int     $id,
		public string  $schoolName,
		public string  $subjectKey,
		public int     $lessonId,
		public ?int    $groupId,
		public ?int    $groupLessonId,
		public int     $teacherUserId,
		public string  $startsAt,
		public string  $endsAt,
		public string  $freezeAt,
		public string  $unusedPurgeAt,
		public string  $purgeAt,
		public ?string $frozenAt,
		public ?string $unusedPurgedAt,
		public ?string $deleteRequestedAt,
		public ?string $purgedAt,
		public ?int    $createdByUserId,
		public string  $createdAt,
		public string  $updatedAt,
	) {}

	/**
	 * @param array<string, mixed> $row Строка таблицы
	 */
	public static function fromArray( array $row ): self {
		return new self(
			id:                (int) $row['id'],
			schoolName:        (string) ( $row['school_name'] ?? '' ),
			subjectKey:        (string) ( $row['subject_key'] ?? '' ),
			lessonId:          (int) ( $row['lesson_id'] ?? 0 ),
			groupId:           self::nullableInt( $row['group_id'] ?? null ),
			groupLessonId:     self::nullableInt( $row['group_lesson_id'] ?? null ),
			teacherUserId:     (int) ( $row['teacher_user_id'] ?? 0 ),
			startsAt:          (string) ( $row['starts_at'] ?? '' ),
			endsAt:            (string) ( $row['ends_at'] ?? '' ),
			freezeAt:          (string) ( $row['freeze_at'] ?? '' ),
			unusedPurgeAt:     (string) ( $row['unused_purge_at'] ?? '' ),
			purgeAt:           (string) ( $row['purge_at'] ?? '' ),
			frozenAt:          self::nullableString( $row['frozen_at'] ?? null ),
			unusedPurgedAt:    self::nullableString( $row['unused_purged_at'] ?? null ),
			deleteRequestedAt: self::nullableString( $row['delete_requested_at'] ?? null ),
			purgedAt:          self::nullableString( $row['purged_at'] ?? null ),
			createdByUserId:   self::nullableInt( $row['created_by_user_id'] ?? null ),
			createdAt:         (string) ( $row['created_at'] ?? '' ),
			updatedAt:         (string) ( $row['updated_at'] ?? '' ),
		);
	}

	/** Доступ закрыт: наступил `freezeAt`. Граница включена — в сам момент `freezeAt` уже закрыто. */
	public function isFrozen( string $nowUtc ): bool {
		return $nowUtc >= $this->freezeAt;
	}

	/** Идёт окно мастер-класса: `startsAt <= now <= endsAt` (обе границы включены). */
	public function isRunning( string $nowUtc ): bool {
		return $nowUtc >= $this->startsAt && $nowUtc <= $this->endsAt;
	}

	/** Аккаунты и группа вычищены. */
	public function isPurged(): bool {
		return null !== $this->purgedAt;
	}

	/** Администратор запросил удаление (тик вычистит). */
	public function isDeleteRequested(): bool {
		return null !== $this->deleteRequestedAt;
	}

	private static function nullableInt( mixed $value ): ?int {
		return null === $value || '' === $value ? null : (int) $value;
	}

	private static function nullableString( mixed $value ): ?string {
		return null === $value || '' === $value ? null : (string) $value;
	}
}
