<?php

declare( strict_types=1 );

namespace Inc\Services\AuthService;

use Hybridauth\Hybridauth;
use Inc\DTO\UserDTO;
use Inc\Enums\AuthProvider;
use Inc\Enums\UserRole;
use Inc\Repositories\UserRepository;

/**
 * Class AuthService
 *
 * Сервис для управления процессом авторизации через социальные сети.
 *
 * @package Inc\Services
 *
 * ### Основные обязанности:
 *
 * 1. **Инициализация OAuth** — запуск процесса авторизации у провайдера (редирект).
 * 2. **Аутентификация** — обработка callback'а, получение профиля, создание пользователя.
 * 3. **Вход в WordPress** — установка сессии и куки для авторизованного пользователя.
 *
 * ### Архитектурная роль:
 *
 * Делегирует работу с пользователями UserRepository.
 * Использует библиотеку Hybridauth для OAuth-авторизации через соцсети.
 */
class AuthService {

	private ?Hybridauth $hybridauth = null;

	public function __construct(
		private readonly UserRepository $user_repo
	) {}

	/**
	 * Основная точка входа для всех стратегий.
	 * Принимает очищенный профиль из соцсети и выполняет "приземление" данных.
	 *
	 * @param AuthProvider $provider Социальная сеть
	 * @param object       $profile Профиль от Hybridauth
	 * @return UserDTO|null
	 */
	public function processUserFromSocialProfile( AuthProvider $provider, object $profile ): ?UserDTO {
		// 1. Пытаемся найти пользователя по ID соцсети
		$user = $this->user_repo->getBySocialId( $provider->value, (string) $profile->identifier );

		// 2. Если по ID не нашли, проверяем по Email (склейка аккаунтов)
		if ( ! $user && ! empty( $profile->email ) ) {
			$user = $this->user_repo->getByEmail( $profile->email );

			if ( $user ) {
				// Если нашли по email — привязываем текущую соцсеть
				$this->user_repo->updateMeta(
					$user->id,
					array(
						"fs_social_{$provider->value}_id" => $profile->identifier,
					)
				);
			}
		}

		// 3. Если пользователя всё еще нет — регистрируем нового
		if ( ! $user ) {
			$user = $this->registerSocialUser( $provider, $profile );
		}

		// 4. Если пользователь найден (а не зарегистрирован только что),
		// всё равно обновляем аватарку на актуальную из соцсети
		if ( $user && ! empty( $profile->photoURL ) ) {
			$this->user_repo->updateMeta(
				$user->id,
				array(
					'fs_avatar_url' => $profile->photoURL,
				)
			);
		}

		// 5. Если в итоге пользователь есть — логиним его в WP
		if ( $user ) {
			$this->login( $user );
		}

		return $user;
	}
	/**
	 * Выполняет вход пользователя в WordPress.
	 *
	 * @param UserDTO $user DTO пользователя
	 *
	 * @return void
	 */
	public function login( UserDTO $user ): void {
		// wp_set_current_user() — устанавливает текущего пользователя в глобальную переменную
		wp_set_current_user( $user->id );

		// wp_set_auth_cookie() — создаёт куки аутентификации
		// Второй параметр true — "запомнить меня"
		wp_set_auth_cookie( $user->id, true );

		// do_action( 'wp_login' ) — стандартный WordPress-хук для действий после входа
		do_action( 'wp_login', $user->email, get_userdata( $user->id ) );
	}


	/**
	 * Создаёт нового пользователя на основе профиля из соцсети.
	 *
	 * @param AuthProvider $provider Провайдер
	 * @param object       $profile  Профиль пользователя из Hybridauth
	 *
	 * @return UserDTO|null
	 */
	private function registerSocialUser( AuthProvider $provider, $profile ): ?UserDTO {
		return $this->user_repo->create(
			array(
				'user_login'   => $this->generateUniqueLogin( $profile->displayName ?: $profile->firstName ),
				'user_email'   => $profile->email ?: '',
				'display_name' => $profile->displayName ?: $profile->firstName,
				'role'         => UserRole::Student->value,
				// Мета-поля для привязки соцсети
				'meta'         => array(
					"fs_social_{$provider->value}_id" => $profile->identifier,
					'fs_social_provider'              => $provider->value,
					'fs_avatar_url'                   => $profile->photoURL,
				),
			)
		);
	}

	/**
	 * Генерирует уникальный логин на основе базового имени.
	 *
	 * @param string $baseName Базовое имя (displayName или firstName)
	 *
	 * @return string
	 */
	private function generateUniqueLogin( string $baseName ): string {
		// sanitize_user() — очищает строку для использования в качестве логина
		$login = sanitize_user( $baseName, true );

		// bin2hex() — преобразует двоичные данные в шестнадцатеричную строку
		// random_bytes() — генерирует криптографически безопасные случайные байты
		return $login . '_' . bin2hex( random_bytes( 2 ) );
	}
}
