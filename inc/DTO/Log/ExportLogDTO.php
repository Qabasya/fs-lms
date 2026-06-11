<?php

declare( strict_types=1 );

namespace Inc\DTO\Log;

/**
 * Class ExportLogDTO
 *
 * Data Transfer Object для записи в журнал экспорта данных (export_log).
 *
 * @package Inc\DTO\Log
 *
 * ### Основные обязанности:
 *
 * 1. **Хранение записи экспорта данных** — представляет запись из таблицы export_log.
 * 2. **Преобразование массива в DTO** — статический метод fromArray().
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
 * - targetIdsJson — JSON-массив ID экспортированных сущностей
 * - createdAt — дата и время экспорта
 *
 * ### Примечания:
 *
 * - Лог экспорта важен для аудита и отслеживания выгрузок персональных данных.
 * - Поле targetIdsJson может быть NULL для массовых экспортов без указания конкретных ID.
 */
readonly class ExportLogDTO {

	/**
	 * Конструктор DTO.
	 *
	 * @param int         $id             ID записи
	 * @param int         $actorUserId    ID пользователя WP, выполнившего экспорт
	 * @param string|null $actorRole      Роль пользователя
	 * @param string      $dataType       Тип экспортируемых данных
	 * @param string      $actionType     Тип действия (single/bulk)
	 * @param string|null $targetIdsJson  JSON-массив ID сущностей
	 * @param string      $createdAt      Дата и время экспорта
	 */
	public function __construct(
		public int     $id,
		public int     $actorUserId,
		public ?string $actorRole,
		public string  $dataType,
		public string  $actionType,
		public ?string $targetIdsJson,
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
			id:            (int) $row['id'],
			actorUserId:   (int) $row['actor_user_id'],
			actorRole:     isset( $row['actor_role'] ) ? (string) $row['actor_role'] : null,
			dataType:      (string) $row['data_type'],
			actionType:    (string) $row['action_type'],
			targetIdsJson: isset( $row['target_ids_json'] ) ? (string) $row['target_ids_json'] : null,
			createdAt:     (string) $row['created_at'],
		);
	}
}