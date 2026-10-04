<?php

declare( strict_types=1 );

namespace Inc\Repositories\WPDBRepositories;

/** Взаимная блокировка (1213) или таймаут ожидания блокировки (1205): операцию можно повторить. */
class RetryableDbException extends \RuntimeException {
}
