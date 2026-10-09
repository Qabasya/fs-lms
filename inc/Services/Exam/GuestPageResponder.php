<?php

declare( strict_types=1 );

namespace Inc\Services\Exam;

use Inc\DTO\Exam\GuestPageOutcome;

/**
 * Исполняет {@see GuestPageOutcome}: заголовки гостевых страниц, куки, редирект, 404 темы.
 *
 * Здесь только транспорт — решений нет. Все гостевые страницы отдаются с `noindex`, `no-store` и `no-referrer`:
 * ключ в адресе не попадает в кеш, поисковик и заголовок Referer.
 */
class GuestPageResponder {

	/** Заголовки гостевых страниц. Отправляются до любого вывода и независимо от исхода. */
	public function sendHeaders(): void {
		if ( headers_sent() ) {
			return;
		}

		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );
		header( 'Referrer-Policy: no-referrer' );
	}

	/**
	 * Удаляет куки гостя (завершение сеанса по AJAX: исход страницы здесь не при чём).
	 *
	 * @param list<string> $names
	 */
	public function clearCookies( array $names ): void {
		foreach ( $names as $name ) {
			$this->setCookie( $name, '', time() - YEAR_IN_SECONDS );
		}
	}

	/**
	 * @return bool true — исход исполнен и выполнение прервано (редирект/404 выходят из скрипта); false — страницу нужно отрисовать.
	 */
	public function apply( GuestPageOutcome $outcome ): bool {
		foreach ( $outcome->clearCookies as $name ) {
			$this->setCookie( $name, '', time() - YEAR_IN_SECONDS );
		}

		if ( GuestPageOutcome::RENDER === $outcome->kind ) {
			return false;
		}

		if ( GuestPageOutcome::REDIRECT === $outcome->kind ) {
			foreach ( $outcome->cookies as $name => $value ) {
				$this->setCookie( $name, $value, 0 );
			}
			wp_safe_redirect( $outcome->url, 302 );
			exit;
		}

		global $wp_query;
		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();
		include get_404_template();
		exit;
	}

	/** Сессионная кука: без срока, HttpOnly, SameSite=Lax, Secure на https. */
	private function setCookie( string $name, string $value, int $expires ): void {
		setcookie( $name, $value, array(
			'expires'  => $expires,
			'path'     => '/',
			'secure'   => is_ssl(),
			'httponly' => true,
			'samesite' => 'Lax',
		) );
		if ( '' !== $value ) {
			$_COOKIE[ $name ] = $value;
		} else {
			unset( $_COOKIE[ $name ] );
		}
	}
}
