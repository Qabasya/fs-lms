<?php

declare( strict_types=1 );

namespace Inc\Modules\AdSync\Enums;

/**
 * Статус строки outbox-очереди синхронизации с AD.
 *
 * @package Inc\Modules\AdSync\Enums
 */
enum AdOutboxStatus: string {
	case Pending = 'pending';
	case Sent    = 'sent';
	case Failed  = 'failed';
	case Dead    = 'dead';
}
