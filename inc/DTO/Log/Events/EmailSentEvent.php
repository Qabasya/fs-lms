<?php

declare( strict_types=1 );

namespace Inc\DTO\Log\Events;

use Inc\Contracts\LogEventInterface;
use Inc\Enums\Email\EmailTemplateType;

/**
 * Payload события Email — отправка письма плагином.
 *
 * Диспетчится после вызова wp_mail() со статусом результата.
 */
readonly class EmailSentEvent implements LogEventInterface {

	public function __construct(
		public ?int              $actorUserId,
		public EmailTemplateType $emailType,
		public ?int              $targetPersonId,
		public string            $recipientEmail,
		public bool              $success,
		public ?string           $errorMessage = null,
	) {}
}
