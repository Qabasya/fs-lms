<?php

declare( strict_types=1 );

namespace Inc\Controllers;

use Inc\Callbacks\AcademicPeriodCallbacks;
use Inc\Contracts\ServiceInterface;
use Inc\Core\BaseController;
use Inc\Enums\AjaxHook;

/**
 * Class AcademicPeriodController
 *
 * Контроллер для управления функционалом учебных периодов.
 * То, что он так раздут - так надо.
 *
 * @package Inc\Controllers
 */
class AcademicPeriodController extends BaseController implements ServiceInterface {

	/**
	 * Конструктор принимает коллбеки через DI.
	 */
	public function __construct(
		private readonly AcademicPeriodCallbacks $academic_period_callbacks
	) {
		parent::__construct();
	}
	public function register(): void {
		// Регистрация AJAX-обработчиков
		$this->registerAjaxHooks();
	}

	/**
	 * Регистрация AJAX-хуков для учебных периодов.
	 */
	private function registerAjaxHooks(): void {
		$academicHooks = array(
			AjaxHook::SaveAcademicPeriod,
			AjaxHook::DeleteAcademicPeriod,
		);

		foreach ( $academicHooks as $hook ) {
			add_action(
				$hook->action(),
				array( $this->academic_period_callbacks, $hook->callbackMethod() )
			);
		}
	}
}