<?php

declare( strict_types=1 );

namespace Inc\Services\Log;

use Inc\Enums\Access\UserRole;
use Inc\Managers\Person\UserManager;

/**
 * Class ActorRoleResolver
 *
 * Определяет роль пользователя, выполнившего действие, для записи в журнал.
 *
 * @package Inc\Services\Log
 *
 * ### Архитектурная роль:
 *
 * Единственный источник правила «ID пользователя → слаг основной роли» для всех
 * log-writer'ов ({@see EmailLogWriter}, {@see PiiAccessLogWriter} и остальных):
 * раньше каждый держал собственную приватную копию этой логики.
 *
 * Гость (id ≤ 0), удалённый пользователь и пользователь без ролей дают `null` —
 * в журнале это означает «роль неизвестна», а не отсутствие записи.
 */
readonly class ActorRoleResolver {

	/**
	 * @param UserManager $userManager Менеджер пользователей WP
	 */
	public function __construct(
		private UserManager $userManager,
	) {}

	/**
	 * Слаг основной роли пользователя.
	 *
	 * @param int $userId ID пользователя WordPress (0 — гость)
	 *
	 * @return string|null Слаг роли или null, если роль неопределима
	 */
	public function resolve( int $userId ): ?string {
		if ( $userId <= 0 ) {
			return null;
		}

		$user  = $this->userManager->find( $userId );
		$roles = (array) ( $user->roles ?? array() );

		return empty( $roles ) ? null : UserRole::primarySlug( $roles );
	}
}
