<?php

namespace Inc\Controllers;

use Inc\Contracts\ServiceInterface;
use Inc\Core\BaseController;

use Inc\Services\AuthService;
use Inc\Enums\AuthProvider;

class AuthController extends BaseController implements ServiceInterface
{

    public function __construct(
        private readonly AuthService $auth_service
    ) {
        parent::__construct();
    }

    public function register(): void
    {
        add_action( 'template_redirect', [ $this, 'handleAuthRoutes' ] );
    }

    /**
     * Логика "роутинга" через разбор URL.
     */
    public function handleAuthRoutes(): void {
        $path = trim( parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ), '/' );
        $parts = explode( '/', $path );

        // Проверяем, начинается ли путь с lms-auth
        if ( empty( $parts[0] ) || $parts[0] !== 'lms-auth' ) {
            return;
        }

        // 1. Обработка Callback: site/lms-auth/callback
        if ( isset( $parts[1] ) && $parts[1] === 'callback' ) {
            $this->processCallback();
            exit;
        }

        // 2. Обработка Login: site/lms-auth/{provider}
        if ( isset( $parts[1] ) ) {
            $this->processLogin( $parts[1] );
            exit;
        }
    }

    /**
     * Запуск процесса авторизации.
     */
    private function processLogin( string $provider_name ): void {
        // Пытаемся найти провайдера в нашем Enum
        $provider = AuthProvider::tryFrom( ucfirst( strtolower( $provider_name ) ) );

        if ( ! $provider ) {
            wp_die( 'Неизвестный провайдер авторизации.', 'Ошибка LMS', [ 'response' => 404 ] );
        }

        // Получаем конфиг (пока хардкод, позже вынесем в SettingsManager)
        $config = $this->getAuthConfig();

        // Запускаем процесс (AuthService сам сделает редирект на сторону соцсети)
        $this->auth_service->authenticate( $provider, $config );
    }

    /**
     * Обработка возврата пользователя от соцсети.
     */
    private function processCallback(): void {
        // В callback Hybridauth сам знает, какой провайдер вернулся,
        // если мы передали его в URL или сессии.
        // Для надежности можно передать провайдера в параметре: site/lms-auth/callback?provider=Google
        $provider_name = $_GET['provider'] ?? '';
        $provider = AuthProvider::tryFrom( ucfirst( strtolower( $provider_name ) ) );

        if ( $provider ) {
            $user = $this->auth_service->authenticate( $provider, $this->getAuthConfig( $provider ) );

            if ( $user ) {
                // Всё прошло успешно, юзер залогинен сервисом
                wp_safe_redirect( admin_url( 'profile.php' ) );
                exit;
            }
        }

        wp_die( 'Ошибка авторизации. Попробуйте еще раз.' );
    }

    /**
     * Конфигурация. Добавим параметр $current_provider,
     * чтобы формировать уникальный callback URL.
     */
    private function getAuthConfig( ?AuthProvider $current_provider = null ): array {
        $callback = home_url( '/lms-auth/callback' );

        // Добавляем в callback параметр провайдера, чтобы не терять его
        if ( $current_provider ) {
            $callback = add_query_arg( 'provider', $current_provider->value, $callback );
        }

        return [
            'callback'  => $callback,
            'providers' => [
                'Google' => [
                    'enabled' => true,
                    'keys'    => [
                        'id'     => 'ВАШ_ID.apps.googleusercontent.com',
                        'secret' => 'ВАШ_СЕКРЕТ'
                    ],
                ],
                'VK' => [
                    'enabled' => true,
                    'keys'    => [
                        'id'     => 'ID_ПРИЛОЖЕНИЯ_ВК',
                        'secret' => 'СЕКРЕТНЫЙ_КЛЮЧ_ВК'
                    ],
                ],
            ],
            // Полезно для отладки, создаст файл в корне плагина
            'debug_mode' => true,
            'debug_file' => __DIR__ . '/hybridauth.log',
        ];
    }
}