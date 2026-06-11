<?php

declare( strict_types=1 );

namespace Inc\DTO\Log;

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
	 */
	public function __construct(
		public int     $id,
		public ?string $loginIdentifier,
		public string  $action,
		public string  $result,
		public string  $actorIp,
		public ?string $actorUa,
		public string  $createdAt,
	) {}

	/**
	 * Создаёт DTO из массива данных (например, из результата SQL-запроса).
	 *
	 * @param array<string, mixed> $row Ассоциативный массив с полями таблицы
	 *
	 * @return static
	 */
	public static function fromArray( array $row ): static {
		return new static(
			id:              (int) $row['id'],
			loginIdentifier: isset( $row['login_identifier'] ) ? (string) $row['login_identifier'] : null,
			action:          (string) $row['action'],
			result:          (string) $row['result'],
			actorIp:         (string) $row['actor_ip'],
			actorUa:         isset( $row['actor_ua'] ) ? (string) $row['actor_ua'] : null,
			createdAt:       (string) $row['created_at'],
		);
	}
}