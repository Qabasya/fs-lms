<?php

declare( strict_types=1 );

namespace Inc\Services\Log;

use Inc\Contracts\ClockInterface;
use Inc\DTO\Log\DeletionLogInputDTO;
use Inc\Managers\UserManager;
use Inc\Repositories\WPDBRepositories\DeletionLogRepository;
use Inc\Shared\Traits\RequestContextProvider;

/**
 * Class DeletionLogWriter
 *
 * Сервис для записи удалений сущностей в журнал аудита.
 *
 * @package Inc\Services\Log
 *
 * ### Основные обязанности:
 *
 * 1. **Запись удалений сущностей** — логирование физического удаления групп, периодов, студентов, родителей.
 * 2. **Фиксация каскадных удалений** — сохранение информации о связанных удалённых записях.
 * 3. **Сбор контекста запроса** — получение IP, User-Agent через трейт RequestContextProvider.
 * 4. **Определение роли пользователя** — получение роли через UserManager.
 *
 * ### Архитектурная роль:
 *
 * Делегирует сохранение DeletionLogRepository.
 * Используется в DeletionSubscriber для записи событий при физическом удалении сущностей.
 *
 * ### Примечания:
 *
 * - Лог удалений важен для аудита и соответствия требованиям хранения данных.
 * - Каскадный дайджест (cascadedSummary) содержит информацию о том,
 *   какие связанные записи были удалены вместе с основной сущностью (без PII).
 */
class DeletionLogWriter {

	use RequestContextProvider;  // Трейт с методом requestContext() для получения IP/UA

	/**
	 * Конструктор райтера.
	 *
	 * @param DeletionLogRepository $repository  Репозиторий журнала удалений
	 * @param UserManager           $userManager Менеджер пользователей
	 * @param ClockInterface        $clock       Интерфейс часов
	 */
	public function __construct(
		private readonly DeletionLogRepository $repository,
		private readonly UserManager           $userManager,
		private readonly ClockInterface        $clock,
	) {}

	/**
	 * Записывает удаление сущности в журнал.
	 *
	 * @param string      $entityType      Тип удалённой сущности (person/group/subject/period)
	 * @param int         $entityId        ID удалённой сущности
	 * @param string|null $cascadedSummary Сводка каскадных удалений (без PII)
	 *
	 * @return void
	 */
	public function record( string $entityType, int $entityId, ?string $cascadedSummary = null ): void {
		$ctx = $this->requestContext();
		$role = $this->resolveRole( $ctx->actorUserId );

		$this->repository->create( new DeletionLogInputDTO(
			actorUserId:     $ctx->actorUserId > 0 ? $ctx->actorUserId : 0,
			actorRole:       $role,
			entityType:      $entityType,
			entityId:        $entityId,
			cascadedSummary: $cascadedSummary,
			actorIp:         $ctx->ip,
			createdAt:       $this->clock->now( 'mysql', true ),
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