<?php

declare( strict_types=1 );

namespace Inc\Controllers\Pages;

use Inc\Contracts\ServiceInterface;
use Inc\Services\Shared\PageTypographyService;

/**
 * Class TypographyController
 *
 * Неразрывные пробелы после предлогов и союзов на всех публичных страницах
 * (тема и плагин) — {@see PageTypographyService}.
 *
 * @package Inc\Controllers\Pages
 */
class TypographyController implements ServiceInterface {

	public function __construct(
		private readonly PageTypographyService $service,
	) {}

	public function register(): void {
		// Последним: буфер открывается прямо перед выводом шаблона.
		add_action( 'template_redirect', array( $this->service, 'start' ), PHP_INT_MAX );
	}
}
