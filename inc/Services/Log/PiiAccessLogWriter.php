<?php

declare( strict_types=1 );

namespace Inc\Services\Log;

use Inc\Contracts\ClockInterface;
use Inc\DTO\Person\PiiAccessLogInputDTO;
use Inc\Managers\Person\UserManager;
use Inc\Repositories\WPDBRepositories\Log\PiiAccessLogRepository;
use Inc\Shared\Traits\RequestContextProvider;

/**
 * Class PiiAccessLogWriter
 *
 * Сервис для записи фактов доступа к персональным данным (PII) в журнал аудита.
 *
 * @package Inc\Services\Log
 *
 * ### Основные обязанности:
 *
 * 1. **Запись доступа к PII** — логирование каждого случая раскрытия персональных данных.
 * 2. **Фиксация причины доступа** — обязательное поле accessReason для compliance.
 * 3. **Сбор контекста запроса** — получение IP, User-Agent через трейт RequestContextProvider.
 * 4. **Определение роли пользователя** — получение роли через UserManager.
 *
 * ### Архитектурная роль:
 *
 * Делегирует сохранение PiiAccessLogRepository.
 * Используется в PiiAccessSubscriber для записи событий при раскрытии PII.
 *
 * ### Примечания:
 *
 * - Журнал доступа к PII является неизменяемым (только append).
 * - Обязательность поля accessReason гарантирует, что каждый доступ к PII
 *   имеет легитимную причину (требование 152-ФЗ и GDPR).
 * - Лог используется для compliance-аудита и проверки обоснованности доступа.
 */
class PiiAccessLogWriter {

	use RequestContextProvider;  // Трейт с методом requestContext() для получения IP/UA

	/**
	 * Конструктор райтера.
	 *
	 * @param PiiAccessLogRepository $repository  Репозиторий журнала доступа к PII
	 * @param UserManager            $userManager Менеджер пользователей
	 * @param ClockInterface         $clock       Интерфейс часов
	 */
	public function __construct(
		private readonly PiiAccessLogRepository $repository,
		private readonly UserManager            $userManager,
		private readonly ClockInterface         $clock,
	) {}

	/**
	 * Записывает факт доступа к персональным данным в журнал.
	 *
	 * @param int    $personId       ID лица (из persons), чьи данные запрошены
	 * @param string $fieldsAccessed Список запрошенных полей (через запятую)
	 * @param string $accessReason   Причина доступа
	 *
	 * @return void
	 */
	public function record( ?int $personId, string $fieldsAccessed, string $accessReason ): void {
		$ctx = $this->requestContext();
		$role = $this->resolveRole( $ctx->actorUserId );

		$this->repository->create( new PiiAccessLogInputDTO(
			actorUserId:    $ctx->actorUserId > 0 ? $ctx->actorUserId : null,
			actorRole:      $role,
			personId:       $personId,
			fieldsAccessed: $fieldsAccessed,
			accessReason:   $accessReason,
			actorIp:        $ctx->ip,
			actorUa:        '' !== $ctx->userAgent ? $ctx->userAgent : null,
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