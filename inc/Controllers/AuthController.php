<?php

namespace Inc\Controllers;

use Inc\Callbacks\AuthCallbacks;
use Inc\Contracts\ServiceInterface;
use Inc\Core\BaseController;

use Inc\Services\AuthService;
use Inc\Enums\AuthProvider;
use Inc\Repositories\SettingsRepository;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\ErrorHandler;
use Inc\Shared\Traits\Sanitizer;

class AuthController extends BaseController implements ServiceInterface
{
    use Authorizer, ErrorHandler, Sanitizer;

    private const string ROUTE_PREFIX = 'lms-auth';

    public function __construct(
        private readonly AuthService        $auth_service,
        private readonly AuthCallbacks      $callbacks,
        private readonly SettingsRepository $settings_repo
    )
    {
        parent::__construct();
    }

    public function register(): void
    {
        add_action('template_redirect', [$this, 'handleAuthRoutes']);
        add_shortcode('lms_auth_test', [$this->callbacks, 'renderAuthTestPage']);
    }

    /**
     * Логика "роутинга" через разбор URL.
     */
    public function handleAuthRoutes(): void
    {
        $path = trim(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/');

        if (!str_starts_with($path, self::ROUTE_PREFIX . '/')) {
            return;
        }

        $parts = array_values(array_filter(explode('/', $path)));
        $action = $parts[1] ?? null;

        if ($action === 'callback') {
            $this->processCallback();
            return;
        }

        if ($action === 'login') {
            $this->processLogin();
            return;
        }

        // fallback: /lms-auth/{provider}
        $provider = AuthProvider::fromRequest((string)$action);

        $this->processLogin($provider);
    }

    private function getCurrentPath(): string
    {
        return trim(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/');
    }

    private function getProviderFromRequest(): ?AuthProvider
    {
        $provider_name = $this->sanitizeText('provider', 'GET');

        if (empty($provider_name)) {
            return null;
        }

        $normalized = ucfirst(strtolower($provider_name));

        return AuthProvider::tryFrom($normalized);
    }

    /**
     * Запуск процесса авторизации.
     */
    private function processLogin(?AuthProvider $provider = null): void
    {
        $this->auth_service->login($provider);
    }

    /**
     * Обработка возврата пользователя от соцсети.
     */
    private function processCallback(): void
    {
        $provider = $this->resolveProviderFromCallback();

        if (!$provider instanceof AuthProvider) {
            $this->sendError('unknown_provider', 'Не удалось определить провайдера', 400);
            return;
        }

        try {
            $config = $this->getAuthConfig($provider);
            $user = $this->auth_service->authenticate($provider, $config);

            if ($user) {
                $redirect = apply_filters('lms_auth_redirect_url', home_url('/wp-admin/profile.php'), $user);
                wp_safe_redirect($redirect);
                exit;
            }

            $this->sendError('auth_failed', 'Не удалось получить данные пользователя', 401);

        } catch (\Exception $e) {
            $this->logAuthError($e, $provider);
            $this->sendError('auth_error', 'Произошла ошибка при авторизации', 500);
        }
    }

    private function resolveProviderFromCallback(): ?AuthProvider
    {
        $provider = $this->getProviderFromRequest();
        if ($provider) {
            return $provider;
        }

        // Fallback: перебор включенных провайдеров при наличии кода
        if (isset($_GET['code'])) {
            foreach (AuthProvider::cases() as $case) {
                if ($this->settings_repo->isProviderEnabled($case->value)) {
                    return $case;
                }
            }
        }

        return null;
    }


    /**
     * Конфигурация. Добавим параметр $current_provider,
     * чтобы формировать уникальный callback URL.
     */
    private function getAuthConfig(?AuthProvider $current_provider = null): array
    {
        $callback_base = home_url('/lms-auth/callback');
        $callback = $current_provider
            ? add_query_arg('provider', strtolower($current_provider->value), $callback_base)
            : $callback_base;

        $settings = $this->settings_repo->readAll();

        $buildProviderConfig = fn(string $provider_key) => [
            'enabled' => !empty($settings[$provider_key . '_enabled']),
            'keys' => [
                'id' => $settings[$provider_key . '_id'] ?? '',
                'secret' => $settings[$provider_key . '_secret'] ?? '',
            ],
        ];

        return [
            'callback' => $callback,
            'debug_mode' => defined('WP_DEBUG') && WP_DEBUG,
            'debug_file' => WP_CONTENT_DIR . '/hybridauth.log',
            'providers' => [
                'Google' => $buildProviderConfig('google'),
                'Vkontakte' => $buildProviderConfig('vk'),
                'GitHub' => $buildProviderConfig('github'),
            ],
        ];
    }

    private function getProviderKey(AuthProvider $provider): string
    {
        return match ($provider) {
            AuthProvider::VKONTAKTE => 'Vkontakte',
            AuthProvider::GITHUB => 'GitHub',
            AuthProvider::GOOGLE => 'Google',
            default => $provider->value,
        };
    }
}