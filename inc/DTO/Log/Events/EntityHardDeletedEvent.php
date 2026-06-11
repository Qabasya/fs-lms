<?php

declare( strict_types=1 );

namespace Inc\DTO\Log\Events;

use Inc\Contracts\LogEventInterface;

/**
 * Payload события Deletion — GDPR hard delete сущности.
 *
 * Диспетчится после завершения всего каскада удаления.
 * $cascadedSummary — текстовая сводка удалённых связанных объектов (без PII).
 */
readonly class EntityHardDeletedEvent implements LogEventInterface {

	public function __construct(
		public int     $actorUserId,
		public string  $entityType,
		public int     $entityId,
		public ?string $cascadedSummary = null,
	) {}
}
