<?php

declare( strict_types=1 );

namespace Inc\Services;

use Hybridauth\Hybridauth;
use Inc\Enums\AuthProvider;
use Inc\Repositories\UserRepository;
use Inc\DTO\UserDTO;
use Inc\Enums\UserRole;

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
     * Инициирует OAuth-редирект к провайдеру.
     *
     * @param AuthProvider $provider Провайдер (Google, VK, GitHub)
     * @param array        $config   Конфигурация Hybridauth
     *
     * @return void
     */
    public function startLogin( AuthProvider $provider, array $config ): void {
        $this->init( $config );
        // authenticate() — перенаправляет на страницу авторизации провайдера
        $this->hybridauth->authenticate( $provider->hybridauthKey() );
    }

    /**
     * Обрабатывает возврат от провайдера.
     *
     * @param AuthProvider $provider Провайдер
     * @param array        $config   Конфигурация Hybridauth
     *
     * @return UserDTO|null
     */
    public function authenticate( AuthProvider $provider, array $config ): ?UserDTO {
        try {
            $this->init( $config );

            $adapter = $this->hybridauth->authenticate( $provider->hybridauthKey() );
            // getUserProfile() — получает профиль пользователя из соцсети
            $profile = $adapter->getUserProfile();

            // disconnect() — закрывает соединение с провайдером
            $adapter->disconnect();

            // 1. Поиск пользователя по Social ID (мета-поле fs_social_{provider}_id)
            $user = $this->user_repo->getBySocialId( $provider->value, (string) $profile->identifier );

            // 2. Если не нашли — ищем по Email
            if ( ! $user && ! empty( $profile->email ) ) {
                $user = $this->user_repo->getByEmail( $profile->email );

                // Если нашли по Email — привязываем соцсеть к существующему аккаунту
                if ( $user ) {
                    $this->user_repo->updateMeta( $user->id, [
                        "fs_social_{$provider->value}_id" => $profile->identifier
                    ] );
                }
            }

            // 3. Если пользователь не найден — регистрируем нового
            if ( ! $user ) {
                $user = $this->registerSocialUser( $provider, $profile );
            }

            // 4. Если пользователь успешно найден или создан — выполняем вход
            if ( $user ) {
                $this->login( $user );
            }

            return $user;

        } catch ( \Exception $e ) {
            // error_log() — записывает ошибку в лог PHP
            error_log( 'LMS Auth Error: ' . $e->getMessage() );
            return null;
        }
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
     * Инициализирует объект Hybridauth.
     *
     * @param array $config Конфигурация
     *
     * @return void
     */
    private function init( array $config ): void {
        if ( null === $this->hybridauth ) {
            $this->hybridauth = new Hybridauth( $config );
        }
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
        return $this->user_repo->create( [
            'user_login'    => $this->generateUniqueLogin( $profile->displayName ?: $profile->firstName ),
            'user_email'    => $profile->email ?: '',
            'display_name'  => $profile->displayName ?: $profile->firstName,
            'role'          => UserRole::Student->value,
            // Мета-поля для привязки соцсети
            'meta'          => [
                "fs_social_{$provider->value}_id" => $profile->identifier,
                'fs_social_provider'              => $provider->value,
                'fs_avatar_url'                   => $profile->photoURL
            ]
        ] );
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