<?php

declare( strict_types=1 );

namespace Inc\Controllers\Person;

use Inc\Contracts\ServiceInterface;
use Inc\Core\BaseController;
use Inc\Enums\Auth\LoginNotice;
use Inc\Enums\Wp\PageRoutes;
use Inc\Enums\Wp\ShortCode;
use Inc\Repositories\OptionsRepositories\ConsentDefinitionsRepository;
use Inc\Services\Security\LoginGuardService;
use Inc\Services\Security\RateLimitService;
use Inc\Shared\Traits\RequestContextProvider;
use Inc\Shared\Traits\Sanitizer;
use Inc\Shared\Traits\TemplateRenderer;
use WP_Error;

/**
 * Class AuthPageController
 *
 * Страница входа `/sign-in/`: шорткод формы, перехват `wp-login.php` и возврат
 * на страницу с ошибкой при неверном пароле.
 *
 * @package Inc\Controllers\Person
 *
 * ### Основные обязанности:
 *
 * 1. **Рендер формы** — шорткод {@see ShortCode::LoginForm} на странице `/sign-in/`.
 * 2. **Перехват штатной формы** — GET-заходы на `wp-login.php` уводятся сюда.
 * 3. **Обработка неудачного входа** — возврат на страницу с флагом и введённым логином.
 * 4. **Чистый макет** — страница входа рендерится вне шаблона темы.
 *
 * ### Архитектурная роль:
 *
 * Ядро, а не модуль. Раньше страницей владел опциональный SocialAuth: пока модуль
 * был выключен (а выключен он по умолчанию), `Activate` создавал страницу `/sign-in/`,
 * но шорткод регистрировать было некому — страница отдавалась пустой, а `wp-login.php`
 * не перехватывался вовсе. Форма логина к соцсетям отношения не имеет, поэтому живёт
 * в ядре.
 */
class AuthPageController extends BaseController implements ServiceInterface {

	use RequestContextProvider;
	use Sanitizer;
	use TemplateRenderer;

	public function __construct(
		private readonly ConsentDefinitionsRepository $consents,
		private readonly LoginGuardService            $loginGuard,
	) {
		parent::__construct();
	}

	public function register(): void {
		add_shortcode( ShortCode::LoginForm->value, array( $this, 'renderLoginPage' ) );
		add_action( 'init', array( $this, 'redirectToCustomLogin' ) );
		// Приоритет 20: после AuthLogController (10) и LoginGuardController (15),
		// чтобы попытка успела записаться в журнал и счётчик до redirect + exit.
		add_action( 'wp_login_failed', array( $this, 'redirectFailedLogin' ), 20, 2 );
		add_filter( 'template_include', array( $this, 'forceCleanAuthLayout' ), 9999 );
	}

	/**
	 * Рендерит форму входа. Залогиненного уводит в кабинет.
	 *
	 * @return string
	 */
	public function renderLoginPage(): string {
		if ( is_user_logged_in() ) {
			wp_safe_redirect( PageRoutes::UserProfile->url() );
			exit;
		}

		ob_start();
		$this->render(
			'frontend/auth-page',
			array(
				'error_message' => $this->loginNoticeMessage(),
				'prefill_login' => $this->sanitizeGetText( 'fs_user' ),
				'consent_url'   => $this->consentUrl(),
			)
		);

		return (string) ob_get_clean();
	}

	/**
	 * Текст уведомления по флагу `login` из редиректа неудачного входа.
	 *
	 * Флаг и `wait` только для отображения: решение о блокировке принимает сервер.
	 *
	 * @return string Пустая строка — уведомления нет.
	 */
	private function loginNoticeMessage(): string {
		$notice = LoginNotice::tryFrom( $this->sanitizeGetKey( 'login' ) );

		if ( null === $notice ) {
			return '';
		}

		$maxWait = intdiv( RateLimitService::LOGIN_WINDOW, MINUTE_IN_SECONDS );
		$wait    = min( max( $this->sanitizeGetInt( 'wait' ), 0 ), $maxWait );

		return $notice->message( $wait );
	}

	/**
	 * Адрес страницы согласия на обработку ПДн, если она заведена и опубликована.
	 *
	 * @return string Пустая строка — согласия нет, юридическую сноску не показываем.
	 */
	private function consentUrl(): string {
		$definition = $this->consents->findByKey( 'pd_processing' );
		$pageId     = (int) ( $definition['page_id'] ?? 0 );

		if ( $pageId <= 0 ) {
			return '';
		}

		return (string) ( get_permalink( $pageId ) ?: '' );
	}

	/**
	 * Уводит GET-заходы на `wp-login.php` на страницу входа плагина.
	 *
	 * POST (отправка самой формы) и `action=logout` не трогаем — иначе сломается
	 * вход и выход.
	 *
	 * @return void
	 */
	public function redirectToCustomLogin(): void {
		global $pagenow;

		if ( 'wp-login.php' === $pagenow
			&& ! $this->hasParam( 'wp-submit' )
			&& 'GET' === $_SERVER['REQUEST_METHOD']
			&& 'logout' !== $this->sanitizeGetText( 'action' )
		) {
			wp_safe_redirect( PageRoutes::SignIn->url() );
			exit;
		}
	}

	/**
	 * Возвращает на страницу входа с уведомлением и введённым логином.
	 *
	 * @param string        $username Логин из неудачной попытки.
	 * @param WP_Error|null $error    Причина отказа (с WP 5.4).
	 *
	 * @return void
	 */
	public function redirectFailedLogin( string $username, ?WP_Error $error = null ): void {
		// Маркер нашей формы логина (hidden value="1"); у wp-login flow нонса нет.
		if ( ! $this->sanitizeBool( 'fs_lms_login' ) ) {
			return;
		}

		$notice = $this->loginGuard->noticeFor( $username, $error, $this->requestContext()->ip );

		$url = add_query_arg(
			array_merge(
				$notice->queryArgs(),
				array( 'fs_user' => rawurlencode( $username ) )
			),
			PageRoutes::SignIn->url()
		);

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Подменяет шаблон темы чистым макетом на странице с формой входа.
	 *
	 * @param string $template Шаблон, выбранный WordPress.
	 *
	 * @return string
	 */
	public function forceCleanAuthLayout( string $template ): string {
		if ( is_admin() ) {
			return $template;
		}

		global $post;

		if ( is_a( $post, 'WP_Post' ) && has_shortcode( $post->post_content, ShortCode::LoginForm->value ) ) {
			$plugin_template = $this->path( 'templates/frontend/clean-page.php' );

			if ( file_exists( $plugin_template ) ) {
				return $plugin_template;
			}
		}

		return $template;
	}
}
