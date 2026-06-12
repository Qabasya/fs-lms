<?php

declare( strict_types=1 );

namespace Inc\Services\Log;

use Inc\Contracts\ClockInterface;
use Inc\DTO\Log\EmailLogInputDTO;
use Inc\Managers\UserManager;
use Inc\Repositories\WPDBRepositories\Log\EmailLogRepository;
use Inc\Shared\Traits\RequestContextProvider;

/**
 * Class EmailLogWriter
 *
 * Сервис для записи отправки email в журнал аудита.
 *
 * @package Inc\Services\Log
 *
 * ### Основные обязанности:
 *
 * 1. **Запись отправки email** — логирование отправки писем (OTP, уведомления, сброс пароля).
 * 2. **Фиксация статуса** — сохранение информации об успешной или неудачной отправке.
 * 3. **Сбор контекста запроса** — получение IP, User-Agent через трейт RequestContextProvider.
 * 4. **Определение роли пользователя** — получение роли через UserManager.
 *
 * ### Архитектурная роль:
 *
 * Делегирует сохранение EmailLogRepository.
 * Используется в EmailSubscriber для записи событий при отправке email.
 *
 * ### Примечания:
 *
 * - Лог отправки email важен для аудита и отладки проблем с доставкой уведомлений.
 * - Поле targetPersonId ссылается на ID лица (из persons), которому адресовано письмо.
 * - При неудачной отправке сохраняется сообщение об ошибке из wp_mail().
 */
class EmailLogWriter {

	use RequestContextProvider;  // Трейт с методом requestContext() для получения IP/UA

	/**
	 * Конструктор райтера.
	 *
	 * @param EmailLogRepository $repository  Репозиторий журнала отправки email
	 * @param UserManager        $userManager Менеджер пользователей
	 * @param ClockInterface     $clock       Интерфейс часов
	 */
	public function __construct(
		private readonly EmailLogRepository $repository,
		private readonly UserManager        $userManager,
		private readonly ClockInterface     $clock,
	) {}

	/**
	 * Записывает отправку email в журнал.
	 *
	 * @param string   $emailType      Тип письма (значение из EmailTemplateType)
	 * @param int|null $targetPersonId ID лица (из persons), которому адресовано письмо
	 * @param bool     $success        Результат отправки (true/false)
	 * @param string   $errorMessage   Текст ошибки при неудачной отправке
	 *
	 * @return void
	 */
	public function record( string $emailType, ?int $targetPersonId, ?string $recipientEmail, bool $success, string $errorMessage = '' ): void {
		$ctx = $this->requestContext();
		$role = $this->resolveRole( $ctx->actorUserId );

		$this->repository->create( new EmailLogInputDTO(
			actorUserId:    $ctx->actorUserId > 0 ? $ctx->actorUserId : null,
			actorRole:      $role,
			emailType:      $emailType,
			targetPersonId: $targetPersonId,
			recipientEmail: '' !== (string) $recipientEmail ? $recipientEmail : null,
			status:         $success ? 'success' : 'failed',
			errorMessage:   '' !== $errorMessage ? $errorMessage : null,
			createdAt:      $this->clock->now( 'mysql', true ),
		) );
	}

	/**
	 * Определяет роль пользователя по ID.
	 *
	 * @param int $userId ID пользователя WordPress
	 *
	 * @return string|null
	 */
	private function resolveRole( int $userId ): ?string {
		if ( $userId <= 0 ) {
			return null;
		}
		$user = $this->userManager->find( $userId );
		if ( null === $user || empty( $user->roles ) ) {
			return null;
		}
		// reset() — возвращает первый элемент массива (первую роль пользователя)
		return (string) reset( $user->roles );
	}
}