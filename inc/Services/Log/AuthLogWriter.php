<?php

declare( strict_types=1 );

namespace Inc\Services\Log;

use Inc\Contracts\ClockInterface;
use Inc\DTO\Log\AuthLogInputDTO;
use Inc\Repositories\WPDBRepositories\AuthLogRepository;
use Inc\Shared\Traits\RequestContextProvider;

/**
 * Class AuthLogWriter
 *
 * Сервис для записи событий аутентификации в журнал аудита.
 *
 * @package Inc\Services\Log
 *
 * ### Основные обязанности:
 *
 * 1. **Запись событий аутентификации** — логирование успешных/неудачных входов, сбросов пароля.
 * 2. **Сбор контекста запроса** — получение IP, User-Agent через трейт RequestContextProvider.
 *
 * ### Архитектурная роль:
 *
 * Делегирует сохранение AuthLogRepository.
 * Используется в AuthLogController для записи событий wp_login, wp_login_failed, password_reset.
 *
 * ### Параметры:
 *
 * - $loginIdentifier — логин или email, введённый пользователем (для неудачных попыток)
 * - $action — тип действия (login, login_failed, otp_sent, otp_verified, password_reset)
 * - $success — успешность операции (true/false)
 *
 * ### Примечания:
 *
 * - IP-адрес сохраняется в бинарном формате через inet_pton.
 * - Время события получается через ClockInterface (для тестируемости).
 */
class AuthLogWriter {

	use RequestContextProvider;  // Трейт с методом requestContext() для получения IP/UA

	/**
	 * Конструктор райтера.
	 *
	 * @param AuthLogRepository $repository Репозиторий журнала аутентификации
	 * @param ClockInterface    $clock      Интерфейс часов (для получения текущего времени)
	 */
	public function __construct(
		private readonly AuthLogRepository $repository,
		private readonly ClockInterface    $clock,
	) {}

	/**
	 * Записывает событие аутентификации в журнал.
	 *
	 * @param string|null $loginIdentifier Логин/email (только для login_failed — без пароля)
	 * @param string      $action          Тип действия (login/login_failed/otp_sent/otp_verified/password_reset)
	 * @param bool        $success         Успешность операции
	 *
	 * @return void
	 */
	public function record( ?string $loginIdentifier, string $action, bool $success ): void {
		// Получение контекста запроса (IP, User-Agent)
		$ctx = $this->requestContext();

		// Создание DTO и сохранение через репозиторий
		$this->repository->create( new AuthLogInputDTO(
			loginIdentifier: $loginIdentifier,
			action:          $action,
			result:          $success ? 'success' : 'failure',
			actorIp:         $ctx->ip,
			actorUa:         '' !== $ctx->userAgent ? $ctx->userAgent : null,
			createdAt:       $this->clock->now( 'mysql', true ),  // Текущее время в MySQL datetime (UTC)
		) );
	}
}