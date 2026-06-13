<?php

declare( strict_types=1 );

namespace Inc\DTO\Log\Events;

use Inc\Contracts\LogEventInterface;

/**
 * Payload события PiiAccess — просмотр персональных данных администратором.
 */
readonly class PiiRevealedEvent implements LogEventInterface {

	public function __construct(
		public int    $actorUserId,
		public ?int   $targetPersonId,
		public string $fieldsAccessed,
		public string $accessReason,
	) {}
}
