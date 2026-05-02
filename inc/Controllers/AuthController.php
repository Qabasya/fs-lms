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

class AuthController extends BaseController implements ServiceInterface
{
    use ErrorHandler;

    private const string ROUTE_PREFIX = 'lms-auth';

    public function __construct(
        private readonly AuthService       $auth_service,
        private readonly AuthCallbacks     $callbacks,
        private readonly ProviderResolver  $provider_resolver,
        private readonly AuthConfigFactory $config_factory,
    ) {
        parent::__construct();
    }

    public function register(): void
    {
        add_action( 'template_redirect', [ $this, 'handleAuthRoutes' ] );
        add_shortcode( 'lms_auth_test', [ $this->callbacks, 'renderAuthTestPage' ] );
    }

    public function handleAuthRoutes(): void
    {
        $path = trim( parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH ), '/' );

        if ( ! str_starts_with( $path, self::ROUTE_PREFIX . '/' ) ) {
            return;
        }

        $parts  = array_values( array_filter( explode( '/', $path ) ) );
        $action = $parts[1] ?? null;

        if ( $action === 'callback' ) {
            $this->processCallback();
            return;
        }

        if ( $action === 'login' ) {
            $this->processLogin();
            return;
        }

        $provider = AuthProvider::fromRequest( (string) $action );

        if ( ! $provider ) {
            $this->sendError( 'unknown_provider', 'Неизвестный провайдер', 404 );
            return;
        }

        $this->processLogin( $provider );
    }

    private function processLogin( ?AuthProvider $provider = null ): void
    {
        if ( ! $provider ) {
            $provider = $this->provider_resolver->fromRequest();
        }

        if ( ! $provider ) {
            $this->sendError( 'unknown_provider', 'Провайдер не указан', 400 );
            return;
        }

        $config = $this->config_factory->make( $provider );
        $this->auth_service->startLogin( $provider, $config );
    }

    private function processCallback(): void
    {
        $provider = $this->provider_resolver->fromCallback();

        if ( ! $provider instanceof AuthProvider ) {
            $this->sendError( 'unknown_provider', 'Не удалось определить провайдера', 400 );
            return;
        }

        try {
            $config = $this->config_factory->make( $provider );
            $user   = $this->auth_service->authenticate( $provider, $config );

            if ( $user ) {
                $redirect = apply_filters( 'lms_auth_redirect_url', home_url( '/wp-admin/profile.php' ), $user );
                wp_safe_redirect( $redirect );
                exit;
            }

            $this->sendError( 'auth_failed', 'Не удалось получить данные пользователя', 401 );

        } catch ( \Exception $e ) {
            $this->logAuthError( $e, $provider );
            $this->sendError( 'auth_error', 'Произошла ошибка при авторизации', 500 );
        }
    }

    private function logAuthError( \Throwable $e, AuthProvider $provider ): void
    {
        $this->logException( $e, [
            'provider'  => $provider->value,
            'component' => 'auth',
        ] );
    }
}