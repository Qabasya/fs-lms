<?php

declare( strict_types=1 );

namespace Inc\Services\Email;

use Inc\Services\Shared\PluginConfig;
use RuntimeException;

/**
 * Class EmailOtpService
 *
 * Сервис для отправки и верификации одноразовых кодов (OTP) по email.
 *
 * @package Inc\Services
 *
 * ### Основные обязанности:
 *
 * 1. **Генерация и отправка OTP** — создание 6-значного кода и отправка через EmailService.
 * 2. **Верификация OTP** — проверка введённого кода с учётом временнóго окна.
 * 3. **Защита от повторной отправки** — cooldown на 60 секунд.
 *
 * ### Архитектурная роль:
 *
 * Делегирует отправку email EmailService.
 * Использует WordPress Transients API (set_transient/get_transient) для хранения
 * OTP-кодов и флага cooldown с ограниченным временем жизни.
 *
 * ### Примечания:
 *
 * - OTP-коды хранятся в хэшированном виде (с солью) для безопасности.
 * - Время жизни OTP-кода — 10 минут (600 секунд).
 * - Cooldown на повторную отправку — 60 секунд.
 * - FS_LMS_TEST_ENV (wp-config.php) — тестовое окружение: письмо не отправляется,
 *   капча в ApplicationCallbacks пропускается. Ученик вводит FS_LMS_OTP_BYPASS_CODE.
 * - FS_LMS_OTP_BYPASS_CODE (wp-config.php) — постоянный bypass-код: принимается вместо
 *   кода с почты в любом окружении. Удобно когда у ученика нет доступа к email.
 */
readonly class EmailOtpService {

	/** Максимум неверных попыток ввода кода, после чего код инвалидируется. */
	private const MAX_VERIFY_ATTEMPTS = 5;

	/**
	 * Конструктор сервиса.
	 *
	 * @param EmailService $emailService Сервис отправки email
	 */
	public function __construct(
		private EmailService $emailService,
		private PluginConfig $pluginConfig,
	) {}

	/**
	 * Отправляет OTP-код на указанный email.
	 *
	 * @param string $email Email получателя
	 *
	 * @throws RuntimeException Если cooldown ещё активен
	 *
	 * @return void
	 */
	public function sendCode( string $email ): void {
		// В тестовом окружении письмо не отправляется — используется FS_LMS_OTP_BYPASS_CODE
//		if ( defined( 'FS_LMS_TEST_ENV' ) ) {
//			return;
//		}

		// Проверка возможности повторной отправки
		if ( ! $this->canResend( $email ) ) {
			throw new RuntimeException( 'Повторная отправка кода недоступна. Подождите перед следующей попыткой.' );
		}

		// random_int() — генерация криптографически безопасного случайного числа
		$code = (string) random_int( 100000, 999999 );
		$hash = $this->hashCode( $code );

		// set_transient() — сохранение хэша кода на 10 минут
		set_transient( $this->otpKey( $email ), $hash, 600 );

		// Свежий код — обнуляем счётчик неудачных попыток предыдущего.
		delete_transient( $this->attemptsKey( $email ) );

		// Отправка email через сервис
		$this->emailService->sendOtpCode( $email, $code );

		// Установка cooldown на 60 секунд
		set_transient( $this->cooldownKey( $email ), 1, 60 );
	}

	/**
	 * Проверяет OTP-код.
	 *
	 * @param string $email Email пользователя
	 * @param string $code  Введённый OTP-код
	 *
	 * @return bool
	 */
	public function verify( string $email, string $code ): bool {
		// Bypass-код: принимается вместо кода с почты (из wp-config или wp_options)
		$bypassCode = $this->pluginConfig->otpBypassCode();
		if ( '' !== $bypassCode && $code === $bypassCode ) {
			return true;
		}

		// get_transient() — получение хэша кода (false, если истекло)
		$stored = get_transient( $this->otpKey( $email ) );

		if ( false === $stored ) {
			return false;
		}

		// hash_equals() — защищённое от timing attack сравнение строк
		if ( ! hash_equals( (string) $stored, $this->hashCode( $code ) ) ) {
			$this->registerFailedAttempt( $email );
			return false;
		}

		// После успешной верификации удаляем код и счётчик попыток
		delete_transient( $this->otpKey( $email ) );
		delete_transient( $this->attemptsKey( $email ) );

		return true;
	}

	/**
	 * Фиксирует неудачную попытку ввода кода и инвалидирует код после лимита.
	 *
	 * Защита от перебора 6-значного кода: после MAX_VERIFY_ATTEMPTS неверных
	 * вводов код удаляется, и дальнейшие попытки бесполезны до повторной отправки.
	 *
	 * @param string $email Email пользователя
	 *
	 * @return void
	 */
	private function registerFailedAttempt( string $email ): void {
		$attempts = (int) get_transient( $this->attemptsKey( $email ) ) + 1;

		if ( $attempts >= self::MAX_VERIFY_ATTEMPTS ) {
			$this->invalidate( $email );
			delete_transient( $this->attemptsKey( $email ) );
			return;
		}

		// TTL счётчика совпадает с жизнью кода (10 минут).
		set_transient( $this->attemptsKey( $email ), $attempts, 600 );
	}

	/**
	 * Проверяет, можно ли отправить новый OTP-код.
	 *
	 * @param string $email Email пользователя
	 *
	 * @return bool
	 */
	public function canResend( string $email ): bool {
		// Если cooldown-ключ существует — повторная отправка недоступна
		return false === get_transient( $this->cooldownKey( $email ) );
	}

	/**
	 * Инвалидирует текущий OTP-код и cooldown.
	 *
	 * @param string $email Email пользователя
	 *
	 * @return void
	 */
	public function invalidate( string $email ): void {
		delete_transient( $this->otpKey( $email ) );
		delete_transient( $this->cooldownKey( $email ) );
	}

	/**
	 * Генерирует ключ для хранения OTP-кода в транзиентах.
	 *
	 * @param string $email Email пользователя
	 *
	 * @return string
	 */
	private function otpKey( string $email ): string {
		// hash() — SHA-256 хэш email для анонимности
		return 'fs_lms_otp_' . hash( 'sha256', $email );
	}

	/**
	 * Генерирует ключ для cooldown в транзиентах.
	 *
	 * @param string $email Email пользователя
	 *
	 * @return string
	 */
	private function cooldownKey( string $email ): string {
		return 'fs_lms_otp_cd_' . hash( 'sha256', $email );
	}

	/**
	 * Генерирует ключ для счётчика неудачных попыток ввода кода.
	 *
	 * @param string $email Email пользователя
	 *
	 * @return string
	 */
	private function attemptsKey( string $email ): string {
		return 'fs_lms_otp_att_' . hash( 'sha256', $email );
	}

	/**
	 * Хэширует OTP-код с солью для безопасного хранения.
	 *
	 * @param string $code OTP-код
	 *
	 * @return string
	 */
	private function hashCode( string $code ): string {
		// Файл конфигурации должен определять FS_LMS_HASH_SALT
		$salt = defined( 'FS_LMS_HASH_SALT' ) ? FS_LMS_HASH_SALT : '';
		// hash() — SHA-256 хэш кода с солью
		return hash( 'sha256', $code . $salt );
	}
}