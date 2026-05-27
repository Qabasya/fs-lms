<?php
declare( strict_types=1 );

namespace Inc\Enums;

/**
 * Заголовки страниц административной панели.
 *
 * @package Inc\Core\Config
 */
enum PageTitle: string {
	/** Заголовок первой страницы (Dashboard). */
	case First = 'Dashboard';

	/** Заголовок второй страницы (Настройки). */
	case Second = 'Настройки';
}
