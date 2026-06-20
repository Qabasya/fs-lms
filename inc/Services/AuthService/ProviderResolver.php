<?php

declare( strict_types=1 );

namespace Inc\Services\AuthService;

use Inc\Enums\Auth\AuthProvider;
use Inc\Shared\Traits\Sanitizer;

/**
 * Class ProviderResolver
 *
 * Сервис для определения провайдера аутентификации из запроса.
 *
 * @package Inc\Services\AuthService
 *
 * ### Основные обязанности:
 *
 * 1. **Определение из параметров** — получение провайдера из GET-параметра 'provider'.
 * 2. **Определение из callback'а** — получение провайдера из callback-запроса.
 *
 * ### Архитектурная роль:
 *
 * Используется в AuthController для определения, через какую соцсеть
 * пользователь пытается авторизоваться.
 */
class ProviderResolver {

	use Sanitizer;  // Трейт с методами sanitizeText() для безопасного получения данных

	/**
	 * Определяет провайдера из GET-параметра 'provider'.
	 *
	 * @return AuthProvider|null
	 */
	public function fromRequest(): ?AuthProvider {
		// sanitizeText() — получаем значение параметра 'provider' из $_GET
		$provider = $this->sanitizeText( 'provider', 'GET' );

		if ( '' === $provider ) {
			return null;
		}

		return AuthProvider::fromRequest( $provider );
	}

	/**
	 * Определяет провайдера из callback-запроса.
	 * В текущей реализации просто делегирует в fromRequest().
	 *
	 * @return AuthProvider|null
	 */
	public function fromCallback(): ?AuthProvider {
		return $this->fromRequest();
	}
}