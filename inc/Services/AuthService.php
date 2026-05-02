<?php

declare( strict_types=1 );

namespace Inc\Services;

use Hybridauth\Hybridauth;
use Inc\Enums\AuthProvider;
use Inc\Repositories\UserRepository;
use Inc\DTO\UserDTO;
use Inc\Enums\UserRole;


/**
 * Сервис для управления процессом авторизации.
 */
class AuthService {

    private ?Hybridauth $hybridauth = null;

    public function __construct(
        private readonly UserRepository $user_repo
    ) {}

    /**
     * Основной метод входа. Принимает Enum провайдера и массив конфига.
     */
    public function authenticate( AuthProvider $provider, array $config ): ?UserDTO {
        try {
            $this->init( $config );

            $adapter = $this->hybridauth->authenticate( $provider->value );
            $profile = $adapter->getUserProfile();

            // Важно: отключаемся от адаптера, чтобы не держать лишних соединений
            $adapter->disconnect();

            // 1. Ищем по Social ID
            $user = $this->user_repo->getBySocialId( $provider->value, (string) $profile->identifier );

            // 2. Если не нашли, ищем по Email
            if ( ! $user && ! empty( $profile->email ) ) {
                $user = $this->user_repo->getByEmail( $profile->email );

                // Если нашли по Email — привязываем соцсеть
                if ( $user ) {
                    $this->user_repo->updateMeta( $user->id, [
                        "fs_social_{$provider->value}_id" => $profile->identifier
                    ]);
                }
            }

            // 3. Если всё еще нет юзера — регистрируем
            if ( ! $user ) {
                $user = $this->registerSocialUser( $provider, $profile );
            }

            // 4. Если юзер успешно найден или создан — логиним его в WP
            if ( $user ) {
                $this->login( $user );
            }

            return $user;

        } catch ( \Exception $e ) {
            error_log( 'LMS Auth Error: ' . $e->getMessage() );
            return null;
        }
    }

    /**
     * Реальный вход в WordPress (сессия, куки).
     */
    public function login( UserDTO $user ): void {
        // Устанавливаем текущего пользователя
        wp_set_current_user( $user->id );

        // Генерируем куки (true означает "запомнить меня")
        wp_set_auth_cookie( $user->id, true );

        // Вызываем стандартное событие WP (для совместимости с другими плагинами)
        do_action( 'wp_login', $user->email, get_userdata( $user->id ) );
    }


    private function init( array $config ): void {
        if ( null === $this->hybridauth ) {
            $this->hybridauth = new Hybridauth( $config );
        }
    }

    /**
     * Создает нового пользователя на основе данных из Hybridauth.
     */
    private function registerSocialUser( AuthProvider $provider, $profile ): ?UserDTO {
        return $this->user_repo->create([
            'user_login'    => $this->generateUniqueLogin( $profile->displayName ?: $profile->firstName ),
            'user_email'    => $profile->email ?: '',
            'display_name'  => $profile->displayName ?: $profile->firstName,
            'role'          => UserRole::Student->value,
            'meta'          => [
                "fs_social_{$provider->value}_id" => $profile->identifier,
                'fs_social_provider'              => $provider->value,
                'fs_avatar_url'                   => $profile->photoURL
            ]
        ]);
    }

    // Тут еще подумать, может убрать эту приписку в конце
    private function generateUniqueLogin( string $baseName ): string {
        $login = sanitize_user( $baseName, true );
        // Добавляем случайный хвост для уникальности
        return $login . '_' . bin2hex( random_bytes( 2 ) );
    }
}