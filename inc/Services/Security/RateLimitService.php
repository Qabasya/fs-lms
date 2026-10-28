<?php

declare( strict_types=1 );

namespace Inc\Services\Security;

use Inc\Enums\Auth\CaptchaScope;
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
 * ### Белые IP:
 *
 * Для адресов из `PluginConfig::trustedIps()` (константа FS_LMS_TRUSTED_IPS) все IP-лимиты
 * умножаются на TRUSTED_IP_MULTIPLIER: за таким адресом очно подаёт заявки целый класс.
 * Лимиты по email, по паре IP + пользователь и по user_id от общего IP не зависят и не меняются.
 *
 * ### Ключи transient-ов:
 *
 * - Отправка кода заявки: fs_lms_rl_apply_otp_{ipHash}
 * - Создание заявки:      fs_lms_rl_apply_{ipHash}
 * - JOIN-ссылки: fs_lms_rl_join_{ipHash}
 * - Пропуск без капчи: fs_lms_rl_captchafb_{apply|login}_{ipHash}
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

	private const LIMIT_APPLICATION = 20;
	private const LIMIT_PII_REVEAL  = 100;
	private const LIMIT_OTP_EMAIL   = 5;

	// Публичные проверки занятости (лимит по IP; email жёстче — это проверка ПД).
	// IP за школьным NAT общий на класс — ниже 20/час не опускать.
	private const LIMIT_USERNAME_CHECK = 20;
	private const LIMIT_EMAIL_CHECK    = 20;
	/** Отчёты о сбоях из браузера (журнал «Ошибки»): защита таблицы от засева. */
	private const LIMIT_CLIENT_ERROR   = 30;
	/** Сообщения «Нашли ошибку?»: защита журнала от засева. */
	private const LIMIT_CONTENT_REPORT = 10;
	/** События формы родителя (журнал «Зачисления»): на визит их единицы, лимит — от засева. */
	private const LIMIT_JOIN_TRACK     = 60;
	private const LIMIT_APPLY_TRACK    = 60;
	private const LIMIT_EXAM_FORM_TRACK = 60;

	// Пропуск формы без капчи, когда она не дошла до браузера (VPN, блокировщик). Лимит по IP
	// на час: без капчи защита держится на нём, поэтому он тесный. Заявка — до 8 попыток
	// (код + повторы), вход — 30 (за одним адресом VPN/школы бывает много человек).
	private const LIMIT_CAPTCHA_FALLBACK_APPLY = 8;
	private const LIMIT_CAPTCHA_FALLBACK_LOGIN = 30;

	// Неудачные входы: счётчик на пару IP + пользователь. Лимита по одному IP нет —
	// с одного адреса выходят около 20 человек (перебор логинов сдерживает капча).
	// Как на iPhone: LIMIT_LOGIN неудач за LOGIN_WINDOW закрывают вход, и каждая следующая блокировка
	// длиннее прошлой (LOGIN_LOCK_STEPS, последняя ступень повторяется). Ступень помнится
	// LOGIN_LOCK_MEMORY после последней неудачи, успешный вход сбрасывает всё.
	public const LIMIT_LOGIN       = 3;
	public const LOGIN_WINDOW      = 5 * MINUTE_IN_SECONDS;
	public const LOGIN_LOCK_STEPS  = array( 15 * MINUTE_IN_SECONDS, HOUR_IN_SECONDS, DAY_IN_SECONDS );
	public const LOGIN_LOCK_MEMORY = DAY_IN_SECONDS;

	// Ключ приглашения экзамена: только неудачные проверки, успешные открытия не считаются (за общим адресом школы их десятки).
	public const LIMIT_INVITATION_FAILURES = 20;
	public const INVITATION_WINDOW         = 15 * MINUTE_IN_SECONDS;

	// Во сколько раз IP-лимиты выше для белых адресов. Не безлимит: заражённое устройство
	// в той же сети не должно слать запросы без ограничений. 20 учеников в час укладываются
	// с запасом: до 5 отправок кода и до 6 попыток создания на ученика — 100 и 120 из 200.
	private const TRUSTED_IP_MULTIPLIER = 10;

	/**
	 * Проверяет и фиксирует отправку кода подтверждения заявки с данного IP.
	 *
	 * Отдельный счётчик от создания заявки: на общем счётчике каждый ученик тратил минимум
	 * две единицы (код + создание), и класс упирался в лимит на середине.
	 *
	 * @param string $ip IP-адрес клиента
	 *
	 * @return bool false если лимит превышен
	 */
	public function allowOtpSend( string $ip ): bool {
		if ( $this->pluginConfig->isTestEnv() ) { return true; }
		return $this->checkIp( 'apply_otp', $ip, self::LIMIT_APPLICATION );
	}

	/**
	 * Проверяет и фиксирует попытку создания заявки с данного IP.
	 *
	 * @param string $ip IP-адрес клиента
	 *
	 * @return bool false если лимит превышен
	 */
	public function allowApplicationCreation( string $ip ): bool {
		if ( $this->pluginConfig->isTestEnv() ) { return true; }
		return $this->checkIp( 'apply', $ip, self::LIMIT_APPLICATION );
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
	 * Сообщение об ошибке в задании или статье («Нашли ошибку?»).
	 *
	 * @param string $ip IP-адрес клиента
	 */
	public function allowContentReport( string $ip ): bool {
		return $this->checkIp( 'contentreport', $ip, self::LIMIT_CONTENT_REPORT );
	}

	/**
	 * Отчёт о сбое из браузера в журнал «Ошибки».
	 *
	 * @param string $ip IP-адрес клиента
	 */
	public function allowClientErrorReport( string $ip ): bool {
		return $this->checkIp( 'clienterr', $ip, self::LIMIT_CLIENT_ERROR );
	}

	/**
	 * Событие формы родителя из браузера в журнал «Зачисления».
	 *
	 * @param string $ip IP-адрес клиента
	 */
	public function allowJoinTrack( string $ip ): bool {
		return $this->checkIp( 'jointrack', $ip, self::LIMIT_JOIN_TRACK );
	}

	/**
	 * Проверяет и фиксирует отчёт о событии формы заявки (трекинг из браузера).
	 *
	 * @param string $ip IP-адрес клиента
	 */
	public function allowApplyTrack( string $ip ): bool {
		return $this->checkIp( 'applytrack', $ip, self::LIMIT_APPLY_TRACK );
	}

	/**
	 * Проверяет и фиксирует пропуск формы без капчи (она не дошла до браузера).
	 *
	 * @param string       $ip    IP-адрес клиента
	 * @param CaptchaScope $scope Форма
	 */
	public function allowCaptchaFallback( string $ip, CaptchaScope $scope ): bool {
		return $this->checkIp(
			'captchafb_' . $scope->value,
			$ip,
			CaptchaScope::Apply === $scope ? self::LIMIT_CAPTCHA_FALLBACK_APPLY : self::LIMIT_CAPTCHA_FALLBACK_LOGIN
		);
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
		return $this->checkIp( 'unamechk', $ip, self::LIMIT_USERNAME_CHECK );
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
		return $this->checkIp( 'emailchk', $ip, self::LIMIT_EMAIL_CHECK );
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
	 * Учитывает неудачную проверку ключа приглашения экзамена с IP (неверный, отозванный, чужого назначения).
	 * Успешные открытия не считаются; тестовое окружение лимит не отключает — как у входа.
	 */
	public function registerInvitationFailure( string $ip ): void {
		$this->check( $this->ipKey( 'examkey', $ip ), self::LIMIT_INVITATION_FAILURES, self::INVITATION_WINDOW );
	}

	/** Закрыта ли проверка ключей приглашения для IP: 20 неудач за 15 минут. Счётчик не увеличивает. */
	public function isInvitationLocked( string $ip ): bool {
		$data = $this->peek( $this->ipKey( 'examkey', $ip ) );

		return (int) ( $data['count'] ?? 0 ) >= self::LIMIT_INVITATION_FAILURES;
	}

	/**
	 * Создание брони экзамена: не больше `$hourlyLimit` в час с IP (белые адреса — с множителем). Фиксирует и проверяет.
	 */
	public function allowExamHoldCreation( string $ip, int $hourlyLimit ): bool {
		if ( $this->pluginConfig->isTestEnv() ) { return true; }
		return $this->checkIp( 'examhold', $ip, max( 1, $hourlyLimit ) );
	}

	/**
	 * Техническое событие станции экзамена из браузера: одно событие каждого вида на попытку и IP в минуту.
	 * Фиксирует и проверяет.
	 */
	public function allowExamClientEvent( string $ip, int $attemptId, string $kind ): bool {
		return $this->check( $this->ipKey( 'examclient' . $kind . $attemptId, $ip ), 1, MINUTE_IN_SECONDS );
	}

	/**
	 * Запись об открытии гостевой формы записи в журнал «Экзамены»: не чаще раза в 10 минут с IP на один источник,
	 * иначе каждое обновление страницы и каждый бот заполняли бы журнал. Фиксирует и проверяет.
	 */
	public function allowExamFormOpenLog( string $ip, int $sourceId ): bool {
		return $this->check( $this->ipKey( 'examformopen' . $sourceId, $ip ), 1, 10 * MINUTE_IN_SECONDS );
	}

	/** События гостевой формы записи на экзамен от браузера (начал, ошибка, ушёл): 60 в час с адреса. */
	public function allowExamFormTrack( string $ip ): bool {
		return $this->checkIp( 'examformtrack', $ip, self::LIMIT_EXAM_FORM_TRACK );
	}

	/** Хеш IP для хранения в таблице заявок (сырой IP в базу не пишется): тот же алгоритм, что у ключей счётчиков. */
	public function ipHash( string $ip ): string {
		return hash( 'sha256', $ip . ( defined( 'FS_LMS_HASH_SALT' ) ? FS_LMS_HASH_SALT : '' ) );
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
		return $this->loginState( $ip, $userKey )['locked_until'] > time();
	}

	/**
	 * Сколько неудачных попыток осталось паре до блокировки. Счётчик не увеличивает.
	 *
	 * @param string $ip      IP-адрес клиента
	 * @param string $userKey Идентификатор пользователя
	 *
	 * @return int 0 — вход закрыт
	 */
	public function loginAttemptsLeft( string $ip, string $userKey ): int {
		return $this->attemptsLeft( $this->loginState( $ip, $userKey ), time() );
	}

	/**
	 * Учитывает неудачный вход пары. Последняя неудача окна закрывает вход на ступень вперёд:
	 * 15 минут, час, сутки (дальше — сутки). Пока вход закрыт, неудачи не считаются и блокировку не продлевают.
	 *
	 * @param string $ip      IP-адрес клиента
	 * @param string $userKey Идентификатор пользователя
	 *
	 * @return int Остаток попыток после учёта (0 — вход закрыт)
	 */
	public function registerLoginFailure( string $ip, string $userKey ): int {
		$now   = time();
		$state = $this->loginState( $ip, $userKey );

		if ( $state['locked_until'] > $now ) {
			return 0;
		}

		if ( $now >= $state['reset_at'] ) {
			$state['count']    = 0;
			$state['reset_at'] = $now + self::LOGIN_WINDOW;
		}

		++$state['count'];

		if ( $state['count'] >= self::LIMIT_LOGIN ) {
			++$state['level'];
			$state['locked_until'] = $now + $this->loginLockSeconds( $state['level'] );
			// Новое окно подсчёта начнётся с первой неудачи после конца блокировки.
			$state['count']    = 0;
			$state['reset_at'] = $state['locked_until'];
		}

		$state['touched_at'] = microtime( true );
		$ttl                 = max( $state['reset_at'], $state['locked_until'] ) - $now + self::LOGIN_LOCK_MEMORY;
		set_transient( $this->loginKey( $ip, $userKey ), $state, $ttl );

		return $this->attemptsLeft( $state, $now );
	}

	/**
	 * Сбрасывает счётчик, блокировку и ступень неудачных входов пары (после успешного входа).
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
	 * Минуты до конца блокировки входа, округлённые вверх.
	 *
	 * @param string $ip      IP-адрес клиента
	 * @param string $userKey Идентификатор пользователя
	 *
	 * @return int 0 — вход не закрыт
	 */
	public function loginRetryAfter( string $ip, string $userKey ): int {
		return (int) ceil( $this->loginRetrySeconds( $ip, $userKey ) / MINUTE_IN_SECONDS );
	}

	/**
	 * Секунды до конца блокировки входа (для таймера на форме).
	 *
	 * @param string $ip      IP-адрес клиента
	 * @param string $userKey Идентификатор пользователя
	 *
	 * @return int 0 — вход не закрыт
	 */
	public function loginRetrySeconds( string $ip, string $userKey ): int {
		return max( 0, $this->loginState( $ip, $userKey )['locked_until'] - time() );
	}

	/**
	 * Снимает блокировку, неудачи и ступень пользователя со всех адресов сразу (сброс администратором).
	 *
	 * Счётчики лежат под хешем «IP + пользователь», по пользователю их не перечислить, поэтому
	 * ставится метка времени: счётчик, записанный не позже неё, считается пустым ({@see self::loginState()}).
	 *
	 * @param string $userKey Идентификатор пользователя (см. LoginGuardService)
	 *
	 * @return void
	 */
	public function resetLoginLock( string $userKey ): void {
		set_transient( $this->loginResetKey( $userKey ), microtime( true ), max( self::LOGIN_LOCK_STEPS ) + self::LOGIN_LOCK_MEMORY );
	}

	/**
	 * На сколько минут закроется вход при следующей блокировке пары (для предупреждения «осталась одна попытка»).
	 *
	 * @param string $ip      IP-адрес клиента
	 * @param string $userKey Идентификатор пользователя
	 *
	 * @return int
	 */
	public function loginNextLockMinutes( string $ip, string $userKey ): int {
		return intdiv( $this->loginLockSeconds( $this->loginState( $ip, $userKey )['level'] + 1 ), MINUTE_IN_SECONDS );
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
	 * IP-счётчик с учётом белых адресов.
	 *
	 * @param string $prefix Префикс действия (см. ipKey())
	 * @param string $ip     IP-адрес клиента
	 * @param int    $limit  Лимит для обычного адреса
	 *
	 * @return bool
	 */
	private function checkIp( string $prefix, string $ip, int $limit ): bool {
		$isTrusted = $this->pluginConfig->isTrustedIp( $ip );

		return $this->check( $this->ipKey( $prefix, $ip ), $isTrusted ? $limit * self::TRUSTED_IP_MULTIPLIER : $limit );
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
	 * Состояние счётчика входа пары: неудачи окна, блокировка и ступень. Счётчики прежнего формата
	 * (только `count` и `reset_at`) читаются как ступень 0 без блокировки. Счётчик, записанный не позже
	 * сброса администратором ({@see self::resetLoginLock()}), считается пустым.
	 *
	 * @return array{count: int, reset_at: int, level: int, locked_until: int, touched_at: float}
	 */
	private function loginState( string $ip, string $userKey ): array {
		$data = get_transient( $this->loginKey( $ip, $userKey ) );
		$data = is_array( $data ) ? $data : array();

		$state = array(
			'count'        => (int) ( $data['count'] ?? 0 ),
			'reset_at'     => (int) ( $data['reset_at'] ?? 0 ),
			'level'        => (int) ( $data['level'] ?? 0 ),
			'locked_until' => (int) ( $data['locked_until'] ?? 0 ),
			'touched_at'   => (float) ( $data['touched_at'] ?? 0 ),
		);

		$resetAt = get_transient( $this->loginResetKey( $userKey ) );
		if ( false !== $resetAt && (float) $resetAt >= $state['touched_at'] ) {
			return array( 'count' => 0, 'reset_at' => 0, 'level' => 0, 'locked_until' => 0, 'touched_at' => 0.0 );
		}

		return $state;
	}

	/** Ключ метки сброса блокировок пользователя: общий для всех адресов, без пользователя в открытом виде. */
	public function loginResetKey( string $userKey ): string {
		$salt = defined( 'FS_LMS_HASH_SALT' ) ? FS_LMS_HASH_SALT : '';

		return 'fs_lms_rl_loginreset_' . hash( 'sha256', $userKey . $salt );
	}

	/**
	 * @param array{count: int, reset_at: int, level: int, locked_until: int, touched_at: float} $state
	 */
	private function attemptsLeft( array $state, int $now ): int {
		if ( $state['locked_until'] > $now ) {
			return 0;
		}

		// Окно прошлых неудач истекло: отсчёт начнётся заново.
		if ( $now >= $state['reset_at'] ) {
			return self::LIMIT_LOGIN;
		}

		return max( 0, self::LIMIT_LOGIN - $state['count'] );
	}

	/** Длительность блокировки по её номеру подряд (с 1); номер за последней ступенью берёт последнюю. */
	private function loginLockSeconds( int $level ): int {
		$steps = self::LOGIN_LOCK_STEPS;

		return $steps[ min( max( $level, 1 ), count( $steps ) ) - 1 ];
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
