<?php

declare( strict_types=1 );

namespace Inc\Services\Application;

use Inc\Managers\Person\UserManager;
use Inc\Repositories\WPDBRepositories\ApplicationRepository;
use Inc\Services\Security\PiiCryptoService;

/**
 * Class LoginAvailabilityService
 *
 * Занят ли логин ученика: учёткой WordPress или другой незавершённой заявкой.
 *
 * Логин заявки становится учёткой только при зачислении, поэтому проверка по одним
 * WP-пользователям пропускала две заявки с одинаковым логином — вторая падала при зачислении.
 * Логин заявки хранится зашифрованным, для поиска — `username_hash` (Migration_1_0_8).
 *
 * @package Inc\Services\Application
 */
readonly class LoginAvailabilityService {

	public function __construct(
		private UserManager           $users,
		private ApplicationRepository $applications,
		private PiiCryptoService      $crypto,
	) {}

	/**
	 * @param string   $login                Логин
	 * @param int|null $exceptApplicationId  Заявка, логин которой правят (её саму не учитывать)
	 */
	public function isTaken( string $login, ?int $exceptApplicationId = null ): bool {
		if ( '' === trim( $login ) ) {
			return false;
		}

		return null !== $this->users->findByLogin( $login )
			|| $this->applications->existsActiveByUsernameHash( $this->hash( $login ), $exceptApplicationId );
	}

	/**
	 * Хэш логина для колонки `username_hash` (регистр и пробелы по краям не различаются).
	 *
	 * @param string $login Логин
	 */
	public function hash( string $login ): string {
		return $this->crypto->hash( $login );
	}
}
