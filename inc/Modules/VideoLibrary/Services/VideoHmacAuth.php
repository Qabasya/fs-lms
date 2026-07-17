<?php

declare( strict_types=1 );

namespace Inc\Modules\VideoLibrary\Services;

use Inc\Modules\VideoLibrary\Config\VideoLibraryConfig;

/**
 * Class VideoHmacAuth
 *
 * Аутентификация запросов сервиса fs-video-uploader к REST-эндпоинтам модуля.
 * Схема — единая для интеграций fs-lms (см. FS_LMS_API.md §2): заголовки
 *   X-Fs-Timestamp: <unix-время>
 *   X-Fs-Signature: hex( hmac_sha256( timestamp + "." + rawBody, FS_LMS_VIDEO_HMAC_SECRET ) )
 * Сервер проверяет свежесть timestamp (±300с, анти-replay) и подпись (constant-time).
 *
 * Класс — копия AdHmacAuth со своим секретом: листья не ссылаются друг на друга
 * (ModularArchitecture.md §3.3); при третьем потребителе — вынести общий HmacAuth в Kernel.
 *
 * @package Inc\Modules\VideoLibrary\Services
 */
class VideoHmacAuth {

	private const MAX_SKEW = 300;

	public function __construct(
		private readonly VideoLibraryConfig $config,
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
