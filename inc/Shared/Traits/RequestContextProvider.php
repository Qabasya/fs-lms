<?php

declare( strict_types=1 );

namespace Inc\Shared\Traits;

use Inc\DTO\RequestContextDTO;

/**
 * Trait RequestContextProvider
 *
 * Собирает контекст текущего HTTP-запроса для записи в журналы аудита и PII-доступа.
 *
 * @package Inc\Shared\Traits
 *
 * ### Основные обязанности:
 *
 * 1. **Определение IP** — читает REMOTE_ADDR; учитывает X-Forwarded-For только для
 *    доверенных прокси из фильтра `fs_lms_trusted_proxies`.
 * 2. **Хранение IP как строки** — передаёт IP в виде текста (varchar(45)),
 *    поддерживает IPv4 и IPv6 (до 45 символов).
 * 3. **Идентификация актора** — `get_current_user_id()` (0 для анонимных запросов).
 *
 * ### Архитектурная роль:
 *
 * Используется в AuditService и PersonReader для автоматического заполнения
 * контекста запроса без ручной передачи IP/UA/actor в каждый вызов.
 *
 * ### Безопасность:
 *
 * X-Forwarded-For не доверяется по умолчанию — его можно подделать. Заголовок
 * учитывается только если `$_SERVER['REMOTE_ADDR']` входит в список доверенных
 * прокси, передаваемый через фильтр `fs_lms_trusted_proxies`.
 */
trait RequestContextProvider {

	/**
	 * Создаёт RequestContextDTO из текущего HTTP-запроса.
	 *
	 * @return RequestContextDTO
	 */
	public function requestContext(): RequestContextDTO {
		$remoteAddr = (string) ( $_SERVER['REMOTE_ADDR'] ?? '' );
		$ip         = $this->resolveClientIp( $remoteAddr );

		return new RequestContextDTO(
			ip:          $ip,
			userAgent:   (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ),
			actorUserId: get_current_user_id(),
		);
	}

	/**
	 * Определяет реальный IP клиента с учётом доверенных прокси.
	 *
	 * Список доверенных прокси задаётся через фильтр `fs_lms_trusted_proxies`,
	 * который должен возвращать массив IP-адресов прокси-серверов.
	 *
	 * @param string $remoteAddr Значение $_SERVER['REMOTE_ADDR']
	 *
	 * @return string
	 */
	private function resolveClientIp( string $remoteAddr ): string {
		/** @var string[] $trustedProxies */
		$trustedProxies = (array) apply_filters( 'fs_lms_trusted_proxies', array() );

		if ( empty( $trustedProxies ) || ! in_array( $remoteAddr, $trustedProxies, true ) ) {
			return $remoteAddr;
		}

		$forwarded = (string) ( $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '' );

		if ( '' === $forwarded ) {
			return $remoteAddr;
		}

		// X-Forwarded-For может содержать цепочку IP через запятую; берём первый (клиентский)
		$clientIp = trim( explode( ',', $forwarded )[0] );

		return '' !== $clientIp ? $clientIp : $remoteAddr;
	}

}
