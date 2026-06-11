<?php

declare( strict_types=1 );

namespace Inc\DTO\Log;

use Inc\Enums\EntityType;
use Inc\Enums\OperationType;

/**
 * Class EntityAuditLogInputDTO
 *
 * Data Transfer Object для вставки записи в журнал аудита изменений сущностей (entity_audit_log).
 *
 * @package Inc\DTO\Log
 *
 * ### Основные обязанности:
 *
 * 1. **Типобезопасная передача данных** — инкапсулирует данные для записи изменения сущности.
 * 2. **Преобразование в массив** — метод toArray() для вставки в БД.
 *
 * ### Архитектурная роль:
 *
 * Используется в EntityAuditLogWriter для передачи данных о событиях
 * изменения сущностей (предметы, таксономии, задания, статьи и т.д.).
 *
 * ### Поля записи:
 *
 * - actorUserId — ID пользователя WordPress, изменившего сущность
 * - actorRole — роль пользователя на момент изменения
 * - operation — тип операции (create, update, delete)
 * - entityType — тип сущности (subject, taxonomy, task, article и т.д.)
 * - entityId — ID изменённой сущности
 * - oldLabel — старое название сущности (для отображения в логе при удалении)
 * - actorIp — IP-адрес пользователя
 * - createdAt — дата и время изменения (MySQL datetime)
 *
 * ### Примечания:
 *
 * - Поле oldLabel используется в основном для операций удаления,
 *   чтобы сохранить название сущности после её удаления.
 */
readonly class EntityAuditLogInputDTO {

	/**
	 * Конструктор DTO.
	 *
	 * @param int|null       $actorUserId ID пользователя WP, изменившего сущность
	 * @param string|null    $actorRole   Роль пользователя
	 * @param OperationType  $operation   Тип операции (create, update, delete)
	 * @param EntityType     $entityType  Тип сущности
	 * @param int|null       $entityId    ID изменённой сущности
	 * @param string|null    $oldLabel    Старое название сущности
	 * @param string         $actorIp     IP-адрес пользователя
	 * @param string         $createdAt   Дата и время изменения (MySQL datetime)
	 */
	public function __construct(
		public ?int          $actorUserId,
		public ?string       $actorRole,
		public OperationType $operation,
		public EntityType    $entityType,
		public ?int          $entityId,
		public ?string       $oldLabel,
		public string        $actorIp,
		public string        $createdAt,
	) {}

	/**
	 * Преобразует DTO в массив для вставки в БД.
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array(
			'actor_user_id' => $this->actorUserId,
			'actor_role'    => $this->actorRole,
			'operation'     => $this->operation->value,
			'entity_type'   => $this->entityType->value,
			'entity_id'     => $this->entityId,
			'old_label'     => $this->oldLabel,
			'actor_ip'      => $this->actorIp,
			'created_at'    => $this->createdAt,
		);
	}
}