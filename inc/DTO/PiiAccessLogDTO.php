<?php

declare( strict_types=1 );

namespace Inc\DTO;

/**
 * Class PiiAccessLogDTO
 *
 * Row-DTO строки таблицы fs_lms_pii_access_log.
 *
 * @package Inc\DTO
 *
 * ### Основные обязанности:
 *
 * 1. **Хранение записи доступа к PII** — представляет запись из таблицы pii_access_log.
 * 2. **Преобразование массив <-> DTO** — методы fromArray() и toArray().
 *
 * ### Архитектурная роль:
 *
 * Используется в PiiAccessLogRepository для передачи данных о каждом случае
 * доступа к персональным данным (PII) сотрудниками.
 *
 * ### Примечания:
 *
 * - Записи журнала неизменяемы — только append (создание) и чтение.
 * - update() запрещён для обеспечения compliance с 152-ФЗ.
 * - fields_accessed — список полей, к которым был осуществлён доступ
 *   (full_name, pass, inn, snils, address, phone).
 * - access_reason — обязательное поле, указывает цель доступа
 */
readonly class PiiAccessLogDTO {

	/**
	 * Конструктор DTO.
	 *
	 * @param int         $id              ID записи
	 * @param int         $actorUserId     ID пользователя WP, запросившего доступ
	 * @param string|null $actorRole       Роль пользователя на момент доступа
	 * @param int         $personId        ID человека (из persons), чьи данные запрошены
	 * @param string      $fieldsAccessed  Список запрошенных полей (через запятую)
	 * @param string      $accessReason    Причина доступа (обязательное поле)
	 * @param string      $actorIp         IP-адрес пользователя
	 * @param string      $createdAt       Дата и время доступа
	 */
	public function __construct(
		public int     $id,
		public int     $actorUserId,
		public ?string $actorRole,
		public int     $personId,
		public string  $fieldsAccessed,
		public string  $accessReason,
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
			id:             (int) $row['id'],
			actorUserId:    (int) $row['actor_user_id'],
			actorRole:      isset( $row['actor_role'] ) ? (string) $row['actor_role'] : null,
			personId:       (int) $row['person_id'],
			fieldsAccessed: (string) $row['fields_accessed'],
			accessReason:   (string) $row['access_reason'],
			actorIp:        (string) $row['actor_ip'],
			createdAt:      (string) $row['created_at'],
		);
	}

	/**
	 * Преобразует DTO в массив для вставки в БД.
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array(
			'id'              => $this->id,
			'actor_user_id'   => $this->actorUserId,
			'actor_role'      => $this->actorRole,
			'person_id'       => $this->personId,
			'fields_accessed' => $this->fieldsAccessed,
			'access_reason'   => $this->accessReason,
			'actor_ip'        => $this->actorIp,
			'created_at'      => $this->createdAt,
		);
	}
}