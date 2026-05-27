<?php
declare(strict_types=1);
namespace Inc\Enums;

/**
 * Заголовки пунктов меню в административной панели.
 *
 * @package Inc\Core\Config
 */
enum MenuTitle: string
{
	/** Заголовок первого пункта меню. */
	case First = 'Статистика';

	/** Заголовок второго пункта меню. */
	case Second = 'Настройки плагина';
}
