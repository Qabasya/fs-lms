<?php

declare( strict_types=1 );

namespace Inc\Services\Log;

use Inc\Contracts\ClockInterface;
use Inc\DTO\Log\ExportLogInputDTO;
use Inc\Managers\Person\UserManager;
use Inc\Repositories\WPDBRepositories\Log\ExportLogRepository;
use Inc\Shared\Traits\RequestContextProvider;

/**
 * Class ExportLogWriter
 *
 * Сервис для записи экспорта данных в журнал аудита.
 *
 * @package Inc\Services\Log
 *
 * ### Основные обязанности:
 *
 * 1. **Запись экспорта данных** — логирование экспорта групп, студентов, родителей, архива, логов.
 * 2. **Фиксация типа экспорта** — сохранение информации о типе данных и режиме (single/bulk).
 * 3. **Сбор контекста запроса** — получение IP, User-Agent через трейт RequestContextProvider.
 * 4. **Определение роли пользователя** — через ActorRoleResolver.
 *
 * ### Архитектурная роль:
 *
 * Делегирует сохранение ExportLogRepository.
 * Используется в ExportService для записи событий при экспорте данных.
 *
 * ### Примечания:
 *
 * - dataType — тип экспортируемых данных (groups, students, parents, archive, log_audit и т.д.)
 * - actionType — тип действия (single — единичный экспорт, bulk — массовый)
 * - targetIds — массив ID экспортированных сущностей (для единичных экспортов)
 * - Лог экспорта важен для аудита и отслеживания выгрузок персональных данных.
 */
class ExportLogWriter {

	use RequestContextProvider;  // Трейт с методом requestContext() для получения IP/UA

	/**
	 * Конструктор райтера.
	 *
	 * @param ExportLogRepository $repository  Репозиторий журнала экспорта
	 * @param ActorRoleResolver $roleResolver Роль автора действия для журнала
	 * @param ClockInterface      $clock       Интерфейс часов
	 */
	public function __construct(
		private readonly ExportLogRepository $repository,
		private readonly ActorRoleResolver $roleResolver,
		private readonly ClockInterface      $clock,
	) {}

	/**
	 * Записывает экспорт данных в журнал.
	 *
	 * @param string             $dataType      Тип экспортируемых данных (groups, students, parents, archive, log_*)
	 * @param string             $actionType    Тип действия (single — единичный, bulk — массовый)
	 * @param int[]              $targetIds     Массив ID экспортированных сущностей
	 * @param string             $operationType export | import
	 * @param array<string, int> $details       Объём операции ([раздел => количество]). Пишется вместо
	 *                                          списка ID там, где перечислять цели поштучно бессмысленно:
	 *                                          у переноса предмета «цель» — это сотни записей семи типов,
	 *                                          и в аудите полезен их состав, а не портянка ID.
	 *
	 * @return void
	 */
	public function record(
		string $dataType,
		string $actionType,
		array $targetIds = array(),
		string $operationType = 'export',
		array $details = array()
	): void {
		$ctx = $this->requestContext();
		$role = $this->roleResolver->resolve( $ctx->actorUserId );

		$payload = array() !== $details
			? wp_json_encode( array_filter( $details, static fn( $count ): bool => $count > 0 ) )
			: ( ! empty( $targetIds ) ? wp_json_encode( $targetIds ) : null );

		$this->repository->create( new ExportLogInputDTO(
			actorUserId:   $ctx->actorUserId > 0 ? $ctx->actorUserId : 0,
			actorRole:     $role,
			operationType: $operationType,
			dataType:      $dataType,
			actionType:    $actionType,
			targetIdsJson: false !== $payload ? $payload : null,
			actorIp:       $ctx->ip,
			actorUa:       '' !== $ctx->userAgent ? $ctx->userAgent : null,
			createdAt:     $this->clock->now( 'mysql', true ),
		) );
	}

}