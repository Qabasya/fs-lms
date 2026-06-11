<?php

declare( strict_types=1 );

namespace Inc\DTO\Log;

use Inc\Enums\EntityType;
use Inc\Enums\OperationType;

/**
 * Class EntityAuditLogDTO
 *
 * Data Transfer Object для записи в журнал аудита изменений сущностей (entity_audit_log).
 *
 * @package Inc\DTO\Log
 *
 * ### Основные обязанности:
 *
 * 1. **Хранение записи аудита сущности** — представляет запись из таблицы entity_audit_log.
 * 2. **Преобразование массива в DTO** — статический метод fromArray().
 *
 * ### Архитектурная роль:
 *
 * Используется в EntityAuditLogWriter для передачи данных о событиях
 * изменения сущностей (предметы, таксономии, задания, статьи и т.д.).
 *
 * ### Поля записи:
 *
 * - actorUserId — ID пользователя WordPress, изменившего сущность
 * - actorRole — роль пользователя на момент изменения
 * - operation — тип операции (create, update, delete)
 * - entityType — тип сущности (subject, taxonomy, task, article и т.д.)
 * - entityId — ID изменённой сущности
 * - oldLabel — старое название сущности (для отображения в логе)
 * - actorIp — IP-адрес пользователя
 * - createdAt — дата и время изменения
 *
 * ### Примечания:
 *
 * - Резолв ID → человекочитаемые названия выполняется в слое отображения
 *   (Callback/Template), не здесь — DTO хранит только то, что лежит в БД.
 */
readonly class EntityAuditLogDTO {

	/**
	 * Конструктор DTO.
	 *
	 * @param int            $id          ID записи
	 * @param int|null       $actorUserId ID пользователя WP, изменившего сущность
	 * @param string|null    $actorRole   Роль пользователя
	 * @param OperationType  $operation   Тип операции (create, update, delete)
	 * @param EntityType     $entityType  Тип сущности
	 * @param int|null       $entityId    ID изменённой сущности
	 * @param string|null    $oldLabel    Старое название сущности
	 * @param string         $actorIp     IP-адрес пользователя
	 * @param string         $createdAt   Дата и время изменения
	 */
	public function __construct(
		public int            $id,
		public ?int           $actorUserId,
		public ?string        $actorRole,
		public OperationType  $operation,
		public EntityType     $entityType,
		public ?int           $entityId,
		public ?string        $oldLabel,
		public string         $actorIp,
		public string         $createdAt,
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
			id:           (int) $row['id'],
			actorUserId:  isset( $row['actor_user_id'] ) ? (int) $row['actor_user_id'] : null,
			actorRole:    isset( $row['actor_role'] ) ? (string) $row['actor_role'] : null,
			operation:    OperationType::from( (string) $row['operation'] ),
			entityType:   EntityType::from( (string) $row['entity_type'] ),
			entityId:     isset( $row['entity_id'] ) ? (int) $row['entity_id'] : null,
			oldLabel:     isset( $row['old_label'] ) ? (string) $row['old_label'] : null,
			actorIp:      (string) $row['actor_ip'],
			createdAt:    (string) $row['created_at'],
		);
	}
}