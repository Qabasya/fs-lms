<?php

declare( strict_types=1 );

namespace Inc\Services\Security;

use Inc\Services\Shared\PluginConfig;

/**
 * Class FormGuardService
 *
 * Лёгкая бот-защита публичных форм без трения для людей.
 *
 * @package Inc\Services\Security
 *
 * ### Два механизма:
 *
 * 1. **Honeypot** — скрытое поле, которое люди не видят и не заполняют,
 *    а примитивные боты автозаполняют. Непустое значение → бот.
 * 2. **Тайминг** — подписанная HMAC метка времени рендера формы.
 *    Сабмит быстрее MIN_FILL_SECONDS после загрузки → бот.
 *    Подпись (FS_LMS_HASH_SALT) делает метку неподделываемой и stateless —
 *    серверу не нужно хранить состояние между рендером и сабмитом.
 *
 * Дополняет (не заменяет) капчу и rate-limit: отсекает дешёвый трафик
 * до траты бюджета на капчу/OTP/письма.
 */
readonly class FormGuardService {

	/** Минимальное «человеческое» время заполнения формы, сек. */
	private const MIN_FILL_SECONDS = 3;

	/**
	 * Срок годности токена формы, сек (защита от reuse старых меток).
	 * 4 часа: на очной подаче форму открывают в начале урока, а отправляют позже.
	 */
	private const MAX_TOKEN_AGE = 4 * HOUR_IN_SECONDS;

	/** Имя honeypot-поля в разметке формы. */
	private const HONEYPOT_FIELD = 'fs_company';

	public function __construct(
		private PluginConfig $pluginConfig,
	) {}

	/**
	 * Имя honeypot-поля для вывода в шаблоне и чтения на сервере.
	 *
	 * @return string
	 */
	public function honeypotField(): string {
		return self::HONEYPOT_FIELD;
	}

	/**
	 * Создаёт подписанный токен с меткой времени рендера формы.
	 *
	 * @return string Формат: "{timestamp}.{hmac}"
	 */
	public function timestampToken(): string {
		$ts = (string) time();

		return $ts . '.' . $this->sign( $ts );
	}

	/**
	 * Проверяет, что заявку отправил человек, а не бот.
	 *
	 * @param string $honeypotValue Значение honeypot-поля (должно быть пустым)
	 * @param string $token         Токен из timestampToken()
	 *
	 * @return bool true если проверки пройдены
	 */
	public function isHuman( string $honeypotValue, string $token ): bool {
		return null === $this->rejectionReason( $honeypotValue, $token );
	}

	/** Причина отказа для журнала без значений полей и токена. */
	public function rejectionReason( string $honeypotValue, string $token ): ?string {
		if ( $this->pluginConfig->isTestEnv() ) {
			return null;
		}

		if ( '' !== trim( $honeypotValue ) ) {
			return 'honeypot_filled';
		}

		if ( '' === $token ) {
			return 'token_missing';
		}

		return $this->timingRejectionReason( $token );
	}

	/**
	 * Валидирует подпись токена и проверяет прошедшее время.
	 *
	 * @param string $token Токен из timestampToken()
	 *
	 * @return string|null
	 */
	private function timingRejectionReason( string $token ): ?string {
		$parts = explode( '.', $token, 2 );
		if ( 2 !== count( $parts ) ) {
			return 'token_invalid';
		}

		[ $ts, $sig ] = $parts;

		// ctype_digit + hash_equals — защита от подделки и timing attack.
		if ( ! ctype_digit( $ts ) || ! hash_equals( $this->sign( $ts ), $sig ) ) {
			return 'token_invalid';
		}

		$elapsed = time() - (int) $ts;

		if ( $elapsed < self::MIN_FILL_SECONDS ) {
			return 'too_fast';
		}

		if ( $elapsed > self::MAX_TOKEN_AGE ) {
			return 'token_expired';
		}

		return null;
	}

	/**
	 * Подписывает метку времени HMAC-ключом из соли.
	 *
	 * @param string $ts Unix-таймстамп строкой
	 *
	 * @return string
	 */
	private function sign( string $ts ): string {
		$salt = defined( 'FS_LMS_HASH_SALT' ) ? FS_LMS_HASH_SALT : '';

		return hash_hmac( 'sha256', $ts, $salt );
	}
}
