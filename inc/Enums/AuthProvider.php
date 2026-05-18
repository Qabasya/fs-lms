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
 * 2. **Преобразование из запроса** — определение провайдера по строке из URL.
 * 3. **Конфигурационные ключи** — получение ключа для настроек и Hybridauth.
 *
 * ### Архитектурная роль:
 *
 * Используется в AuthController, ProviderResolver и AuthConfigFactory
 * для типобезопасной идентификации провайдера авторизации.
 */
enum AuthProvider: string {
	case GOOGLE    = 'Google';
	case VKONTAKTE = 'VK';
	case GITHUB    = 'Github';

	/**
	 * Возвращает человекочитаемое название для админки.
	 *
	 * @return string
	 */
	public function label(): string {
		return match ( $this ) {
			self::GOOGLE    => 'Google Auth',
			self::VKONTAKTE => 'ВКонтакте',
			self::GITHUB    => 'Github',
		};
	}

	/**
	 * Создаёт провайдер из строки запроса (URL).
	 * Поддерживает различные варианты написания.
	 *
	 * @param string $value Строка из URL (например, 'google', 'vk', 'github')
	 *
	 * @return self|null
	 */
	public static function fromRequest( string $value ): ?self {
		// strtolower() — приводим к нижнему регистру
		// trim() — удаляем пробелы по краям
		$value = strtolower( trim( $value ) );

		return match ( $value ) {
			'google'                           => self::GOOGLE,
			'vk', 'vkontakte', 'vk.com'        => self::VKONTAKTE,
			'github', 'git hub'                => self::GITHUB,
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
			self::GOOGLE    => 'google',
			self::VKONTAKTE => 'vk',
			self::GITHUB    => 'github',
		};
	}

	/**
	 * Возвращает ключ для Hybridauth (название провайдера в библиотеке).
	 *
	 * @return string
	 */
	public function hybridauthKey(): string {
		return match ( $this ) {
			self::GOOGLE    => 'Google',
			self::VKONTAKTE => 'Vkontakte',
			self::GITHUB    => 'GitHub',
		};
	}
}
