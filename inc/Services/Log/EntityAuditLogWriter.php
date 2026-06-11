<?php

declare( strict_types=1 );

namespace Inc\Services\Log;

use Inc\Contracts\ClockInterface;
use Inc\DTO\Log\EntityAuditLogInputDTO;
use Inc\Enums\EntityType;
use Inc\Enums\OperationType;
use Inc\Managers\UserManager;
use Inc\Repositories\WPDBRepositories\EntityAuditLogRepository;
use Inc\Shared\Traits\RequestContextProvider;

/**
 * Class EntityAuditLogWriter
 *
 * Сервис для записи изменений сущностей в журнал аудита (entity_audit_log).
 *
 * @package Inc\Services\Log
 *
 * ### Основные обязанности:
 *
 * 1. **Запись изменений сущностей** — логирование создания, обновления и удаления сущностей
 *    (предметы, таксономии, задания, статьи, группы, периоды, пользователи).
 * 2. **Сбор контекста запроса** — получение IP, User-Agent через трейт RequestContextProvider.
 * 3. **Определение роли пользователя** — получение роли через UserManager.
 *
 * ### Архитектурная роль:
 *
 * Делегирует сохранение EntityAuditLogRepository.
 * Используется в EntityAuditSubscriber для записи событий при изменении сущностей.
 *
 * ### Примечания:
 *
 * - operation — тип операции (create, update, delete)
 * - entityType — тип сущности (subject, taxonomy, task, article, group, period, user)
 * - oldLabel — старое название сущности (особенно важно для операций удаления)
 * - Время события получается через ClockInterface (для тестируемости)
 */
class EntityAuditLogWriter {

	use RequestContextProvider;  // Трейт с методом requestContext() для получения IP/UA

	/**
	 * Конструктор райтера.
	 *
	 * @param EntityAuditLogRepository $repository  Репозиторий журнала аудита сущностей
	 * @param UserManager              $userManager Менеджер пользователей
	 * @param ClockInterface           $clock       Интерфейс часов
	 */
	public function __construct(
		private readonly EntityAuditLogRepository $repository,
		private readonly UserManager              $userManager,
		private readonly ClockInterface           $clock,
	) {}

	/**
	 * Записывает изменение сущности в журнал аудита.
	 *
	 * @param int           $actorUserId ID пользователя, выполнившего действие
	 * @param OperationType $operation   Тип операции (create, update, delete)
	 * @param EntityType    $entityType  Тип сущности
	 * @param int|null      $entityId    ID изменённой сущности
	 * @param string|null   $oldLabel    Старое название сущности (для операций удаления)
	 *
	 * @return void
	 */
	public function record(
		int           $actorUserId,
		OperationType $operation,
		EntityType    $entityType,
		?int          $entityId,
		?string       $oldLabel = null
	): void {
		$ctx = $this->requestContext();
		$role = $this->resolveRole( $actorUserId );

		$this->repository->create( new EntityAuditLogInputDTO(
			actorUserId: $actorUserId > 0 ? $actorUserId : null,
			actorRole:   $role,
			operation:   $operation,
			entityType:  $entityType,
			entityId:    $entityId,
			oldLabel:    $oldLabel,
			actorIp:     $ctx->ip,
			createdAt:   $this->clock->now( 'mysql', true ),
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