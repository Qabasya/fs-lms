<?php

declare( strict_types=1 );

namespace Inc\Services\Security;

use Inc\Enums\Auth\LoginNotice;
use Inc\Enums\Wp\PageRoutes;
use WP_Error;

/**
 * Class UserEnumerationGuard
 *
 * Закрывает гостям каналы, через которые утекают логины: `user_nicename` — это
 * `sanitize_title( login )`, поэтому любой адрес автора раскрывает логин.
 *
 * @package Inc\Services\Security
 *
 * ### Каналы:
 *
 * 1. REST `/wp/v2/users` (включая `?rest_route=` и встраивание автора через `_embed`).
 * 2. Архивы авторов `?author=N` и `/author/slug/` — редирект `redirect_canonical` отдаёт логин в `Location`.
 * 3. Провайдер `users` карты сайта ядра.
 * 4. Поля автора в ответе oEmbed.
 * 5. Сообщения нативной формы входа («пользователь не зарегистрирован»).
 * 6. Восстановление пароля: POST на `wp-login.php?action=lostpassword` сообщает, есть ли логин.
 */
readonly class UserEnumerationGuard {

	/** Маршруты пользователей. Ядро сопоставляет маршруты без учёта регистра. */
	private const USERS_ROUTE = '#^/wp/v2/users(?:/|$)#i';

	/** Коды ошибок ядра, по которым различимы «нет такого логина» и «неверный пароль». */
	private const CREDENTIAL_ERROR_CODES = array( 'invalid_username', 'invalid_email', 'incorrect_password' );

	/**
	 * Отказ гостю на маршрутах пользователей (фильтр `rest_pre_dispatch`).
	 *
	 * Гейт — по факту входа, а не по праву `list_users`: редактор под учётками
	 * преподавателей обращается к `/wp/v2/users/me` и `?who=authors`.
	 *
	 * @param mixed  $result   Результат предыдущих фильтров
	 * @param string $route    Маршрут запроса
	 * @param bool   $loggedIn Пользователь вошёл
	 *
	 * @return mixed
	 */
	public function restPreDispatch( mixed $result, string $route, bool $loggedIn ): mixed {
		if ( $loggedIn || ! $this->isUsersRoute( $route ) ) {
			return $result;
		}

		return new WP_Error(
			'rest_user_cannot_view',
			'Данные пользователей доступны только после входа.',
			array( 'status' => 401 )
		);
	}

	public function isUsersRoute( string $route ): bool {
		return 1 === preg_match( self::USERS_ROUTE, $route );
	}

	/**
	 * 404 вместо архива автора (хук `template_redirect`, до `redirect_canonical`).
	 *
	 * Страниц авторов в LMS нет, поэтому закрываем их для всех.
	 *
	 * @return void
	 */
	public function blockAuthorArchive(): void {
		global $wp_query;

		if ( ! is_author() ) {
			return;
		}

		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();
	}

	/**
	 * Убирает провайдер `users` из карты сайта (фильтр `wp_sitemaps_add_provider`).
	 *
	 * @param mixed  $provider Провайдер
	 * @param string $name     Имя провайдера
	 *
	 * @return mixed false — провайдер не регистрируется
	 */
	public function filterSitemapProvider( mixed $provider, string $name ): mixed {
		return 'users' === $name ? false : $provider;
	}

	/**
	 * Убирает автора из ответа oEmbed (фильтр `oembed_response_data`).
	 *
	 * @param array<string, mixed> $data Данные ответа
	 *
	 * @return array<string, mixed>
	 */
	public function stripOembedAuthor( array $data ): array {
		unset( $data['author_url'], $data['author_name'] );

		return $data;
	}

	/**
	 * Одна фраза вместо различимых ошибок логина и пароля (фильтр `wp_login_errors`).
	 *
	 * @param WP_Error $errors Ошибки формы входа
	 *
	 * @return WP_Error
	 */
	public function unifyLoginErrors( WP_Error $errors ): WP_Error {
		$codes = array_intersect( self::CREDENTIAL_ERROR_CODES, $errors->get_error_codes() );

		if ( array() === $codes ) {
			return $errors;
		}

		foreach ( $codes as $code ) {
			$errors->remove( $code );
		}
		$errors->add( 'fs_lms_invalid_credentials', LoginNotice::Failed->message() );

		return $errors;
	}

	/**
	 * Уводит любые запросы восстановления пароля на страницу входа
	 * (хуки `login_form_{action}` — срабатывают до обработки в wp-login.php).
	 *
	 * @return never
	 */
	public function redirectPasswordReset(): never {
		wp_safe_redirect( PageRoutes::SignIn->url() );
		exit;
	}
}
