<?php

declare(strict_types=1);

namespace Inc\Core;

use Inc\Contracts\ServiceInterface;
use Inc\Core\Assets\AdminAssets;
use Inc\Core\Assets\BundleLoader;
use Inc\Core\Assets\FrontendAssets;

/**
 * Class Enqueue
 *
 * Фасад подключения скриптов и стилей плагина: регистрирует хуки и делегирует
 * слою Core\Assets (Т14.4). Правило «все wp_localize_script — только в слое
 * Core/Assets (фасад Enqueue)» сохраняется: вызовы живут в AdminAssets /
 * FrontendAssets / BundleLoader, шаблоны глобалов не локализуют.
 *
 * @package Inc\Core
 * @implements ServiceInterface
 *
 * ### Раскладка слоя Assets:
 *
 * - {@see AdminAssets}          — гейт экрана + базовый admin-стек + локализации
 * - {@see Assets\AdminLocalizations} — реестр window-переменных админки
 * - {@see FrontendAssets}       — роутинг SPA/общий стек + публичные локализации
 * - {@see BundleLoader}         — примитивы (шрифты/FA/MathJax) + SPA-бандлы
 *
 * Модалки Confirm/Alert в admin_footer — Controllers\System\AdminFooterModalsController
 * (рендер разметки, не ассеты).
 */
class Enqueue implements ServiceInterface {

	public function __construct(
		private readonly AdminAssets    $admin,
		private readonly FrontendAssets $frontend,
		private readonly BundleLoader   $bundles,
	) {}

	/**
	 * Регистрация всех хуков подключения ресурсов.
	 *
	 * @return void
	 */
	public function register(): void {
		// preconnect к CDN шрифтов — до самой загрузки стиля (экономит RTT).
		add_filter( 'wp_resource_hints', array( $this->bundles, 'fontResourceHints' ), 10, 2 );
		// 'admin_enqueue_scripts' — хук для подключения ресурсов в админ-панели
		add_action( 'admin_enqueue_scripts', array( $this->admin, 'enqueue' ) );
		// 'wp_enqueue_scripts' — хук для подключения ресурсов на фронтенде
		add_action( 'wp_enqueue_scripts', array( $this->frontend, 'enqueue' ) );
	}
}
