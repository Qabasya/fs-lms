<?php

declare( strict_types=1 );

namespace Inc\Modules\AdSync\Enums;

/**
 * Тип события синхронизации с AD (строка в outbox / эндпоинт Python).
 *
 * @package Inc\Modules\AdSync\Enums
 */
enum AdSyncEvent: string {
	case Provision   = 'provision';
	case Promote     = 'promote';
	case Deprovision = 'deprovision';
}
