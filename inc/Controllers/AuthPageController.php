<?php

declare( strict_types=1 );

namespace Inc\Controllers;

use Inc\Callbacks\AuthCallbacks;
use Inc\Contracts\ServiceInterface;
use Inc\Core\BaseController;
use Inc\Shared\Traits\TemplateRenderer;
use Inc\Enums\PageRoutes;
use Inc\Enums\ShortCode;

/**
 * Class AuthPageController
 *
 * Контроллер для управления страницами аутентификации (вход, регистрация).
 *
 * @package Inc\Controllers
 * @implements ServiceInterface
 *
 * ### Основные обязанности:
 *
 * 1. **Рендеринг страницы входа** — отображение кастомной страницы авторизации через шорткод.
 * 2. **Перехват wp-login.php** — редирект со стандартной страницы входа на кастомную.
 * 3. **Защита от залогиненных пользователей** — редирект со страницы входа на профиль.
 * 4. **Чистый шаблон** — подмена шаблона темы на чистый (без хедера и футера).
 *
 * ### Архитектурная роль:
 *
 * Делегирует получение активных провайдеров AuthCallbacks.
 * Использует PageRoutes для хранения URL и TemplateRenderer для рендеринга.
 */
class AuthPageController extends BaseController implements ServiceInterface {

	use TemplateRenderer;

	public function __construct(
		private readonly AuthCallbacks $auth_callbacks
	) {
		parent::__construct();
	}

	/**
	 * Регистрирует все хуки и шорткоды контроллера.
	 *
	 * @return void
	 */
	public function register(): void {
		// Шорткод для вставки формы входа на любую страницу
		add_shortcode( ShortCode::LoginForm->value, array( $this, 'renderLoginPage' ) );

		// 'init' — хук, срабатывающий после загрузки WordPress
		add_action( 'init', array( $this, 'redirectToCustomLogin' ) );

		// 'template_include' — фильтр для подмены шаблона темы (приоритет 9999 для переопределения)
		add_filter( 'template_include', array( $this, 'forceCleanAuthLayout' ), 9999 );
	}

	/**
	 * Рендерит кастомную страницу авторизации через шорткод.
	 *
	 * @return string HTML-контент страницы
	 */
	public function renderLoginPage(): string {
		// is_user_logged_in() — проверяет, авторизован ли пользователь
		if ( is_user_logged_in() ) {
			wp_safe_redirect( PageRoutes::USER_PROFILE->url() );
			exit;
		}

		// Получение списка активных провайдеров из AuthCallbacks
		$providers = $this->auth_callbacks->getEnabledProviders();

		// Буферизация вывода для возврата строки (шорткод должен возвращать, а не выводить)
		ob_start();
		$this->render(
			'frontend/auth-page',
			array(
				'providers'     => $providers,
				// PageRoutes::SIGN_UP->url() — URL страницы регистрации (для ручной регистрации)
				'register_url'  => PageRoutes::SIGN_UP->url(),
				// wp_lostpassword_url() — возвращает URL страницы восстановления пароля
				'lost_pass_url' => wp_lostpassword_url(),
			)
		);

		return (string) ob_get_clean();
	}

	/**
	 * Заменяет стандартный wp-login.php на кастомную страницу входа.
	 * Подключается к хуку 'init'.
	 *
	 * @return void
	 */
	public function redirectToCustomLogin(): void {
		global $pagenow;  // Глобальная переменная WordPress с именем текущего файла

		// Проверяем, что мы на странице логина, это GET-запрос и не отправка формы
		// 'wp-submit' — стандартное поле отправки формы авторизации
		if ( 'wp-login.php' === $pagenow && ! isset( $_POST['wp-submit'] ) && 'GET' === $_SERVER['REQUEST_METHOD'] ) {
			wp_safe_redirect( PageRoutes::SIGN_IN->url() );
			exit;
		}
	}

	/**
	 * Полностью перехватывает вывод WordPress для страницы авторизации.
	 * Подменяет шаблон темы на чистый (без хедера и футера).
	 *
	 * @param string $template Путь к текущему шаблону темы
	 *
	 * @return string
	 */
	public function forceCleanAuthLayout( string $template ): string {
		// Если мы в админке, ничего не меняем
		if ( is_admin() ) {
			return $template;
		}

		global $post;

		// Проверяем, что пост существует и содержит шорткод 'fs_lms_login_form'
		// has_shortcode() — проверяет наличие шорткода в контенте
		if ( is_a( $post, 'WP_Post' ) && has_shortcode( $post->post_content, ShortCode::LoginForm->value ) ) {

			// path() — метод родительского BaseController, возвращает полный путь к файлу
			$plugin_template = $this->path( 'templates/frontend/clean-page.php' );

			// file_exists() — проверяет существование файла
			if ( file_exists( $plugin_template ) ) {
				return $plugin_template;
			}
		}

		return $template;
	}
}
