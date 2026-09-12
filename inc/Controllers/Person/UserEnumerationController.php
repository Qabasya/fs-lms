<?php

declare( strict_types=1 );

namespace Inc\Controllers\Person;

use Inc\Contracts\ServiceInterface;
use Inc\Services\Security\UserEnumerationGuard;
use WP_REST_Request;

/**
 * Class UserEnumerationController
 *
 * Хуки, закрывающие гостям каналы перечисления логинов, и отключение
 * восстановления пароля. Логика — в {@see UserEnumerationGuard}.
 *
 * @package Inc\Controllers\Person
 */
class UserEnumerationController implements ServiceInterface {

	/** Действия wp-login.php, через которые идёт восстановление пароля. */
	private const PASSWORD_RESET_ACTIONS = array( 'lostpassword', 'retrievepassword', 'rp', 'resetpass' );

	public function __construct(
		private readonly UserEnumerationGuard $guard,
	) {}

	public function register(): void {
		add_filter( 'rest_pre_dispatch', array( $this, 'guardRestUsers' ), 10, 3 );
		// Приоритет 1: на 10 висит redirect_canonical, отдающий логин в Location.
		add_action( 'template_redirect', array( $this->guard, 'blockAuthorArchive' ), 1 );
		add_filter( 'wp_sitemaps_add_provider', array( $this->guard, 'filterSitemapProvider' ), 10, 2 );
		add_filter( 'oembed_response_data', array( $this->guard, 'stripOembedAuthor' ), 20 );
		add_filter( 'wp_login_errors', array( $this->guard, 'unifyLoginErrors' ) );

		// Восстановления пароля нет осознанно (решение 2026-09-12). Закрываем и на сервере:
		// GET уводит AuthPageController, а POST ядро обработало бы само.
		foreach ( self::PASSWORD_RESET_ACTIONS as $action ) {
			add_action( "login_form_{$action}", array( $this->guard, 'redirectPasswordReset' ) );
		}
		// Приоритет 20 — после UserBehaviorManager::blockPasswordReset (LMS-роли); закрывает
		// и сброс через «Мой аккаунт» WooCommerce.
		add_filter( 'allow_password_reset', '__return_false', 20 );
	}

	/**
	 * @param mixed           $result  Результат предыдущих фильтров
	 * @param mixed           $server  Сервер REST
	 * @param WP_REST_Request $request Запрос
	 *
	 * @return mixed
	 */
	public function guardRestUsers( mixed $result, mixed $server, WP_REST_Request $request ): mixed {
		return $this->guard->restPreDispatch( $result, $request->get_route(), is_user_logged_in() );
	}
}
