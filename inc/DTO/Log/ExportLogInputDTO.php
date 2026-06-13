<?php

declare( strict_types=1 );

namespace Inc\DTO\Log;

/**
 * Class ExportLogInputDTO
 *
 * Data Transfer Object для вставки записи в журнал экспорта данных (export_log).
 *
 * @package Inc\DTO\Log
 *
 * ### Основные обязанности:
 *
 * 1. **Типобезопасная передача данных** — инкапсулирует данные для записи экспорта данных.
 * 2. **Преобразование в массив** — метод toArray() для вставки в БД.
 *
 * ### Архитектурная роль:
 *
 * Используется в ExportLogWriter для передачи данных о событиях
 * экспорта данных (группы, студенты, родители, архив, различные логи).
 *
 * ### Поля записи:
 *
 * - actorUserId — ID пользователя WordPress, выполнившего экспорт
 * - actorRole — роль пользователя на момент экспорта
 * - dataType — тип экспортируемых данных (groups, students, parents, archive, log_audit и т.д.)
 * - actionType — тип действия (single, bulk)
 * - targetIdsJson — JSON-массив ID экспортированных сущностей (может быть NULL)
 * - createdAt — дата и время экспорта (MySQL datetime)
 *
 * ### Примечания:
 *
 * - Лог экспорта важен для аудита и отслеживания выгрузок персональных данных.
 * - Поле targetIdsJson может быть NULL для массовых экспортов без указания конкретных ID.
 */
readonly class ExportLogInputDTO {

	/**
	 * Конструктор DTO.
	 *
	 * @param int         $actorUserId    ID пользователя WP, выполнившего экспорт
	 * @param string|null $actorRole      Роль пользователя
	 * @param string      $dataType       Тип экспортируемых данных
	 * @param string      $actionType     Тип действия (single/bulk)
	 * @param string|null $targetIdsJson  JSON-массив ID сущностей (опционально)
	 * @param string      $createdAt      Дата и время экспорта (MySQL datetime)
	 */
	public function __construct(
		public int     $actorUserId,
		public ?string $actorRole,
		public string  $operationType,
		public string  $dataType,
		public string  $actionType,
		public ?string $targetIdsJson,
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
			'actor_user_id'   => $this->actorUserId,
			'actor_role'      => $this->actorRole,
			'operation_type'  => $this->operationType,
			'data_type'       => $this->dataType,
			'action_type'     => $this->actionType,
			'target_ids_json' => $this->targetIdsJson,
			'actor_ip'        => $this->actorIp,
			'actor_ua'        => $this->actorUa,
			'created_at'      => $this->createdAt,
		);
	}
}