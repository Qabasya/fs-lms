<?php

declare( strict_types=1 );

namespace Inc\DTO\Log;

/**
 * Class AuditLogDTO
 *
 * Row-DTO строки таблицы fs_lms_audit_log.
 *
 * @package Inc\DTO
 *
 * ### Основные обязанности:
 *
 * 1. **Хранение записи аудита** — представляет запись из таблицы audit_log.
 * 2. **Преобразование массив <-> DTO** — методы fromArray() и toArray().
 *
 * ### Архитектурная роль:
 *
 * Используется в AuditLogRepository для передачи данных о системных событиях.
 * Записи журнала неизменяемы — только append (создание) и чтение.
 *
 * ### Примечания:
 *
 * - actor_user_id — ID пользователя WordPress, выполнившего действие
 * - target_type и target_id — ссылка на целевую сущность (application, enrollment)
 * - details_json — произвольные дополнительные данные в JSON формате
 * - Записи создаются только через create(), update() недоступен
 */
readonly class AuditLogDTO {

	/**
	 * Конструктор DTO.
	 *
	 * @param int         $id          ID записи аудита
	 * @param int|null    $actorUserId ID пользователя WP, совершившего действие
	 * @param string|null $actorRole   Роль пользователя (на момент действия)
	 * @param string      $action      Тип действия (enum AuditAction)
	 * @param string|null $targetType  Тип цели (application, enrollment, person)
	 * @param int|null    $targetId    ID целевой сущности
	 * @param string|null $detailsJson Дополнительные данные в JSON
	 * @param string      $actorIp     IP-адрес пользователя
	 * @param string|null $actorUa     User-Agent браузера
	 * @param string      $createdAt   Дата и время создания записи
	 */
	public function __construct(
		public int     $id,
		public ?int    $actorUserId,
		public ?string $actorRole,
		public string  $action,
		public ?string $targetType,
		public ?int    $targetId,
		public ?string $detailsJson,
		public string  $actorIp,
		public ?string $actorUa,
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
			id:          (int) $row['id'],
			actorUserId: isset( $row['actor_user_id'] ) ? (int) $row['actor_user_id'] : null,
			actorRole:   isset( $row['actor_role'] ) ? (string) $row['actor_role'] : null,
			action:      (string) $row['action'],
			targetType:  isset( $row['target_type'] ) ? (string) $row['target_type'] : null,
			targetId:    isset( $row['target_id'] ) ? (int) $row['target_id'] : null,
			detailsJson: isset( $row['details_json'] ) ? (string) $row['details_json'] : null,
			actorIp:     (string) $row['actor_ip'],
			actorUa:     isset( $row['actor_ua'] ) ? (string) $row['actor_ua'] : null,
			createdAt:   (string) $row['created_at'],
		);
	}

	/**
	 * Преобразует DTO в массив для вставки в БД.
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array(
			'id'           => $this->id,
			'actor_user_id' => $this->actorUserId,
			'actor_role'   => $this->actorRole,
			'action'       => $this->action,
			'target_type'  => $this->targetType,
			'target_id'    => $this->targetId,
			'details_json' => $this->detailsJson,
			'actor_ip'     => $this->actorIp,
			'actor_ua'     => $this->actorUa,
			'created_at'   => $this->createdAt,
		);
	}
}