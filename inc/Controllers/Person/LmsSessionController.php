<?php

declare( strict_types=1 );

namespace Inc\Controllers\Person;

use Inc\Contracts\ServiceInterface;
use Inc\Repositories\WPDBRepositories\UserSessionRepository;
use Inc\Services\Security\LmsSessionService;
use Inc\Shared\Traits\Sanitizer;

class LmsSessionController implements ServiceInterface {

	use Sanitizer;

	public function __construct( private readonly LmsSessionService $sessions ) {}

	public function register(): void {
		add_filter( 'auth_cookie_expiration', array( $this, 'loginLifetime' ), PHP_INT_MAX, 3 );
		add_filter( 'attach_session_information', array( $this, 'tagSession' ) );
		add_action( 'template_redirect', array( $this, 'renew' ), 20 );
	}

	public function loginLifetime( int $lifetime, int $_userId, bool $remember ): int {
		return $remember && $this->isLmsLogin() ? LmsSessionService::IDLE_SECONDS : $lifetime;
	}

	public function tagSession( array $session ): array {
		if ( $this->isLmsLogin() ) {
			$session[ UserSessionRepository::LMS_SESSION ] = true;
		}
		return $session;
	}

	public function renew(): void {
		// Фоновый AJAX, загрузка файлов, prefetch и prerender не продлевают вход.
		if ( 'GET' !== ( $_SERVER['REQUEST_METHOD'] ?? '' )
			|| preg_match( '/\b(?:prefetch|prerender)\b/i', ( $_SERVER['HTTP_SEC_PURPOSE'] ?? '' ) . ' ' . ( $_SERVER['HTTP_PURPOSE'] ?? '' ) )
			|| ( isset( $_SERVER['HTTP_SEC_FETCH_MODE'] ) && 'navigate' !== $_SERVER['HTTP_SEC_FETCH_MODE'] )
			|| ( isset( $_SERVER['HTTP_SEC_FETCH_DEST'] ) && 'document' !== $_SERVER['HTTP_SEC_FETCH_DEST'] ) ) {
			return;
		}

		$this->sessions->renewCurrent( get_current_user_id() );
	}

	private function isLmsLogin(): bool {
		return 'wp-login.php' === ( $GLOBALS['pagenow'] ?? '' )
			&& 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' )
			&& $this->sanitizeBool( 'fs_lms_login' )
			&& 'forever' === $this->sanitizeText( 'rememberme' );
	}
}
