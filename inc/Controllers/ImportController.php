<?php

declare( strict_types=1 );

namespace Inc\Controllers;

use Inc\Callbacks\ImportCallbacks;
use Inc\Enums\Wp\AjaxHook;

/**
 * Class ImportController
 *
 * Регистрирует AJAX-хук импорта учеников из CSV.
 *
 * @package Inc\Controllers
 *
 * Следует паттерну Template Method базового {@see AjaxController}:
 * переопределяет ajaxActions(), регистрация хуков — в родителе.
 */
class ImportController extends AjaxController {

	/**
	 * @param ImportCallbacks $callbacks Обработчик импорта
	 */
	public function __construct(
		private readonly ImportCallbacks $callbacks,
	) {
		parent::__construct();
	}

	/**
	 * AJAX-действия только для авторизованных администраторов.
	 *
	 * @return list<array{AjaxHook, object}>
	 */
	protected function ajaxActions(): array {
		return array(
			array( AjaxHook::ImportStudentsCsv, $this->callbacks ),
		);
	}
}
