<?php

declare( strict_types=1 );

namespace Inc\Services\Log;

use Inc\Contracts\ClockInterface;
use Inc\DTO\Log\AuthLogInputDTO;
use Inc\DTO\Log\LoginDiagnosticsDTO;
use Inc\Enums\Auth\AuthAction;
use Inc\Enums\Auth\AuthResult;
use Inc\Enums\Auth\LoginFailReason;
use Inc\Repositories\WPDBRepositories\Log\AuthLogRepository;
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

	public function record(
		?string $loginIdentifier,
		AuthAction $action,
		AuthResult $result,
		?LoginDiagnosticsDTO $diagnostics = null
	): void {
		$this->write( $loginIdentifier, $action, $result, $diagnostics?->reason, $diagnostics?->details );
	}

	/**
	 * Событие без разбора входа: форма заявки, пропуск без капчи. Подробности — произвольные
	 * (`visit`, `note`… — см. {@see \Inc\DTO\Log\AuthLogDTO::detailLines()}); пустой массив не пишется.
	 *
	 * @param AuthAction            $action          Что произошло
	 * @param AuthResult            $result          Итог
	 * @param LoginFailReason|null  $reason          Причина (для фильтра журнала)
	 * @param array<string, mixed>  $details         Подробности
	 * @param string|null           $loginIdentifier Логин/email, если он известен
	 */
	public function recordEvent(
		AuthAction $action,
		AuthResult $result,
		?LoginFailReason $reason = null,
		array $details = array(),
		?string $loginIdentifier = null
	): void {
		$this->write( $loginIdentifier, $action, $result, $reason, array() !== $details ? $details : null );
	}

	/**
	 * @param array<string, mixed>|null $details
	 */
	private function write( ?string $loginIdentifier, AuthAction $action, AuthResult $result, ?LoginFailReason $reason, ?array $details ): void {
		$ctx = $this->requestContext();

		$this->repository->create( new AuthLogInputDTO(
			loginIdentifier: $loginIdentifier,
			action:          $action->value,
			result:          $result->value,
			actorIp:         $ctx->ip,
			actorUa:         '' !== $ctx->userAgent ? $ctx->userAgent : null,
			createdAt:       $this->clock->now( 'mysql', true ),
			reason:          $reason?->value,
			details:         $details,
		) );
	}
}