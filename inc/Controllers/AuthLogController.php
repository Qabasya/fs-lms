<?php

declare( strict_types=1 );

namespace Inc\Controllers;

use Inc\Contracts\ServiceInterface;
use Inc\Services\Log\AuthLogWriter;

class AuthLogController implements ServiceInterface {

	public function __construct(
		private readonly AuthLogWriter $authLog,
	) {}

	public function register(): void {
		add_action( 'wp_login',       array( $this, 'onLogin' ), 10, 2 );
		add_action( 'wp_login_failed', array( $this, 'onLoginFailed' ), 10, 1 );
		add_action( 'password_reset',  array( $this, 'onPasswordReset' ), 10, 1 );
	}

	public function onLogin( string $userLogin, \WP_User $user ): void {
		$this->authLog->record( $userLogin, 'login', true );
	}

	public function onLoginFailed( string $username ): void {
		$this->authLog->record( $username, 'login_failed', false );
	}

	public function onPasswordReset( \WP_User $user ): void {
		$this->authLog->record( $user->user_login, 'password_reset', true );
	}
}
