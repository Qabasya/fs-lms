<?php

declare( strict_types=1 );

namespace Inc\DTO\Log;

use Inc\Enums\Access\UserRole;
use Inc\Enums\Auth\LoginFailReason;

/**
 * Class AuthLogDTO
 *
 * Data Transfer Object для записи в журнал аутентификации (fs_lms_auth_log).
 *
 * @package Inc\DTO\Log
 *
 * ### Основные обязанности:
 *
 * 1. **Хранение записи аутентификации** — представляет запись из таблицы auth_log.
 * 2. **Преобразование массива в DTO** — статический метод fromArray().
 *
 * ### Архитектурная роль:
 *
 * Используется в AuthLogWriter для передачи данных о событиях аутентификации:
 * - Успешный вход (login)
 * - Неудачный вход (login_failed)
 * - Сброс пароля (password_reset)
 *
 * ### Поля записи:
 *
 * - loginIdentifier — логин или email, введённый пользователем (может быть NULL для некоторых действий)
 * - action — тип действия (login, login_failed, password_reset)
 * - result — результат (success/failed)
 * - actorIp — IP-адрес пользователя
 * - actorUa — User-Agent браузера
 */
readonly class AuthLogDTO {

	/**
	 * Конструктор DTO.
	 *
	 * @param int         $id               ID записи
	 * @param string|null $loginIdentifier  Логин/email (введённый пользователем)
	 * @param string      $action           Тип действия (login, login_failed, password_reset)
	 * @param string      $result           Результат (success/failed)
	 * @param string      $actorIp          IP-адрес пользователя
	 * @param string|null $actorUa          User-Agent браузера
	 * @param string      $createdAt        Дата и время создания записи
	 * @param string|null $reason           Причина отказа ({@see LoginFailReason})
	 * @param array       $details          Подробности попытки ({@see \Inc\Services\Security\LoginDiagnosticsService})
	 */
	public function __construct(
		public int     $id,
		public ?string $loginIdentifier,
		public string  $action,
		public string  $result,
		public string  $actorIp,
		public ?string $actorUa,
		public string  $createdAt,
		public ?string $reason = null,
		public array   $details = array(),
	) {}

	public function reasonLabel(): string {
		if ( null === $this->reason ) {
			return '';
		}

		return LoginFailReason::tryFrom( $this->reason )?->label() ?? $this->reason;
	}

	/**
	 * Подробности попытки строками для таблицы и CSV.
	 *
	 * @return string[]
	 */
	public function detailLines(): array {
		$d     = $this->details;
		$lines = array();

		if ( isset( $d['login_raw'] ) ) {
			$lines[] = 'Введено в поле логина: «' . $d['login_raw'] . '»';
		}
		if ( isset( $d['account'] ) ) {
			$lines[] = match ( $d['account'] ) {
				'login' => 'Аккаунт найден по логину',
				'email' => 'Аккаунт найден по email',
				default => 'Аккаунт не найден',
			};
		}
		if ( ! empty( $d['roles'] ) ) {
			$lines[] = 'Роль: ' . implode( ', ', array_map(
				static fn( string $role ): string => UserRole::tryFrom( $role )?->label() ?? $role,
				(array) $d['roles']
			) );
		}
		if ( isset( $d['password_length'] ) ) {
			$lines[] = 'Длина пароля: ' . (int) $d['password_length']
				. ( ! empty( $d['password_cyrillic'] ) ? ', есть кириллица' : '' );
		}
		if ( isset( $d['form'] ) ) {
			$lines[] = 'sign_in' === $d['form'] ? 'Форма: /sign-in/' : 'Форма: wp-login.php';
		}
		if ( isset( $d['captcha_token'] ) ) {
			$lines[] = $d['captcha_token'] ? 'Токен капчи: есть' : 'Токен капчи: нет';
		}
		if ( ! empty( $d['error_code'] ) ) {
			$lines[] = 'Код WP: ' . $d['error_code'];
		}
		if ( ! empty( $d['visit'] ) ) {
			$lines[] = 'Визит: ' . $d['visit'];
		}
		if ( ! empty( $d['note'] ) ) {
			$lines[] = (string) $d['note'];
		}

		return $lines;
	}

	/**
	 * Создаёт DTO из массива данных (например, из результата SQL-запроса).
	 *
	 * @param array<string, mixed> $row Ассоциативный массив с полями таблицы
	 *
	 * @return static
	 */
	public static function fromArray( array $row ): static {
		$details = isset( $row['details'] ) ? json_decode( (string) $row['details'], true ) : null;

		return new static(
			id:              (int) $row['id'],
			loginIdentifier: isset( $row['login_identifier'] ) ? (string) $row['login_identifier'] : null,
			action:          (string) $row['action'],
			result:          (string) $row['result'],
			actorIp:         (string) $row['actor_ip'],
			actorUa:         isset( $row['actor_ua'] ) ? (string) $row['actor_ua'] : null,
			createdAt:       (string) $row['created_at'],
			reason:          isset( $row['reason'] ) ? (string) $row['reason'] : null,
			details:         is_array( $details ) ? $details : array(),
		);
	}
}