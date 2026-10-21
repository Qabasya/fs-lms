<?php

declare( strict_types=1 );

namespace Inc\Controllers\Seo;

use Inc\Callbacks\Seo\ContentSeoCallbacks;
use Inc\Contracts\ServiceInterface;
use Inc\Core\BaseController;

/**
 * Class ContentSeoController
 *
 * Хуки поисковых тегов страниц заданий и статей. Вся логика — в {@see ContentSeoCallbacks}.
 *
 * Фильтры `rank_math/frontend/*` срабатывают только при активном Rank Math, без него
 * регистрация безвредна. Микроразметка и robots.txt от плагина не зависят.
 *
 * @package Inc\Controllers\Seo
 */
class ContentSeoController extends BaseController implements ServiceInterface {

	/**
	 * @param ContentSeoCallbacks $callbacks Обработчики поисковых тегов.
	 */
	public function __construct(
		private readonly ContentSeoCallbacks $callbacks,
	) {
		parent::__construct();
	}

	/**
	 * @return void
	 */
	public function register(): void {
		add_filter( 'rank_math/frontend/title', array( $this->callbacks, 'filterTitle' ) );
		add_filter( 'rank_math/frontend/description', array( $this->callbacks, 'filterDescription' ) );
		add_filter( 'rank_math/frontend/robots', array( $this->callbacks, 'filterRobots' ) );

		add_action( 'wp_head', array( $this->callbacks, 'printSchema' ), 20 );

		// Приоритет выше, чем у Rank Math и WooCommerce: дописываем в готовый текст.
		add_filter( 'robots_txt', array( $this->callbacks, 'filterRobotsTxt' ), 99, 2 );
	}
}
