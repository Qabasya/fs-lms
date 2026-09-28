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
	case Deprovision = 'deprovision';
	/** Администратор сменил пароль ученика на сайте — тот же пароль ставится в AD. */
	case Password    = 'password';
}
