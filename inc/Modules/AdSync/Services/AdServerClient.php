<?php

declare( strict_types=1 );

namespace Inc\Modules\AdSync\Services;

use Inc\Modules\AdSync\Config\AdSyncConfig;
use Inc\Modules\AdSync\DTO\AdServerResponseDTO;

/**
 * Class AdServerClient
 *
 * HTTP-транспорт к серверу AdSync в офисе (`https://<белый IP>:8443`).
 *
 * Сертификат сервера самоподписанный, с IP в SAN: сайт доверяет ТОЛЬКО ему —
 * файл `.crt` из константы `FS_LMS_AD_SERVER_CERT` передаётся в `sslcertificates`
 * вместо общего набора корневых сертификатов. Подменить сервер нельзя даже чужим
 * валидным сертификатом. Без файла запрос не уходит вовсе.
 *
 * @package Inc\Modules\AdSync\Services
 */
class AdServerClient {

	/** Таймаут одного задания: создание учётки в AD занимает доли секунды. */
	public const int TIMEOUT_JOB = 5;

	/** Сверка передаёт весь список активных логинов — даём больше времени. */
	public const int TIMEOUT_RECONCILE = 30;

	public function __construct(
		private readonly AdSyncConfig    $config,
		private readonly AdRequestSigner $signer,
	) {}

	/**
	 * Готов ли транспорт: адрес, секрет и сертификат заданы.
	 *
	 * @return string '' — готов, иначе причина
	 */
	public function notReadyReason(): string {
		return match ( true ) {
			'' === $this->config->serverUrl()     => 'Не указан адрес сервера в офисе.',
			! $this->signer->hasSecret()          => 'Не задан FS_LMS_AD_HMAC_SECRET в wp-config.php.',
			! $this->config->hasServerCert()      => 'Не найден файл сертификата сервера (FS_LMS_AD_SERVER_CERT).',
			default                               => '',
		};
	}

	/**
	 * @param array<string, mixed>|null $body Тело (JSON); null — GET без тела
	 */
	public function request( string $method, string $path, ?array $body = null, int $timeout = self::TIMEOUT_JOB ): AdServerResponseDTO {
		$reason = $this->notReadyReason();
		if ( '' !== $reason ) {
			return new AdServerResponseDTO( 0, null, $reason );
		}

		$url     = $this->config->serverUrl() . $path;
		$rawBody = null === $body ? '' : (string) wp_json_encode( $body, JSON_UNESCAPED_UNICODE );
		$signed  = (string) ( wp_parse_url( $url, PHP_URL_PATH ) ?: $path );

		$started  = microtime( true );
		$response = wp_remote_request( $url, array(
			'method'          => strtoupper( $method ),
			'timeout'         => $timeout,
			'redirection'     => 0,
			'sslverify'       => true,
			'sslcertificates' => $this->config->serverCertPath(),
			'headers'         => array_merge(
				array( 'Content-Type' => 'application/json', 'Accept' => 'application/json' ),
				$this->signer->headers( $method, $signed, $rawBody )
			),
			'body'            => null === $body ? null : $rawBody,
		) );
		$seconds = microtime( true ) - $started;

		if ( is_wp_error( $response ) ) {
			return new AdServerResponseDTO( 0, null, $response->get_error_message(), $seconds );
		}

		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		return new AdServerResponseDTO(
			(int) wp_remote_retrieve_response_code( $response ),
			is_array( $decoded ) ? $decoded : null,
			'',
			$seconds
		);
	}
}
