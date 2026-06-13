<?php

declare( strict_types=1 );

namespace Inc\DTO\Log\Events;

use Inc\Contracts\LogEventInterface;

/**
 * Payload события ConsentChange — изменение согласия на обработку ПДн.
 *
 * $oldHash / $newHash — хеши документа согласия (не сам документ).
 */
readonly class ConsentChangedEvent implements LogEventInterface {

	public function __construct(
		public ?int   $actorUserId,
		public ?int   $personId,
		public string $consentType,
		public ?string $oldHash,
		public ?string $newHash,
	) {}
}
