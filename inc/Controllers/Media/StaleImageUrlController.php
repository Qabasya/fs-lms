<?php

declare( strict_types=1 );

namespace Inc\Controllers\Media;

use Inc\Callbacks\Media\StaleImageUrlCallbacks;
use Inc\Contracts\ServiceInterface;
use Inc\Core\BaseController;

/**
 * Class StaleImageUrlController
 *
 * Подключает починку адресов картинок к выводу содержимого. Приоритет 20 — после `wpautop` и шорткодов, когда теги `<img>` уже
 * на месте; сами условия заданий и разборы работ вызывают `apply_filters( 'the_content' )`, поэтому покрыты тоже.
 *
 * @package Inc\Controllers\Media
 */
class StaleImageUrlController extends BaseController implements ServiceInterface {

	/**
	 * @param StaleImageUrlCallbacks $callbacks Обработчик фильтра
	 */
	public function __construct(
		private readonly StaleImageUrlCallbacks $callbacks,
	) {
		parent::__construct();
	}

	/**
	 * @return void
	 */
	public function register(): void {
		add_filter( 'the_content', array( $this->callbacks, 'filterContent' ), 20 );
	}
}
