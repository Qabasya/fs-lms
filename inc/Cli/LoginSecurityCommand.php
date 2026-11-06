<?php

declare( strict_types=1 );

namespace Inc\Cli;

use Inc\Contracts\ServiceInterface;
use Inc\Services\Security\LoginBlockService;
use Inc\Services\Security\LoginGuardService;
use WP_CLI;

class LoginSecurityCommand implements ServiceInterface {
	public function __construct( private readonly LoginBlockService $blocks, private readonly LoginGuardService $guard ) {}

	public function register(): void {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'fs-lms login unblock-ip', array( $this, 'unblockIp' ) );
			WP_CLI::add_command( 'fs-lms login unblock-user', array( $this, 'unblockUser' ) );
		}
	}

	/**
	 * Снять блокировку IP.
	 *
	 * ## OPTIONS
	 *
	 * <ip>
	 * : IPv4 или IPv6 адрес.
	 */
	public function unblockIp( array $args ): void {
		$status = isset( $args[0] ) ? $this->blocks->unblockIpWithStatus( $args[0] ) : null;
		if ( null === $status ) {
			WP_CLI::error( 'Укажите корректный IP-адрес.' );
			return;
		}
		WP_CLI::success( $status ? 'Блокировка IP снята; блокировки учётных записей сохраняются.' : 'Постоянной блокировки этого IP не было; счётчик сброшен.' );
	}

	/**
	 * Снять блокировку учётной записи.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Числовой идентификатор пользователя WordPress.
	 */
	public function unblockUser( array $args ): void {
		$id = isset( $args[0] ) && ctype_digit( $args[0] ) ? (int) $args[0] : 0;
		if ( $id < 1 ) {
			WP_CLI::error( 'Укажите числовой ID пользователя.' );
			return;
		}
		$this->guard->unlockUser( $id );
		WP_CLI::success( 'Блокировка учётной записи снята.' );
	}
}
