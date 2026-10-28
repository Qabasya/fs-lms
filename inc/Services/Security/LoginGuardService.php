<?php

declare( strict_types=1 );

namespace Inc\Services\Security;

use Inc\DTO\Person\LoginNoticeDTO;
use Inc\Enums\Auth\CaptchaFailure;
use Inc\Enums\Auth\CaptchaScope;
use Inc\Enums\Auth\LoginNotice;
use Inc\Managers\Person\UserManager;
use Inc\Services\Captcha\CaptchaService;
use Inc\Services\Shared\PluginConfig;
use WP_Error;
use WP_User;

/**
 * Class LoginGuardService
 *
 * Защита входа от перебора: лимит неудачных попыток на пару IP + пользователь
 * и невидимая капча на форме входа. Лимит ступенчатый, как на iPhone: 3 неудачи за 5 минут
 * закрывают вход на 15 минут, следующие 3 после этого — на час, дальше — на сутки
 * ({@see RateLimitService}).
 *
 * @package Inc\Services\Security
 *
 * ### Порядок проверок в guard():
 *
 * блокировка → капча → результат проверки пароля. Фильтр `authenticate` вызывается
 * после проверки пароля ядром (приоритет 20), но его результат затирается: бот не
 * узнаёт, подошёл ли пароль, пока вход закрыт или капча не пройдена.
 *
 * ### Доверенный IP:
 *
 * С адреса из `FS_LMS_TRUSTED_IPS` (сеть школы: за ним много учеников) вход не блокируется и неудачи
 * не копятся; капча остаётся.
 *
 * ### Что не считается неудачей:
 *
 * - отказ по блокировке — иначе каждая попытка продлевала бы окно;
 * - непройденная капча — иначе бот без капчи мог бы закрыть вход живому человеку.
 */
