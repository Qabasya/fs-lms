<?php

declare(strict_types=1);

namespace Inc\Enums;

/**
 * Слаги меню плагина.
 *
 * @package Inc\Core\Config
 */
enum MenuSlug: string
{
	/** Slug главного меню плагина. */
	case Main = 'fs_lms';

	/** Slug меню предметов. */
	case Subjects = 'fs_subjects';
}
