<?php

declare( strict_types=1 );

namespace Inc\Services\Security;

use Inc\Services\Shared\PluginConfig;

/**
 * Class RateLimitService
 *
 * Ограничение частоты запросов на публичных и защищённых endpoint-ах.
 *
 * @package Inc\Services
 *
 * ### Основные обязанности:
 *
 * 1. **Счётчики по IP** — ограничение создания заявок, открытий JOIN-ссылок и submit-ов родителя.
 * 2. **Счётчики по user_id** — ограничение reveal-операций PII.
 * 3. **Инкремент при вызове** — счётчик увеличивается при каждом вызове allow*().
 *    Вызывающий код не вызывает allow() отдельно — метод одновременно фиксирует и проверяет.
 *
 * ### Реализация:
 *
 * WP Transients API. IP-ключи хранятся не напрямую, а через
 * hash('sha256', $ip . FS_LMS_HASH_SALT) — защита от перебора ключей в БД.
 *
 * Для каждого счётчика хранится структура ['count' => N, 'reset_at' => timestamp].
 * reset_at устанавливается при первом обращении в окне; TTL transient-а берётся с
 * двукратным запасом, чтобы WP не вытеснил его раньше истечения окна.
 * Это обеспечивает фиксированное (не скользящее) окно в 1 час.
 *
 * ### Ключи transient-ов:
 *
 * - Заявки:      fs_lms_rl_apply_{ipHash}
 * - JOIN-ссылки: fs_lms_rl_join_{ipHash}
 * - Submit-ы:    fs_lms_rl_parent_{ipHash}
 * - PII reveal:  fs_lms_rl_pii_{userId}
 */
