<?php

declare( strict_types=1 );

namespace Inc\DTO\Log;

/**
 * Class ConsentChangeLogDTO
 *
 * Data Transfer Object для записи в журнал изменений согласий (consent_change_log).
 *
 * @package Inc\DTO\Log
 *
 * ### Основные обязанности:
 *
 * 1. **Хранение записи изменения согласия** — представляет запись из таблицы consent_change_log.
 * 2. **Преобразование массива в DTO** — статический метод fromArray().
 *
 * ### Архитектурная роль:
 *
 * Используется в ConsentChangeLogWriter для передачи данных о событиях
 * изменения согласий (старый и новый хеш документа согласия).
 *
 * ### Поля записи:
 *
 * - actorUserId — ID пользователя WordPress, изменившего согласие
 * - actorRole — роль пользователя на момент изменения
 * - personId — ID лица (из persons), чьё согласие изменилось
 * - consentType — тип согласия (pd_processing, marketing и т.д.)
 * - oldHash — SHA-256 хеш старой версии документа согласия
 * - newHash — SHA-256 хеш новой версии документа согласия
 */
readonly class ConsentChangeLogDTO {

	/**
	 * Конструктор DTO.
	 *
	 * @param int         $id          ID записи
	 * @param int|null    $actorUserId ID пользователя WP, изменившего согласие
	 * @param string|null $actorRole   Роль пользователя
	 * @param int|null    $personId    ID лица (из persons)
	 * @param string      $consentType Тип согласия
	 * @param string|null $oldHash     Хеш старой версии документа
	 * @param string|null $newHash     Хеш новой версии документа
	 * @param string      $createdAt   Дата и время изменения
	 */
	public function __construct(
		public int     $id,
		public ?int    $actorUserId,
		public ?string $actorRole,
		public ?int    $personId,
		public string  $consentType,
		public ?string $oldHash,
		public ?string $newHash,
		public string  $createdAt,
	) {}

	/**
	 * Создаёт DTO из массива данных (например, из результата SQL-запроса).
	 *
	 * @param array<string, mixed> $row Ассоциативный массив с полями таблицы
	 *
	 * @return static
	 */
	public static function fromArray( array $row ): static {
		return new static(
			id:          (int) $row['id'],
			actorUserId: isset( $row['actor_user_id'] ) ? (int) $row['actor_user_id'] : null,
			actorRole:   isset( $row['actor_role'] ) ? (string) $row['actor_role'] : null,
			personId:    isset( $row['person_id'] ) ? (int) $row['person_id'] : null,
			consentType: (string) $row['consent_type'],
			oldHash:     isset( $row['old_hash'] ) ? (string) $row['old_hash'] : null,
			newHash:     isset( $row['new_hash'] ) ? (string) $row['new_hash'] : null,
			createdAt:   (string) $row['created_at'],
		);
	}
}