<?php

declare( strict_types=1 );

namespace Inc\Controllers\Pages;

use Inc\Contracts\ServiceInterface;
use Inc\Core\BaseController;
use Inc\Enums\Wp\ShortCode;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Services\Exam\ApplyPrefillLinkService;
use Inc\Services\Security\FormGuardService;
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

	public function __construct(
		private readonly SubjectRepository $subjects,
		private readonly ApplyPrefillLinkService $prefillLinks,
		private readonly FormGuardService $formGuard,
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
		// Личная ссылка гостя экзамена: поля формы заполнены, курсор — на почте (её у гостя нет).
		$token   = sanitize_text_field( wp_unslash( $_GET[ ApplyPrefillLinkService::QUERY_ARG ] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- публичная ссылка, подпись проверяет сервис.
		$prefill = '' !== $token ? $this->prefillLinks->resolve( $token ) : null;

		ob_start();
		$this->render( 'frontend/apply', array(
			'subjects' => $this->subjects->readActive(),
			'prefill'  => $prefill ?? array(),
			// Токен возвращается формой в заявку: по нему при зачислении результаты экзамена гостя переходят к ученику.
			'guest_token' => null !== $prefill ? $token : '',
			'honeypot' => $this->formGuard->honeypotField(),
		) );
		return (string) ob_get_clean();
	}
}