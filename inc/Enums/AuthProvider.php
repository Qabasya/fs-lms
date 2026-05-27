<?php

declare( strict_types=1 );

namespace Inc\Enums;

/**
 * Enum AuthProvider
 *
 * Перечисление провайдеров аутентификации через социальные сети.
 *
 * @package Inc\Enums
 *
 * ### Основные обязанности:
 *
 * 1. **Хранение провайдеров** — перечисление поддерживаемых соцсетей (Google, VK, GitHub).
 * 2. **Преобразование из запроса** — определение провайдера по строке из PageRoutes.
 * 3. **Конфигурационные ключи** — получение ключа для настроек и Hybridauth.
 *
 * ### Архитектурная роль:
 *
 * Используется в AuthController, ProviderResolver и AuthConfigFactory
 * для типобезопасной идентификации провайдера авторизации.
 */
enum AuthProvider: string {
	case Google    = 'Google';
	case Vkontakte = 'VK';
	case Github    = 'Github';

	/**
	 * Возвращает человекочитаемое название для админки.
	 *
	 * @return string
	 */
	public function label(): string {
		return match ( $this ) {
			self::Google    => 'Google',
			self::Vkontakte => 'VK',
			self::Github    => 'Github',
		};
	}

	/**
	 * Создаёт провайдер из строки запроса (PageRoutes).
	 * Поддерживает различные варианты написания.
	 *
	 * @param string $value Строка из PageRoutes (например, 'google', 'vk', 'github')
	 *
	 * @return self|null
	 */
	public static function fromRequest( string $value ): ?self {
		// strtolower() — приводим к нижнему регистру
		// trim() — удаляем пробелы по краям
		$value = strtolower( trim( $value ) );

		return match ( $value ) {
			'google'                           => self::Google,
			'vk', 'vkontakte', 'vk.com'        => self::Vkontakte,
			'github', 'git hub'                => self::Github,
			default                            => null,
		};
	}

	/**
	 * Возвращает ключ для конфигурации плагина.
	 *
	 * @return string
	 */
	public function configKey(): string {
		return match ( $this ) {
			self::Google    => 'google',
			self::Vkontakte => 'vk',
			self::Github    => 'github',
		};
	}

	/**
	 * Возвращает ключ для Hybridauth (название провайдера в библиотеке).
	 *
	 * @return string
	 */
	public function hybridauthKey(): string {
		return match ( $this ) {
			self::Google    => 'Google',
			self::Vkontakte => 'Vkontakte',
			self::Github    => 'GitHub',
		};
	}
}
