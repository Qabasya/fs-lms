<?php

declare( strict_types=1 );

namespace Inc\DTO\Log;

use Inc\Enums\EntityType;
use Inc\Enums\OperationType;

/**
 * DTO строки из таблицы entity_audit_log (чтение / отображение в админке).
 *
 * Резолв id → человекочитаемые названия выполняется в слое отображения (Callback/Template),
 * не здесь — DTO хранит только то, что лежит в БД.
 */
readonly class EntityAuditLogDTO {

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
