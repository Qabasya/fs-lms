<?php

declare( strict_types=1 );

namespace Inc\DTO\Log\Events;

use Inc\Contracts\LogEventInterface;

/**
 * Payload события DataChange — изменение поля данных пользователя.
 *
 * Диспетчится по одному событию на каждое изменённое поле.
 * $oldValue и $newValue — сырые значения; writer зашифрует их перед записью в БД.
 *
 * Используется только во внутренней шине (не do_action) — payload содержит PII.
 */
readonly class PersonDataChangedEvent implements LogEventInterface {

	public function __construct(
		public int    $actorUserId,
		public int    $targetPersonId,
		public string $fieldName,
		public mixed  $oldValue,
		public mixed  $newValue,
	) {}
}
