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
 * - Логин-чек:   fs_lms_rl_unamechk_{ipHash}
 * - Email-чек:   fs_lms_rl_emailchk_{ipHash}
 * - Вход:        fs_lms_rl_login_{hash(ip + пользователь)}
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

	// Публичные проверки занятости (лимит по IP; email жёстче — это проверка ПД).
	// IP за школьным NAT общий на класс — ниже 20/час не опускать.
	private const LIMIT_USERNAME_CHECK = 20;
	private const LIMIT_EMAIL_CHECK    = 10;

	// Неудачные входы: счётчик на пару IP + пользователь. Лимита по одному IP нет —
	// с одного адреса выходят около 20 человек (перебор логинов сдерживает капча).
	public const LIMIT_LOGIN  = 3;
	public const LOGIN_WINDOW = 15 * MINUTE_IN_SECONDS;

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
	 * Проверяет и фиксирует попытку проверки занятости логина с данного IP.
	 *
	 * Эндпоинт публичный (nopriv): без лимита это пакетное перечисление
	 * пользователей через username_exists().
	 *
	 * @param string $ip IP-адрес клиента
	 *
	 * @return bool false если лимит превышен
	 */
	public function allowUsernameCheck( string $ip ): bool {
		if ( $this->pluginConfig->isTestEnv() ) { return true; }
		return $this->check( $this->ipKey( 'unamechk', $ip ), self::LIMIT_USERNAME_CHECK );
	}

	/**
	 * Проверяет и фиксирует попытку проверки занятости email с данного IP.
	 *
	 * Жёстче лимита логина: подтверждение регистрации по произвольному email —
	 * раскрытие персональных данных, проверяемое пакетно по списку адресов.
	 *
	 * @param string $ip IP-адрес клиента
	 *
	 * @return bool false если лимит превышен
	 */
	public function allowEmailCheck( string $ip ): bool {
		if ( $this->pluginConfig->isTestEnv() ) { return true; }
		return $this->check( $this->ipKey( 'emailchk', $ip ), self::LIMIT_EMAIL_CHECK );
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
	 * Закрыт ли вход для пары IP + пользователь. Счётчик не увеличивает.
	 *
	 * isTestEnv() лимит входа НЕ отключает (как и allowPiiReveal): тумблер тестового
	 * окружения живёт в опции и может остаться включённым на проде.
	 *
	 * @param string $ip      IP-адрес клиента
	 * @param string $userKey Идентификатор пользователя (см. LoginGuardService)
	 *
	 * @return bool
	 */
	public function isLoginLocked( string $ip, string $userKey ): bool {
		return 0 === $this->loginAttemptsLeft( $ip, $userKey );
	}

	/**
	 * Сколько неудачных попыток осталось паре до блокировки. Счётчик не увеличивает.
	 *
	 * @param string $ip      IP-адрес клиента
	 * @param string $userKey Идентификатор пользователя
	 *
	 * @return int
	 */
	public function loginAttemptsLeft( string $ip, string $userKey ): int {
		$data = $this->peek( $this->loginKey( $ip, $userKey ) );

		return max( 0, self::LIMIT_LOGIN - (int) ( $data['count'] ?? 0 ) );
	}

	/**
	 * Учитывает неудачный вход пары.
	 *
	 * @param string $ip      IP-адрес клиента
	 * @param string $userKey Идентификатор пользователя
	 *
	 * @return int Остаток попыток после учёта (0 — вход закрыт до конца окна)
	 */
	public function registerLoginFailure( string $ip, string $userKey ): int {
		$this->check( $this->loginKey( $ip, $userKey ), self::LIMIT_LOGIN, self::LOGIN_WINDOW );

		return $this->loginAttemptsLeft( $ip, $userKey );
	}

	/**
	 * Сбрасывает счётчик неудачных входов пары (после успешного входа).
	 *
	 * @param string $ip      IP-адрес клиента
	 * @param string $userKey Идентификатор пользователя
	 *
	 * @return void
	 */
	public function clearLoginFailures( string $ip, string $userKey ): void {
		delete_transient( $this->loginKey( $ip, $userKey ) );
	}

	/**
	 * Минуты до конца окна счётчика входа, округлённые вверх.
	 *
	 * @param string $ip      IP-адрес клиента
	 * @param string $userKey Идентификатор пользователя
	 *
	 * @return int 0 — счётчика нет или окно истекло
	 */
	public function loginRetryAfter( string $ip, string $userKey ): int {
		$data = $this->peek( $this->loginKey( $ip, $userKey ) );

		if ( null === $data ) {
			return 0;
		}

		return max( 1, (int) ceil( ( (int) $data['reset_at'] - time() ) / MINUTE_IN_SECONDS ) );
	}

	/**
	 * Строит transient-ключ счётчика входа.
	 *
	 * Пара IP + пользователь хэшируется с солью FS_LMS_HASH_SALT — ни IP, ни логин
	 * не попадают в ключи в БД.
	 *
	 * @param string $ip      IP-адрес клиента
	 * @param string $userKey Идентификатор пользователя
	 *
	 * @return string
	 */
	public function loginKey( string $ip, string $userKey ): string {
		$salt = defined( 'FS_LMS_HASH_SALT' ) ? FS_LMS_HASH_SALT : '';
		$hash = hash( 'sha256', $ip . '|' . $userKey . $salt );

		return "fs_lms_rl_login_{$hash}";
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

	/**
	 * Читает счётчик без инкремента.
	 *
	 * @param string $key Ключ transient-а
	 *
	 * @return array{count: int, reset_at: int}|null null — счётчика нет или окно истекло
	 */
	private function peek( string $key ): ?array {
		$data = get_transient( $key );

		if ( ! is_array( $data ) || time() >= (int) ( $data['reset_at'] ?? 0 ) ) {
			return null;
		}

		return $data;
	}
}