<?php

declare( strict_types=1 );

namespace Inc\DTO\Log\Events;

use Inc\Contracts\LogEventInterface;
use Inc\Enums\EntityType;
use Inc\Enums\OperationType;

/**
 * Payload события EntityAudit — CRUD-операция над сущностью плагина.
 *
 * Диспетчится после успешного сохранения/удаления. Никогда не содержит PII.
 * $oldLabel — только нечувствительное название (например «Английский язык»), не значение поля.
 */
readonly class EntityChangedEvent implements LogEventInterface {

	public function __construct(
		public int           $actorUserId,
		public OperationType $operation,
		public EntityType    $entityType,
		public int|string    $entityId,
		public ?string       $oldLabel = null,
	) {}
}
