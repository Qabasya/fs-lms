<?php

declare( strict_types=1 );

namespace Inc\DTO\Log;

/**
 * Class DeletionLogDTO
 *
 * Data Transfer Object для записи в журнал удалений сущностей (deletion_log).
 *
 * @package Inc\DTO\Log
 *
 * ### Основные обязанности:
 *
 * 1. **Хранение записи удаления сущности** — представляет запись из таблицы deletion_log.
 * 2. **Преобразование массива в DTO** — статический метод fromArray().
 *
 * ### Архитектурная роль:
 *
 * Используется в DeletionLogWriter для передачи данных о событиях
 * физического удаления сущностей (групп, периодов, студентов и т.д.).
 *
 * ### Поля записи:
 *
 * - actorUserId — ID пользователя WordPress, выполнившего удаление
 * - actorRole — роль пользователя на момент удаления
 * - entityType — тип удалённой сущности (group, period, student, parent)
 * - entityId — ID удалённой сущности
 * - cascadedSummary — краткое описание каскадных удалений (JSON)
 * - actorIp — IP-адрес пользователя
 * - createdAt — дата и время удаления
 *
 * ### Примечания:
 *
 * - Лог удалений важен для аудита и соответствия требованиям хранения данных.
 * - Каскадный дайджест (cascadedSummary) содержит информацию о том,
 *   какие связанные записи были удалены вместе с основной сущностью.
 */
readonly class DeletionLogDTO {

	/**
	 * Конструктор DTO.
	 *
	 * @param int         $id               ID записи
	 * @param int         $actorUserId      ID пользователя WP, выполнившего удаление
	 * @param string|null $actorRole        Роль пользователя
	 * @param string      $entityType       Тип удалённой сущности
	 * @param int         $entityId         ID удалённой сущности
	 * @param string|null $cascadedSummary  Описание каскадных удалений (JSON)
	 * @param string      $actorIp          IP-адрес пользователя
	 * @param string      $createdAt        Дата и время удаления
	 */
	public function __construct(
		public int     $id,
		public int     $actorUserId,
		public ?string $actorRole,
		public string  $entityType,
		public int     $entityId,
		public ?string $cascadedSummary,
		public string  $actorIp,
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
			id:               (int) $row['id'],
			actorUserId:      (int) $row['actor_user_id'],
			actorRole:        isset( $row['actor_role'] ) ? (string) $row['actor_role'] : null,
			entityType:       (string) $row['entity_type'],
			entityId:         (int) $row['entity_id'],
			cascadedSummary:  isset( $row['cascaded_summary'] ) ? (string) $row['cascaded_summary'] : null,
			actorIp:          (string) $row['actor_ip'],
			createdAt:        (string) $row['created_at'],
		);
	}
}