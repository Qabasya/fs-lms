<?php

declare( strict_types=1 );

namespace Inc\DTO\Log\Events;

use Inc\Contracts\LogEventInterface;
use Inc\Enums\Log\AuditAction;

/**
 * Payload события EnrollmentAudit — изменение статуса на пути зачисления.
 *
 * $studentRecordId и $groupId заполняются только для EnrollStudent.
 */
readonly class EnrollmentStatusEvent implements LogEventInterface {

	public function __construct(
		public int         $actorUserId,
		public AuditAction $action,
		public int         $studentPersonId,
		public ?int        $studentRecordId = null,
		public ?int        $groupId         = null,
	) {}
}