readonly class RateLimitService {

	private const WINDOW      = HOUR_IN_SECONDS;

	public function __construct(
		private PluginConfig $pluginConfig,
	) {}
	private const TRANSIENT_TTL = self::WINDOW * 2;

	private const LIMIT_APPLICATION = 5;
	private const LIMIT_JOIN        = 10;
	private const LIMIT_PARENT      = 3;
	private const LIMIT_PII_REVEAL  = 100;
	private const LIMIT_OTP_EMAIL   = 5;

	/**
	 * Проверяет и фиксирует попытку создания заявки с данного IP.
	 *
	 * @param string $ip IP-адрес клиента
	 *
	 * @return bool false если лимит превышен
	 */
	public function allowApplicationCreation( string $ip ): bool {
		if ( $this->pluginConfig->isTestEnv() ) { return true; }
		return $this->check( $this->ipKey( 'apply', $ip ), self::LIMIT_APPLICATION );
	}

	/**
	 * Проверяет и фиксирует попытку отправки OTP-кода на данный email.
	 *
	 * Лимит по адресу (не по IP) — защита от email-бомбинга жертвы
	 * с ротацией IP и от спама заявок на одну почту. Окно — сутки.
	 *
	 * @param string $email Email получателя кода
	 *
	 * @return bool false если суточный лимит отправок исчерпан
	 */
	public function allowOtpSendForEmail( string $email ): bool {
		if ( $this->pluginConfig->isTestEnv() ) { return true; }
		return $this->check( $this->emailKey( 'otpmail', $email ), self::LIMIT_OTP_EMAIL, DAY_IN_SECONDS );
	}

	/**
	 * Проверяет и фиксирует попытку открытия JOIN-ссылки с данного IP.
	 *
	 * @param string $ip IP-адрес клиента
	 *
	 * @return bool false если лимит превышен
	 */
	public function allowJoinAttempt( string $ip ): bool {
		if ( $this->pluginConfig->isTestEnv() ) { return true; }
		return $this->check( $this->ipKey( 'join', $ip ), self::LIMIT_JOIN );
	}

	/**
	 * Проверяет и фиксирует попытку submit-а формы родителя с данного IP.
	 *
	 * @param string $ip IP-адрес клиента
	 *
	 * @return bool false если лимит превышен
	 */
	public function allowParentSubmit( string $ip ): bool {
		if ( $this->pluginConfig->isTestEnv() ) { return true; }
		return $this->check( $this->ipKey( 'parent', $ip ), self::LIMIT_PARENT );
	}

	/**
	 * Проверяет и фиксирует попытку reveal PII для данного пользователя.
	 *
	 * @param int $userId ID пользователя WordPress
	 *
	 * @return bool false если лимит превышен
	 */
	public function allowPiiReveal( int $userId ): bool {
		return $this->check( $this->userKey( 'pii', $userId ), self::LIMIT_PII_REVEAL );
	}

	/**
	 * Сбрасывает счётчик по ключу transient-а.
	 *
	 * Предназначен для тестов и ручного управления через wp-cli или код.
	 * Ключ строится через ipKey() или userKey() — вызывающий код должен
	 * использовать те же методы для получения ключа.
	 *
	 * @param string $key Полный ключ transient-а (например, fs_lms_rl_apply_{hash})
	 *
	 * @return void
	 */
	public function reset( string $key ): void {
		delete_transient( $key );
	}

	/**
	 * Строит transient-ключ для IP-based счётчика.
	 *
	 * IP хэшируется через SHA-256 с солью из FS_LMS_HASH_SALT —
	 * сырой IP не попадает в ключи transient-ов в БД.
	 *
	 * @param string $prefix Префикс действия (apply, join, parent)
	 * @param string $ip     IP-адрес клиента
	 *
	 * @return string
	 */
	public function ipKey( string $prefix, string $ip ): string {
		$salt = defined( 'FS_LMS_HASH_SALT' ) ? FS_LMS_HASH_SALT : '';
		$hash = hash( 'sha256', $ip . $salt );

		return "fs_lms_rl_{$prefix}_{$hash}";
	}

	/**
	 * Строит transient-ключ для user_id-based счётчика.
	 *
	 * @param string $prefix Префикс действия (pii)
	 * @param int    $userId ID пользователя WordPress
	 *
	 * @return string
	 */
	public function userKey( string $prefix, int $userId ): string {
		return "fs_lms_rl_{$prefix}_{$userId}";
	}

	/**
	 * Строит transient-ключ для email-based счётчика.
	 *
	 * Email нормализуется (trim + lowercase) и хэшируется через SHA-256 с
	 * солью из FS_LMS_HASH_SALT — сырой адрес не попадает в ключи в БД.
	 *
	 * @param string $prefix Префикс действия (otpmail)
	 * @param string $email  Email клиента
	 *
	 * @return string
	 */
	public function emailKey( string $prefix, string $email ): string {
		$salt = defined( 'FS_LMS_HASH_SALT' ) ? FS_LMS_HASH_SALT : '';
		$hash = hash( 'sha256', strtolower( trim( $email ) ) . $salt );

		return "fs_lms_rl_{$prefix}_{$hash}";
	}

	/**
	 * Инкрементирует счётчик и проверяет лимит.
	 *
	 * Использует фиксированное окно: reset_at задаётся при первом обращении
	 * и не сдвигается при последующих — в отличие от скользящего окна.
	 *
	 * @param string $key    Ключ transient-а
	 * @param int    $limit  Максимально допустимое количество запросов в окне
	 * @param int    $window Длина окна в секундах (по умолчанию — 1 час)
	 *
	 * @return bool true если запрос разрешён, false если лимит исчерпан
	 */
	private function check( string $key, int $limit, int $window = self::WINDOW ): bool {
		$now  = time();
		$data = get_transient( $key );
		$ttl  = $window * 2;

		if ( false === $data || ! is_array( $data ) || $now >= $data['reset_at'] ) {
			set_transient( $key, array( 'count' => 1, 'reset_at' => $now + $window ), $ttl );
			return true;
		}

		$data['count']++;
		set_transient( $key, $data, $ttl );

		return $data['count'] <= $limit;
	}
}