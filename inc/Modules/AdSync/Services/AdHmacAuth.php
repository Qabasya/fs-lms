<?php

declare( strict_types=1 );

namespace Inc\Modules\AdSync\Services;

use Inc\Modules\AdSync\Config\AdSyncConfig;

/**
 * Class AdHmacAuth
 *
 * Аутентификация запросов Python-сервиса к REST-эндпоинтам модуля.
 * Схема (легко повторить в Python): заголовки
 *   X-Fs-Timestamp: <unix-время>
 *   X-Fs-Signature: hex( hmac_sha256( timestamp + "." + rawBody, FS_LMS_AD_HMAC_SECRET ) )
 * Сервер проверяет свежесть timestamp (±300с, анти-replay) и подпись (constant-time).
 *
 * @package Inc\Modules\AdSync\Services
 */
class AdHmacAuth {

	private const MAX_SKEW = 300;

	public function __construct(
		private readonly AdSyncConfig $config,
	) {}

	/** Проверяет подпись REST-запроса. permission_callback. */
	public function verify( \WP_REST_Request $request ): bool {
		$secret = $this->config->hmacSecret();
		if ( '' === $secret ) {
			return false;
		}

		$ts  = (string) $request->get_header( 'X-Fs-Timestamp' );
		$sig = (string) $request->get_header( 'X-Fs-Signature' );
		if ( '' === $ts || '' === $sig ) {
			return false;
		}
		if ( abs( time() - (int) $ts ) > self::MAX_SKEW ) {
			return false;
		}

		$body     = (string) $request->get_body();
		$expected = hash_hmac( 'sha256', $ts . '.' . $body, $secret );

		return hash_equals( $expected, $sig );
	}
}
