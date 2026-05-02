<?php

namespace Inc\Controllers;

use Inc\Callbacks\AuthCallbacks;
use Inc\Contracts\AuthStrategyInterface;
use Inc\Contracts\ServiceInterface;
use Inc\Core\BaseController;
use Inc\Enums\AuthProvider;
use Inc\Services\AuthService\AuthStrategies\GithubAuthStrategy;
use Inc\Services\AuthService\AuthStrategies\GoogleAuthStrategy;
use Inc\Services\AuthService\AuthStrategies\VkAuthStrategy;
use Inc\Services\AuthService\ProviderResolver;
use Inc\Shared\Traits\ErrorHandler;

/**
 * Class AuthController
 *
 * Контроллер аутентификации через социальные сети (Hybridauth).
 *
 * @package Inc\Controllers
 * @implements ServiceInterface
 *
 * ### Основные обязанности:
 *
 * 1. **Маршрутизация** — обработка кастомных маршрутов для входа через соцсети (/lms-auth/vk, /lms-auth/google).
 * 2. **Инициализация входа** — перенаправление на страницу авторизации провайдера.
 * 3. **Обработка callback'а** — получение данных пользователя после успешной авторизации.
 *
 * ### Архитектурная роль:
 *
 * Делегирует бизнес-логику стратегиям аутентификации (GoogleAuthStrategy, VkAuthStrategy и др.),
 * определение провайдера — ProviderResolver. Является точкой входа для всего функционала
 * аутентификации через соцсети.
 */
class AuthController extends BaseController implements ServiceInterface
{
    use ErrorHandler;  // Трейт с методами logException(), sendError()

    // Префикс маршрутов для аутентификации (URL: /lms-auth/{provider})
    private const string ROUTE_PREFIX = 'lms-auth';

    /**
     * Список доступных стратегий (провайдер → объект стратегии).
     *
     * @var array<string, AuthStrategyInterface>
     */
    private array $strategies = [];

    public function __construct(
        private readonly AuthCallbacks    $callbacks,
        private readonly ProviderResolver $provider_resolver,

        // Внедрение конкретных стратегий через DI-контейнер
        GoogleAuthStrategy                $google_strategy,
        VkAuthStrategy                    $vk_strategy,
        GithubAuthStrategy                $github_strategy,
    ) {
        parent::__construct();

        // Регистрация стратегий с привязкой к значению enum
        $this->strategies = [
            AuthProvider::GOOGLE->value    => $google_strategy,
            AuthProvider::VKONTAKTE->value => $vk_strategy,
            AuthProvider::GITHUB->value    => $github_strategy,
        ];
    }

    /**
     * Регистрирует все хуки и шорткоды контроллера.
     *
     * @return void
     */
    public function register(): void
    {
        // 'template_redirect' — хук, срабатывающий перед загрузкой шаблона темы
        add_action( 'template_redirect', [ $this, 'handleAuthRoutes' ] );

        // add_shortcode() — регистрирует шорткод для тестовой страницы авторизации
        add_shortcode( 'lms_auth_test', [ $this->callbacks, 'renderAuthTestPage' ] );
    }

    /**
     * Обрабатывает кастомные маршруты аутентификации.
     *
     * @return void
     */
    public function handleAuthRoutes(): void
    {
        // parse_url(, PHP_URL_PATH) — извлекает только путь из URL
        $path = trim( parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH ), '/' );

        // str_starts_with() — проверяет начало строки (PHP 8.0)
        if ( ! str_starts_with( $path, self::ROUTE_PREFIX . '/' ) ) {
            return;
        }

        // explode() — разбивает строку по разделителю '/'
        // array_filter() — удаляет пустые элементы
        // array_values() — переиндексирует массив
        $parts  = array_values( array_filter( explode( '/', $path ) ) );
        $action = $parts[1] ?? null;

        // Маршрут: /lms-auth/callback — обработка ответа от провайдера
        if ( $action === 'callback' ) {
            $this->processCallback();
            return;
        }

        // Если это /lms-auth/login — показываем выбор провайдеров
        if ( $action === 'login' ) {
            // Здесь может быть рендер страницы логина (заглушка)
            return;
        }

        // Если это /lms-auth/{provider} — вход через конкретного провайдера
        $provider = AuthProvider::fromRequest( (string) $action );
        if ( $provider ) {
            $this->processLogin( $provider );
        }
    }

    /**
     * Инициализирует процесс входа через социальную сеть.
     *
     * @param AuthProvider|null $provider Провайдер (vk, google, github)
     *
     * @return void
     */
    private function processLogin( ?AuthProvider $provider = null ): void
    {
        $provider = $provider ?? $this->provider_resolver->fromRequest();
        $strategy = $this->getStrategy( $provider );

        if ( ! $strategy ) {
            $this->sendError( 'unknown_provider', 'Провайдер не поддерживается или не настроен', 400 );
            return;
        }

        // login() — перенаправляет на страницу авторизации провайдера
        $strategy->login();
    }

    /**
     * Обрабатывает callback-запрос от провайдера после авторизации.
     *
     * @return void
     */
    private function processCallback(): void
    {
        $provider = $this->provider_resolver->fromCallback();
        $strategy = $this->getStrategy( $provider );

        if ( ! $strategy ) {
            $this->sendError( 'unknown_provider', 'Не удалось определить стратегию для callback', 400 );
            return;
        }

        try {
            // authenticate() — получает профиль и возвращает UserDTO
            $user = $strategy->authenticate();

            if ( $user ) {
                // apply_filters() — позволяет переопределить URL редиректа
                $redirect = apply_filters( 'lms_auth_redirect_url', home_url( '/wp-admin/profile.php' ), $user );
                // wp_safe_redirect() — безопасный редирект (только локальные URL)
                wp_safe_redirect( $redirect );
                exit;
            }

            $this->sendError( 'auth_failed', 'Ошибка авторизации через соцсеть', 401 );

        } catch ( \Exception $e ) {
            $this->logAuthError( $e, $provider );
            $this->sendError( 'auth_error', 'Техническая ошибка при обработке ответа', 500 );
        }
    }

    /**
     * Возвращает стратегию для указанного провайдера.
     *
     * @param AuthProvider|null $provider Провайдер
     *
     * @return AuthStrategyInterface|null
     */
    private function getStrategy( ?AuthProvider $provider ): ?AuthStrategyInterface
    {
        if ( ! $provider ) {
            return null;
        }
        return $this->strategies[ $provider->value ] ?? null;
    }

    /**
     * Логирует ошибку аутентификации.
     *
     * @param \Throwable   $e        Исключение
     * @param AuthProvider $provider Провайдер
     *
     * @return void
     */
    private function logAuthError( \Throwable $e, AuthProvider $provider ): void
    {
        $this->logException( $e, [
            'provider'  => $provider->value,
            'component' => 'auth',
        ] );
    }
}