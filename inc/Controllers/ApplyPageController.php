<?php

declare( strict_types=1 );

namespace Inc\Controllers;

use Inc\Contracts\ServiceInterface;
use Inc\Core\BaseController;
use Inc\Enums\ShortCode;
use Inc\Shared\Traits\TemplateRenderer;

/**
 * Class ApplyPageController
 *
 * Контроллер публичной страницы подачи заявки на обучение.
 *
 * @package Inc\Controllers
 *
 * ### Основные обязанности:
 *
 * 1. **Регистрация шорткода** — регистрация шорткода [fs_lms_apply_form] для вставки формы на страницу.
 * 2. **Рендеринг формы** — отображение формы заявки через шаблон frontend/apply.php.
 */
class ApplyPageController extends BaseController implements ServiceInterface {

	use TemplateRenderer;

	public function __construct() {
		parent::__construct();
	}

	/**
	 * Регистрирует шорткод формы заявки.
	 *
	 * @return void
	 */
	public function register(): void {
		add_shortcode( ShortCode::ApplyForm->value, array( $this, 'renderApplyForm' ) );
	}

	/**
	 * Рендерит форму подачи заявки через шорткод.
	 *
	 * @return string HTML-контент формы
	 */
	public function renderApplyForm(): string {
		ob_start();
		$this->render( 'frontend/apply', array() );
		return (string) ob_get_clean();
	}
}