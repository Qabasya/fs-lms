<?php

declare( strict_types=1 );

namespace Inc\Repositories\WPDBRepositories;

/** Нарушение уникального индекса (1062): вторая линия защиты от дубля записи. */
class DuplicateKeyException extends \RuntimeException {
}
