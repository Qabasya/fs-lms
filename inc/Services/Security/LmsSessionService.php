<?php

declare( strict_types=1 );

namespace Inc\Services\Security;

use Inc\Contracts\ClockInterface;
use Inc\Repositories\WPDBRepositories\UserSessionRepository;
use Inc\Shared\Traits\ScopedFilter;

/** Скользящий срок входа через форму LMS. Сессия остаётся штатной сессией WordPress. */
class LmsSessionService {

	use ScopedFilter;

	public const int IDLE_SECONDS = 90 * DAY_IN_SECONDS;

	public function __construct(
		private readonly UserSessionRepository $sessions,
		private readonly ClockInterface $clock,
	) {}

	public function renewCurrent( int $userId ): void {
		if ( $userId <= 0 || headers_sent() || (int) wp_validate_auth_cookie( '', 'logged_in' ) !== $userId ) {
			return;
		}
		if ( \WP_User_Meta_Session_Tokens::class !== get_class( \WP_Session_Tokens::get_instance( $userId ) ) ) {
			return;
		}

		$token = wp_get_session_token();
		$now = (int) $this->clock->now( 'U', true );
		$expiration = $now + self::IDLE_SECONDS;
		if ( '' === $token || ! $this->sessions->extend( $userId, $token, $now, $expiration ) ) {
			return;
		}

		$this->withFilter(
			'auth_cookie_expiration',
			static fn(): int => $expiration - time(),
			static fn() => wp_set_auth_cookie( $userId, true, '', $token ),
			PHP_INT_MAX,
		);
	}
}
