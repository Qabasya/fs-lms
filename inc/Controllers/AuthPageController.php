<?php

declare( strict_types=1 );

namespace Inc\Controllers;

use Inc\Callbacks\AuthCallbacks;
use Inc\Contracts\ServiceInterface;
use Inc\Core\BaseController;
use Inc\Shared\Traits\TemplateRenderer;
class AuthPageController extends BaseController implements ServiceInterface {

	use TemplateRenderer;

	public function __construct(
		private readonly AuthCallbacks $auth_callbacks
	) {
		parent::__construct();
	}
	public function register(): void {
		// Регистрируем шорткод боевой страницы авторизации
		add_shortcode( 'fs_lms_login_form', array( $this, 'renderLoginPage' ) );

		// Перехватываем стандартный wp-login.php и редиректим на нашу кастомную страницу
		add_action( 'init', array( $this, 'redirectToCustomLogin' ) );
	}

	/**
	 * Рендерит кастомную страницу авторизации через шорткод.
	 */
	public function renderLoginPage(): string {
		if ( is_user_logged_in() ) {
			// Если юзер уже залогинен, отправляем его туда, куда положено
			// Метод filterRedirectUrl мы уже написали в AuthController
			wp_safe_redirect( apply_filters( 'lms_auth_redirect_url', home_url(), null ) );
			exit;
		}

		// Собираем активные провайдеры (метод уже есть в твоем AuthCallbacks)
		// Но теперь мы рендерим не тестовый шаблон, а красивый боевой
		$providers = $this->auth_callbacks->getEnabledProviders();

		ob_start();
		$this->render(
			'frontend/auth-page',
			array(
				'providers'     => $providers,
				'register_url'  => home_url( '/sign-up/' ), // URL для регистрации вручную
				'lost_pass_url' => wp_lostpassword_url(),
			)
		);
		return (string) ob_get_clean();
	}


	/**
	 * Заменяет стандартный wp-login.php на нашу страницу
	 */
	public function redirectToCustomLogin(): void {
		global $pagenow;

		// Проверяем, что мы на странице логина и это не AJAX/POST запрос сохранения формы
		if ( 'wp-login.php' === $pagenow && ! isset( $_POST['wp-submit'] ) && 'GET' === $_SERVER['REQUEST_METHOD'] ) {
			wp_safe_redirect( home_url( '/sign-in/' ) );
			exit;
		}
	}

}
