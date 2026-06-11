<?php

declare( strict_types=1 );

namespace Inc\DTO\Log;

/**
 * Class AuthLogInputDTO
 *
 * Data Transfer Object для вставки записи в журнал аутентификации (fs_lms_auth_log).
 *
 * @package Inc\DTO\Log
 *
 * ### Основные обязанности:
 *
 * 1. **Типобезопасная передача данных** — инкапсулирует данные для записи события аутентификации.
 * 2. **Преобразование в массив** — метод toArray() для вставки в БД.
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
 * - createdAt — дата и время события
 */
readonly class AuthLogInputDTO {

	/**
	 * Конструктор DTO.
	 *
	 * @param string|null $loginIdentifier Логин/email (введённый пользователем)
	 * @param string      $action          Тип действия (login, login_failed, password_reset)
	 * @param string      $result          Результат (success/failed)
	 * @param string      $actorIp         IP-адрес пользователя
	 * @param string|null $actorUa         User-Agent браузера
	 * @param string      $createdAt       Дата и время события (MySQL datetime)
	 */
	public function __construct(
		public ?string $loginIdentifier,
		public string  $action,
		public string  $result,
		public string  $actorIp,
		public ?string $actorUa,
		public string  $createdAt,
	) {}

	/**
	 * Преобразует DTO в массив для вставки в БД.
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array(
			'login_identifier' => $this->loginIdentifier,
			'action'           => $this->action,
			'result'           => $this->result,
			'actor_ip'         => $this->actorIp,
			'actor_ua'         => $this->actorUa,
			'created_at'       => $this->createdAt,
		);
	}
}