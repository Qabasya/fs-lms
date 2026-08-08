<?php

declare( strict_types=1 );

namespace Inc\Modules\AdSync\Services;

/**
 * Class AdStatusTokenService
 *
 * Непредсказуемый токен для nopriv-поллинга статуса провижна: наружу вместо
 * последовательного ID заявки уходит токен, соответствие token → application_id
 * живёт в транзиенте. Перебор ID больше не раскрывает существование заявок.
 *
 * Сырые set_/get_transient легальны: ключ инкапсулирован в одном классе модуля
 * (паттерн RateLimitService/EmailOtpService; core TransientKey о модулях не знает).
 *
 * @package Inc\Modules\AdSync\Services
 */
class AdStatusTokenService {

	/** Префикс транзиента соответствия token → application_id. */
	private const PREFIX = 'fs_lms_ad_ref_';

	/** Окно поллинга ~100 с; TTL с запасом на ретраи и медленный провижн. */
	private const TTL = 15 * MINUTE_IN_SECONDS;

	/** Выдаёт токен опроса статуса для заявки. */
	public function issue( int $applicationId ): string {
		$token = bin2hex( random_bytes( 16 ) );
		set_transient( self::PREFIX . $token, $applicationId, self::TTL );

		return $token;
	}

	/**
	 * ID заявки по токену; 0 — токен неизвестен либо протух.
	 * Читает, не удаляя: фронт опрашивает статус многократно.
	 */
	public function resolve( string $token ): int {
		return (int) get_transient( self::PREFIX . $token );
	}
}
