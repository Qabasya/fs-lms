<?php

declare( strict_types=1 );

namespace Inc\DTO\Log;

/**
 * Class DeletionLogInputDTO
 *
 * Data Transfer Object для вставки записи в журнал удалений сущностей (deletion_log).
 *
 * @package Inc\DTO\Log
 *
 * ### Основные обязанности:
 *
 * 1. **Типобезопасная передача данных** — инкапсулирует данные для записи удаления сущности.
 * 2. **Преобразование в массив** — метод toArray() для вставки в БД.
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
readonly class DeletionLogInputDTO {

	/**
	 * Конструктор DTO.
	 *
	 * @param int         $actorUserId      ID пользователя WP, выполнившего удаление
	 * @param string|null $actorRole        Роль пользователя
	 * @param string      $entityType       Тип удалённой сущности
	 * @param int         $entityId         ID удалённой сущности
	 * @param string|null $cascadedSummary  Описание каскадных удалений (JSON)
	 * @param string      $actorIp          IP-адрес пользователя
	 * @param string      $createdAt        Дата и время удаления (MySQL datetime)
	 */
	public function __construct(
		public int     $actorUserId,
		public ?string $actorRole,
		public string  $entityType,
		public int     $entityId,
		public ?string $cascadedSummary,
		public string  $actorIp,
		public string  $createdAt,
	) {}

	/**
	 * Преобразует DTO в массив для вставки в БД.
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array(
			'actor_user_id'    => $this->actorUserId,
			'actor_role'       => $this->actorRole,
			'entity_type'      => $this->entityType,
			'entity_id'        => $this->entityId,
			'cascaded_summary' => $this->cascadedSummary,
			'actor_ip'         => $this->actorIp,
			'created_at'       => $this->createdAt,
		);
	}
}