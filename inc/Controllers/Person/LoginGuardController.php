<?php

declare( strict_types=1 );

namespace Inc\Controllers\Person;

use Inc\Contracts\ServiceInterface;
use Inc\Services\Security\LoginGuardService;
use Inc\Shared\Traits\RequestContextProvider;
use Inc\Shared\Traits\Sanitizer;
use WP_Error;
use WP_User;

/**
 * Class LoginGuardController
 *
 * Хуки защиты входа: лимит неудачных попыток и капча. Логика — в {@see LoginGuardService}.
 *
 * @package Inc\Controllers\Person
 *
 * ### Приоритеты:
 *
 * - `authenticate` на 30: `wp_authenticate_username_password` (20) затирает любой
 *   WP_Error, пришедший до него. На 30 блокировка срабатывает и при верном пароле.
 * - `wp_login_failed` на 15: после журнала (AuthLogController, 10) и до редиректа
 *   формы (AuthPageController, 20 — там exit).
 */
class LoginGuardController implements ServiceInterface {

	use RequestContextProvider;
	use Sanitizer;

	public function __construct(
		private readonly LoginGuardService $guard,
	) {}

	public function register(): void {
		add_filter( 'authenticate', array( $this, 'guardAuthenticate' ), 30, 2 );
		add_action( 'wp_login_failed', array( $this, 'onLoginFailed' ), 15, 2 );
		add_action( 'wp_login', array( $this, 'onLogin' ), 10, 2 );
	}

	/**
	 * @param WP_User|WP_Error|null $user     Результат проверки пароля ядром
	 * @param mixed                 $username Логин или email
	 *
	 * @return WP_User|WP_Error|null
	 */
	public function guardAuthenticate( mixed $user, mixed $username ): mixed {
		global $pagenow;

		// Капча — на любом POST формы входа, а не только с маркером fs_lms_login: бот маркер не пришлёт.
		$fromLoginForm = 'wp-login.php' === $pagenow && $this->hasParam( 'log' );

		return $this->guard->guard(
			$user,
			is_string( $username ) ? $username : '',
			$this->sanitizeText( 'captcha_token' ),
			$this->requestContext()->ip,
			$fromLoginForm
		);
	}

	/**
	 * @param string        $username Логин или email из неудачной попытки
	 * @param WP_Error|null $error    Причина отказа (с WP 5.4)
	 *
	 * @return void
	 */
	public function onLoginFailed( string $username, ?WP_Error $error = null ): void {
		$this->guard->onFailure( $username, $error, $this->requestContext()->ip );
	}

	/**
	 * @param string  $userLogin Логин
	 * @param WP_User $user      Вошедший пользователь
	 *
	 * @return void
	 */
	public function onLogin( string $userLogin, WP_User $user ): void {
		$this->guard->onSuccess( $user, $this->requestContext()->ip );
	}
}
