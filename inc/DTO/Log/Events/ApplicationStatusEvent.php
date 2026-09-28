<?php

declare( strict_types=1 );

namespace Inc\DTO\Log\Events;

use Inc\Contracts\LogEventInterface;
use Inc\Enums\Log\AuditAction;

/**
 * Payload события ApplicationAudit — изменение статуса заявки.
 *
 * Используется для anonymous событий (actorUserId=0): создание заявки,
 * подпись родителя, истечение срока. Для авторизованных действий
 * с заявками используется EnrollmentStatusEvent.
 */
readonly class ApplicationStatusEvent implements LogEventInterface {

	/**
	 * @param array<string, scalar> $details Доп. поля записи журнала (`note` — пояснение под действием)
	 */
	public function __construct(
		public int         $actorUserId,
		public AuditAction $action,
		public int         $applicationId,
		public ?int        $studentPersonId = null,
		public array       $details = array(),
	) {}
}