readonly class LoginGuardService {

	public const CODE_LOCKED  = 'fs_lms_login_locked';
	public const CODE_CAPTCHA = 'fs_lms_login_captcha';

	public function __construct(
		private RateLimitService $rateLimit,
		private CaptchaService   $captcha,
		private UserManager      $users,
		private PluginConfig     $pluginConfig,
	) {}

	/**
	 * Проверка попытки входа (фильтр `authenticate`).
	 *
	 * @param WP_User|WP_Error|null $user          Результат предыдущих обработчиков
	 * @param string                $login         Введённый логин или email
	 * @param string                $captchaToken  Токен капчи из формы
	 * @param string                $ip            IP клиента
	 * @param bool                  $fromLoginForm POST на wp-login.php с полем `log` — капча обязательна
	 * @param CaptchaFailure|null   $captchaFailure Почему браузер не получил токен (VPN/блокировщик) — см. {@see CaptchaService::check()}
	 *
	 * @return WP_User|WP_Error|null
	 */
	public function guard( mixed $user, string $login, string $captchaToken, string $ip, bool $fromLoginForm, ?CaptchaFailure $captchaFailure = null ): mixed {
		if ( '' === trim( $login ) ) {
			return $user;
		}

		$userKey = $this->userKey( $login );

		if ( ! $this->pluginConfig->isTrustedIp( $ip ) && $this->rateLimit->isLoginLocked( $ip, $userKey ) ) {
			$wait = $this->rateLimit->loginRetryAfter( $ip, $userKey );
			return new WP_Error( self::CODE_LOCKED, LoginNotice::Locked->message( $wait ) );
		}

		if (
			$fromLoginForm
			&& $this->captchaRequired()
			&& ! $this->captcha->check( $captchaToken, $ip, CaptchaScope::Login, $captchaFailure, $login )->isAllowed()
		) {
			return new WP_Error( self::CODE_CAPTCHA, LoginNotice::Captcha->message() );
		}

		return $user;
	}

	/**
	 * Учитывает неудачный вход (хук `wp_login_failed`).
	 *
	 * @param string        $login Введённый логин или email
	 * @param WP_Error|null $error Причина отказа
	 * @param string        $ip    IP клиента
	 *
	 * @return void
	 */
	public function onFailure( string $login, ?WP_Error $error, string $ip ): void {
		if ( '' === trim( $login ) || $this->isGuardRejection( $error ) || $this->pluginConfig->isTrustedIp( $ip ) ) {
			return;
		}

		$this->rateLimit->registerLoginFailure( $ip, $this->userKey( $login ) );
	}

	/**
	 * Сбрасывает счётчик после успешного входа (хук `wp_login`).
	 *
	 * @param WP_User $user Вошедший пользователь
	 * @param string  $ip   IP клиента
	 *
	 * @return void
	 */
	public function onSuccess( WP_User $user, string $ip ): void {
		$this->rateLimit->clearLoginFailures( $ip, $this->userIdKey( $user->ID ) );
	}

	/**
	 * Уведомление для формы входа после неудачной попытки.
	 *
	 * Вызывается после onFailure(): неудача уже учтена.
	 *
	 * @param string        $login Введённый логин или email
	 * @param WP_Error|null $error Причина отказа
	 * @param string        $ip    IP клиента
	 *
	 * @return LoginNoticeDTO
	 */
	public function noticeFor( string $login, ?WP_Error $error, string $ip ): LoginNoticeDTO {
		if ( null !== $error && self::CODE_CAPTCHA === $error->get_error_code() ) {
			return new LoginNoticeDTO( LoginNotice::Captcha );
		}

		if ( '' === trim( $login ) || $this->pluginConfig->isTrustedIp( $ip ) ) {
			return new LoginNoticeDTO( LoginNotice::Failed );
		}

		$userKey = $this->userKey( $login );
		$left    = $this->rateLimit->loginAttemptsLeft( $ip, $userKey );

		return match ( $left ) {
			0       => new LoginNoticeDTO( LoginNotice::Locked, $this->rateLimit->loginRetryAfter( $ip, $userKey ) ),
			1       => new LoginNoticeDTO( LoginNotice::Last, $this->rateLimit->loginNextLockMinutes( $ip, $userKey ) ),
			default => new LoginNoticeDTO( LoginNotice::Failed ),
		};
	}

	/**
	 * Сколько секунд осталось блокировке входа для логина с этого адреса (для таймера и отключённой формы).
	 *
	 * Доверенный адрес и пустой логин не блокируются.
	 *
	 * @param string $login Введённый логин или email
	 * @param string $ip    IP клиента
	 *
	 * @return int 0 — вход открыт
	 */
	public function lockSecondsFor( string $login, string $ip ): int {
		if ( '' === trim( $login ) || $this->pluginConfig->isTrustedIp( $ip ) ) {
			return 0;
		}

		return $this->rateLimit->loginRetrySeconds( $ip, $this->userKey( $login ) );
	}

	/**
	 * Снимает блокировку входа аккаунта со всех адресов и возвращает первую ступень (сброс администратором).
	 *
	 * @param int $userId ID пользователя WordPress
	 *
	 * @return void
	 */
	public function unlockUser( int $userId ): void {
		$this->rateLimit->resetLoginLock( $this->userIdKey( $userId ) );
	}

	/**
	 * Идентификатор пользователя для счётчика.
	 *
	 * Существующий аккаунт — по ID: иначе, чередуя логин и email, можно удвоить число
	 * попыток. Несуществующий — нормализованная строка.
	 *
	 * @param string $login Введённый логин или email
	 *
	 * @return string
	 */
	public function userKey( string $login ): string {
		$login = trim( $login );
		$user  = $this->users->findByLogin( $login );

		if ( null === $user && str_contains( $login, '@' ) ) {
			$user = $this->users->findByEmail( $login );
		}

		return null !== $user
			? $this->userIdKey( $user->ID )
			: 'login:' . mb_strtolower( $login );
	}

	private function userIdKey( int $userId ): string {
		return 'id:' . $userId;
	}

	private function captchaRequired(): bool {
		return ! $this->pluginConfig->isTestEnv() && $this->captcha->isConfigured();
	}

	private function isGuardRejection( ?WP_Error $error ): bool {
		return null !== $error
			&& in_array( $error->get_error_code(), array( self::CODE_LOCKED, self::CODE_CAPTCHA ), true );
	}
}
