<?php

namespace Inc\Controllers;

use Inc\Callbacks\AuthCallbacks;
use Inc\Contracts\ServiceInterface;
use Inc\Core\BaseController;

use Inc\Services\AuthService;
use Inc\Enums\AuthProvider;

class AuthController extends BaseController implements ServiceInterface
{

    public function __construct(
        private readonly AuthService $auth_service,
        private readonly AuthCallbacks $callbacks
    ) {
        parent::__construct();
    }

    public function register(): void
    {
        add_action( 'template_redirect', [ $this, 'handleAuthRoutes' ] );
        add_shortcode( 'lms_auth_test', [ $this->callbacks, 'renderAuthTestPage' ] );
    }

    /**
     * Логика "роутинга" через разбор URL.
     */
    public function handleAuthRoutes(): void {
        $path = trim( parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ), '/' );
        $parts = explode( '/', $path );

        if ( empty( $parts[0] ) || $parts[0] !== 'lms-auth' ) {
            return;
        }

        // 1. Обработка Callback: site/lms-auth/callback
        if ( isset( $parts[1] ) && $parts[1] === 'callback' ) {
            $this->processCallback();
            exit;
        }

        // 2. Обработка Login
        // Если путь /lms-auth/login?provider=github
        if ( isset( $parts[1] ) && $parts[1] === 'login' ) {
            $provider_name = $_GET['provider'] ?? '';
            $this->processLogin( $provider_name );
            exit;
        }

        // 3. Обработка прямого пути (на всякий случай): site/lms-auth/github
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
        // 1. Пробуем взять из GET (если мы сами его туда приклеили)
        $provider_name = $_GET['provider'] ?? '';

        // 2. Если в GET пусто, GitHub мог вернуть нас на чистый /callback
        // В этом случае мы можем попробовать определить провайдера по контексту
        // или запустить аутентификацию для GitHub по умолчанию, если пришел код.
        if ( empty( $provider_name ) && isset( $_GET['code'] ) ) {
            // Для теста форсируем GitHub, если видим, что это возврат от него
            $provider_name = 'github';
        }

        $provider = null;
        foreach ( AuthProvider::cases() as $case ) {
            if ( strcasecmp( $case->value, $provider_name ) === 0 ) {
                $provider = $case;
                break;
            }
        }

        if ( $provider ) {
            try {
                $user = $this->auth_service->authenticate( $provider, $this->getAuthConfig( $provider ) );

                if ( $user ) {
                    wp_safe_redirect( home_url( '/wp-admin/profile.php' ) );
                    exit;
                }
            } catch ( \Exception $e ) {
                // Если что-то пошло не так внутри Hybridauth, посмотрим ошибку
                wp_die( 'Hybridauth error: ' . $e->getMessage() );
            }
        }

        wp_die( 'Ошибка авторизации: не удалось определить провайдера.' );
    }

    /**
     * Конфигурация. Добавим параметр $current_provider,
     * чтобы формировать уникальный callback URL.
     */
    private function getAuthConfig( ?AuthProvider $current_provider = null ): array {
        // Получаем сохраненные настройки из базы
        $settings = get_option( 'fs_lms_auth_settings', [] );

        $callback = home_url( '/lms-auth/callback' );

        if ( $current_provider ) {
            // Приводим к нижнему регистру для URL: github, vk, google
            $callback = add_query_arg( 'provider', strtolower($current_provider->value), $callback );
        }

        return [
            'callback'  => $callback,
            'providers' => [
                'Google' => [
                    'enabled' => !empty($settings['google_enabled']),
                    'keys'    => [
                        'id'     => $settings['google_id'] ?? '',
                        'secret' => $settings['google_secret'] ?? ''
                    ],
                ],
                // ВАЖНО: Для Hybridauth используем ключ 'Vkontakte'
                'Vkontakte' => [
                    'enabled' => !empty($settings['vk_enabled']),
                    'keys'    => [
                        'id'     => $settings['vk_id'] ?? '',
                        'secret' => $settings['vk_secret'] ?? ''
                    ],
                ],
                // ВАЖНО: Для Hybridauth используем ключ 'GitHub'
                'GitHub' => [
                    'enabled' => !empty($settings['github_enabled']),
                    'keys'    => [
                        'id'     => $settings['github_id'] ?? '',
                        'secret' => $settings['github_secret'] ?? ''
                    ],
                ],
            ],
            'debug_mode' => true,
            'debug_file' => WP_CONTENT_DIR . '/hybridauth.log',
        ];
    }
}