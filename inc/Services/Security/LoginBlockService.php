<?php

declare( strict_types=1 );

namespace Inc\Services\Security;

use Inc\Repositories\OptionsRepositories\LoginBlockRepository;

/** Постоянные блокировки IP и известных учётных записей. */
class LoginBlockService {
	public const IP_LIMIT = 20;
	public const IP_WINDOW = 10 * MINUTE_IN_SECONDS;

	public function __construct( private readonly LoginBlockRepository $repository ) {}

	public function ipBlocked( string $ip ): bool {
		$key = $this->ipKey( $ip );
		return null !== $key && $this->repository->isBlocked( $key );
	}

	public function userBlocked( int $userId ): bool {
		return $userId > 0 && $this->repository->isBlocked( 'user:' . $userId );
	}

	public function recordIpFailure( string $ip ): void {
		$key = $this->ipKey( $ip );
		if ( null !== $key ) {
			$this->repository->recordFailure( $key, self::IP_LIMIT, self::IP_WINDOW );
		}
	}

	public function recordUserFailure( int $userId ): void {
		if ( $userId > 0 ) {
			$this->repository->recordFailure( 'user:' . $userId, RateLimitService::LIMIT_LOGIN, RateLimitService::LOGIN_WINDOW );
		}
	}

	public function unblockIp( string $ip ): bool {
		return null !== $this->unblockIpWithStatus( $ip );
	}

	/** null — неверный адрес, false — постоянной блокировки не было, true — снята. */
	public function unblockIpWithStatus( string $ip ): ?bool {
		$key = $this->ipKey( $ip );
		if ( null === $key ) {
			return null;
		}
		return $this->repository->unblock( $key );
	}

	public function unblockUser( int $userId ): void {
		if ( $userId > 0 ) {
			$this->repository->unblock( 'user:' . $userId );
		}
	}

	public function clearUserFailures( int $userId ): void {
		if ( $userId > 0 ) {
			$this->repository->clearFailures( 'user:' . $userId );
		}
	}

	private function ipKey( string $ip ): ?string {
		$packed = @inet_pton( trim( $ip ) );
		return false === $packed ? null : 'ip:' . inet_ntop( $packed );
	}
}
