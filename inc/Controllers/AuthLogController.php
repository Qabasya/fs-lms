<?php

declare( strict_types=1 );

namespace Inc\Controllers;

use Inc\Contracts\ServiceInterface;
use Inc\Services\Log\AuthLogWriter;

/**
 * Class AuthLogController
 *
 * Контроллер для записи событий аутентификации (вход, неудачный вход, сброс пароля) в журнал аудита.
 *
 * @package Inc\Controllers
 * @implements ServiceInterface
 *
 * ### Основные обязанности:
 *
 * 1. **Запись успешного входа** — логирование события wp_login.
 * 2. **Запись неудачного входа** — логирование события wp_login_failed.
 * 3. **Запись сброса пароля** — логирование события password_reset.
 *
 * ### Архитектурная роль:
 *
 * Делегирует запись AuthLogWriter.
 * Регистрируется в WordPress через хуки, связанные с аутентификацией.
 * Является частью системы аудита для отслеживания действий пользователей.
 */
class AuthLogController implements ServiceInterface {

	/**
	 * Конструктор контроллера.
	 *
	 * @param AuthLogWriter $authLog Райтер для записи логов аутентификации
	 */
	public function __construct(
		private readonly AuthLogWriter $authLog,
	) {}

	/**
	 * Регистрирует все хуки контроллера.
	 *
	 * @return void
	 */
	public function register(): void {
		// 'wp_login' — хук, срабатывающий после успешного входа пользователя
		add_action( 'wp_login',       array( $this, 'onLogin' ), 10, 2 );
		// 'wp_login_failed' — хук, срабатывающий при неудачной попытке входа
		add_action( 'wp_login_failed', array( $this, 'onLoginFailed' ), 10, 1 );
		// 'password_reset' — хук, срабатывающий после сброса пароля пользователя
		add_action( 'password_reset',  array( $this, 'onPasswordReset' ), 10, 1 );
	}

	/**
	 * Обработчик успешного входа пользователя.
	 *
	 * @param string   $userLogin Логин пользователя
	 * @param \WP_User $user      Объект пользователя WordPress
	 *
	 * @return void
	 */
	public function onLogin( string $userLogin, \WP_User $user ): void {
		$this->authLog->record( $userLogin, 'login', true );
	}

	/**
	 * Обработчик неудачной попытки входа.
	 *
	 * @param string $username Логин или email, введённый пользователем
	 *
	 * @return void
	 */
	public function onLoginFailed( string $username ): void {
		$this->authLog->record( $username, 'login_failed', false );
	}

	/**
	 * Обработчик сброса пароля пользователя.
	 *
	 * @param \WP_User $user Объект пользователя WordPress
	 *
	 * @return void
	 */
	public function onPasswordReset( \WP_User $user ): void {
		$this->authLog->record( $user->user_login, 'password_reset', true );
	}
}