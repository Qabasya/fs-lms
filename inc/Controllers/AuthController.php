<?php

namespace Inc\Controllers;

use Inc\Callbacks\AuthCallbacks;
use Inc\Contracts\ServiceInterface;
use Inc\Core\BaseController;
use Inc\Enums\AuthProvider;
use Inc\Services\AuthConfigFactory;
use Inc\Services\AuthService;
use Inc\Services\ProviderResolver;
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
 * Делегирует бизнес-логику AuthService, определение провайдера — ProviderResolver,
 * а создание конфигурации — AuthConfigFactory. Является точкой входа для всего
 * функционала аутентификации через соцсети.
 */
class AuthController extends BaseController implements ServiceInterface
{
    use ErrorHandler;  // Трейт с методами logException(), sendError()

    // Префикс маршрутов для аутентификации (URL: /lms-auth/{provider})
    private const string ROUTE_PREFIX = 'lms-auth';

    public function __construct(
        private readonly AuthService       $auth_service,
        private readonly AuthCallbacks     $callbacks,
        private readonly ProviderResolver  $provider_resolver,
        private readonly AuthConfigFactory $config_factory,
    ) {
        parent::__construct();
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
        // parse_url() — разбирает URL на компоненты
        // PHP_URL_PATH — получить только путь
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

        // Маршрут: /lms-auth/login — страница выбора провайдера
        if ( $action === 'login' ) {
            $this->processLogin();
            return;
        }

        // Маршрут: /lms-auth/{provider} — вход через конкретного провайдера
        $provider = AuthProvider::fromRequest( (string) $action );

        if ( ! $provider ) {
            $this->sendError( 'unknown_provider', 'Неизвестный провайдер', 404 );
            return;
        }

        $this->processLogin( $provider );
    }

    /**
     * Инициализирует процесс входа через социальную сеть.
     *
     * @param AuthProvider|null $provider Провайдер (vk, google, facebook)
     *
     * @return void
     */
    private function processLogin( ?AuthProvider $provider = null ): void
    {
        // Если провайдер не передан — пытаемся определить из запроса
        if ( ! $provider ) {
            $provider = $this->provider_resolver->fromRequest();
        }

        if ( ! $provider ) {
            $this->sendError( 'unknown_provider', 'Провайдер не указан', 400 );
            return;
        }

        // Создание конфигурации для Hybridauth
        $config = $this->config_factory->make( $provider );

        // Перенаправление на страницу авторизации соцсети
        $this->auth_service->startLogin( $provider, $config );
    }

    /**
     * Обрабатывает callback-запрос от провайдера после авторизации.
     *
     * @return void
     */
    private function processCallback(): void
    {
        // Определяем провайдера из параметров callback'а
        $provider = $this->provider_resolver->fromCallback();

        if ( ! $provider instanceof AuthProvider ) {
            $this->sendError( 'unknown_provider', 'Не удалось определить провайдера', 400 );
            return;
        }

        try {
            $config = $this->config_factory->make( $provider );
            // Аутентификация и создание/вход пользователя
            $user = $this->auth_service->authenticate( $provider, $config );

            if ( $user ) {
                // apply_filters() — позволяет переопределить URL редиректа
                $redirect = apply_filters( 'lms_auth_redirect_url', home_url( '/wp-admin/profile.php' ), $user );
                // wp_safe_redirect() — безопасный редирект (только локальные URL)
                wp_safe_redirect( $redirect );
                exit;
            }

            $this->sendError( 'auth_failed', 'Не удалось получить данные пользователя', 401 );

        } catch ( \Exception $e ) {
            $this->logAuthError( $e, $provider );
            $this->sendError( 'auth_error', 'Произошла ошибка при авторизации', 500 );
        }
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