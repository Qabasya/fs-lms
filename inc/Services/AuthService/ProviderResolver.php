<?php

namespace Inc\Services\AuthService;

use Inc\Enums\AuthProvider;
use Inc\Repositories\SettingsRepository;
use Inc\Shared\Traits\Sanitizer;

/**
 * Class ProviderResolver
 *
 * Сервис для определения провайдера аутентификации из запроса.
 *
 * @package Inc\Services
 *
 * ### Основные обязанности:
 *
 * 1. **Определение из параметров** — получение провайдера из GET-параметра 'provider'.
 * 2. **Определение из callback'а** — автоматическое определение провайдера по наличию кода в ответе.
 *
 * ### Архитектурная роль:
 *
 * Используется в AuthController для определения, через какую соцсеть
 * пользователь пытается авторизоваться.
 */
class ProviderResolver
{
    use Sanitizer;  // Трейт с методами sanitizeText(), sanitizeBool()

    public function __construct(
        private readonly SettingsRepository $settings_repo
    ) {}

    /**
     * Определяет провайдера из GET-параметра 'provider'.
     *
     * @return AuthProvider|null
     */
    public function fromRequest(): ?AuthProvider
    {
        // sanitizeText() — получаем значение параметра 'provider' из $_GET
        $provider = $this->sanitizeText( 'provider', 'GET' );

        if ( $provider === '' ) {
            return null;
        }

        return AuthProvider::fromRequest( $provider );
    }

    /**
     * Определяет провайдера из callback-запроса.
     * Использует параметр 'provider' или ищет первый включённый провайдер.
     *
     * @return AuthProvider|null
     */
    public function fromCallback(): ?AuthProvider
    {
        // Сначала пробуем получить провайдер из явного параметра
        $provider = $this->fromRequest();

        if ( $provider ) {
            return $provider;
        }

        // Если параметра нет — проверяем наличие code (OAuth-код авторизации)
        // sanitizeBool() проверяет наличие параметра 'code' в GET-запросе
        if ( ! $this->sanitizeBool( 'code', 'GET' ) && ! isset( $_GET['code'] ) ) {
            return null;
        }

        // Возвращаем первый включённый провайдер из настроек
        foreach ( AuthProvider::cases() as $case ) {
            if ( $this->settings_repo->isProviderEnabled( $case->value ) ) {
                return $case;
            }
        }

        return null;
    }
}