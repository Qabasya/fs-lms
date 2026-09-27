<?php

declare( strict_types=1 );

namespace Inc\Controllers\Pages;

use Inc\Contracts\ServiceInterface;
use Inc\Core\BaseController;
use Inc\Enums\Wp\ShortCode;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Services\Course\PublicCourseService;
use Inc\Shared\Traits\Sanitizer;
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
 *    Пришли по «Записаться» с курса (`?course=ID`) — направление курса выбрано сразу.
 */
class ApplyPageController extends BaseController implements ServiceInterface {

	use Sanitizer;
	use TemplateRenderer;

	public function __construct(
		private readonly SubjectRepository $subjects,
		private readonly PublicCourseService $courses,
	) {
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
		$this->render( 'frontend/apply', array(
			'subjects'         => $this->subjects->readActive(),
			'selected_subject' => $this->courses->subjectOf( $this->sanitizeGetInt( 'course' ) ),
		) );
		return (string) ob_get_clean();
	}
}
