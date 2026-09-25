<?php

declare( strict_types=1 );

namespace Inc\Services\Person;

use Inc\Contracts\ClockInterface;
use Inc\Enums\Access\UserRole;
use Inc\Managers\Person\UserManager;

/**
 * Class PresenceService
 *
 * «Был в системе» преподавателя: время последнего запроса к сайту (любая
 * страница или AJAX, включая фоновый опрос уведомлений открытого кабинета) и
 * входа. По нему крон решает, что преподавателя нет на занятии
 * ({@see \Inc\Services\Profile\AdminAlertCronService}). Пишется не чаще раза в
 * {@see self::THROTTLE_MINUTES} минут и только преподавателям.
 *
 * @package Inc\Services\Person
 */
class PresenceService {

	private const THROTTLE_MINUTES = 5;

	public function __construct(
		private readonly UserManager    $users,
		private readonly ClockInterface $clock,
	) {}

	/** Запрос вошедшего пользователя (хук `init`). */
	public function touchCurrentUser(): void {
		$userId = get_current_user_id();
		if ( $userId > 0 ) {
			$this->touch( $userId );
		}
	}

	/** Вход (хук `wp_login`). */
	public function onLogin( string $login, \WP_User $user ): void {
		$this->touch( (int) $user->ID );
	}

	/** Время последнего появления пользователя ('Y-m-d H:i:s', местное) или null. */
	public function lastSeenAt( int $userId ): ?string {
		return $this->users->getLastSeenAt( $userId );
	}

	private function touch( int $userId ): void {
		$user = $this->users->find( $userId );
		if ( null === $user || ! in_array( UserRole::FSTeacher->value, (array) $user->roles, true ) ) {
			return;
		}

		$now  = $this->clock->now();
		$last = $this->users->getLastSeenAt( $userId );
		$gate = ( new \DateTimeImmutable( $now ) )->modify( '-' . self::THROTTLE_MINUTES . ' minutes' )->format( 'Y-m-d H:i:s' );
		if ( null !== $last && $last > $gate ) {
			return;
		}

		$this->users->setLastSeenAt( $userId, $now );
	}
}
