<?php

declare( strict_types=1 );

namespace Inc\Modules\SmartCaptcha\Providers;

use Inc\Contracts\CaptchaProviderInterface;
use Inc\Modules\SmartCaptcha\Config\SmartCaptchaConfig;
use Inc\Shared\PluginLogger;

/**
 * Class YandexSmartCaptchaProvider
 *
 * Провайдер капчи на базе Yandex SmartCaptcha (модуль SmartCaptcha).
 * Ключи берёт из опции модуля (`SmartCaptchaConfig`), не из core-конфига.
 *
 * @package Inc\Modules\SmartCaptcha\Providers
 *
 * ### Поведение:
 *
 * - Не настроен (нет серверного ключа) → validate() возвращает true, getSiteKey() пуст,
 *   isConfigured() false. Форма не блокируется — защита держится на OTP + honeypot + rate-limit.
 * - Настроен → серверная валидация токена через API SmartCaptcha.
 *
 * ### Fail-open:
 *
 * При сетевой ошибке или некорректном ответе validate() возвращает true (не блокируем
 * легитимных пользователей при недоступности Яндекса) — за капчей стоят OTP и прочие контроли.
 *
 * @see https://yandex.cloud/ru/docs/smartcaptcha/
 */
readonly class YandexSmartCaptchaProvider implements CaptchaProviderInterface {

	/** Endpoint серверной валидации токена. */
	private const VALIDATE_URL = 'https://smartcaptcha.yandexcloud.net/validate';

	public function __construct(
		private SmartCaptchaConfig $config,
	) {}

	public function validate( string $token, string $remoteIp ): bool {
		$serverKey = $this->config->serverKey();

		// Капча не настроена — пропускаем (поведение NullCaptchaProvider).
		if ( '' === $serverKey ) {
			return true;
		}

		// Настроена, но токен пуст → точно не пройдена.
		if ( '' === $token ) {
			return false;
		}

		$response = wp_remote_post( self::VALIDATE_URL, array(
			'timeout' => 5,
			'body'    => array(
				'secret' => $serverKey,
				'token'  => $token,
				'ip'     => $remoteIp,
			),
		) );

		if ( is_wp_error( $response ) ) {
			PluginLogger::warning( 'YandexSmartCaptcha', 'validate request failed (fail-open)', array( 'error' => $response->get_error_message() ) );
			return true;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code || ! is_array( $body ) ) {
			PluginLogger::warning( 'YandexSmartCaptcha', 'unexpected validate response (fail-open)', array( 'code' => $code ) );
			return true;
		}

		return isset( $body['status'] ) && 'ok' === $body['status'];
	}

	public function getSiteKey(): string {
		return $this->config->siteKey();
	}

	public function isConfigured(): bool {
		return '' !== $this->config->siteKey() && '' !== $this->config->serverKey();
	}
}
