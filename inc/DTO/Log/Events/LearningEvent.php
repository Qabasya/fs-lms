<?php

declare( strict_types=1 );

namespace Inc\DTO\Log\Events;

use Inc\Contracts\LogEventInterface;
use Inc\Enums\LogEvent;

readonly class LearningEvent implements LogEventInterface {

	public function __construct(
		public LogEvent $event,
		public int      $actorUserId,
		public ?string  $subjectKey  = null,
		public ?int     $groupId     = null,
		public ?string  $entityType  = null,
		public ?string  $entityId    = null,
		public bool     $isPublic    = true,
	) {}
}
