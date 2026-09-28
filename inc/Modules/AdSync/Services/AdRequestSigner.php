<?php

declare( strict_types=1 );

namespace Inc\Modules\AdSync\Services;

use Inc\Modules\AdSync\Config\AdSyncConfig;

/**
 * Class AdRequestSigner
 *
 * Подпись исходящих запросов сайта к серверу AdSync в офисе (push-модель).
 *
 * Схема (повторяется в Python один в один):
 *   X-Fs-Timestamp: <unix-время>
 *   X-Fs-Signature: hex( hmac_sha256( METHOD + "\n" + PATH + "\n" + TIMESTAMP + "\n" + BODY, секрет ) )
 * PATH — путь запроса без хоста и query (`/v1/jobs`), BODY — сырое тело (пусто у GET).
 * Метод и путь в подписи не дают переиграть подписанное тело на другой эндпоинт;
 * сервер проверяет окно времени (±300 с) и повтор `idempotency_key`.
 * Секрет — `FS_LMS_AD_HMAC_SECRET` в wp-config.php (то же значение в .env сервиса).
 *
 * @package Inc\Modules\AdSync\Services
 */
class AdRequestSigner {

	public function __construct(
		private readonly AdSyncConfig $config,
	) {}

	public function hasSecret(): bool {
		return '' !== $this->config->hmacSecret();
	}

	/**
	 * Заголовки подписи запроса.
	 *
	 * @param int|null $timestamp Время подписи (null — сейчас; параметр — для тестов)
	 *
	 * @return array<string, string>
	 */
	public function headers( string $method, string $path, string $body, ?int $timestamp = null ): array {
		$ts = (string) ( $timestamp ?? time() );

		return array(
			'X-Fs-Timestamp' => $ts,
			'X-Fs-Signature' => hash_hmac( 'sha256', self::canonical( $method, $path, $ts, $body ), $this->config->hmacSecret() ),
		);
	}

	/** Подписываемая строка — общая для сайта и сервиса. */
	public static function canonical( string $method, string $path, string $timestamp, string $body ): string {
		return strtoupper( $method ) . "\n" . $path . "\n" . $timestamp . "\n" . $body;
	}
}
